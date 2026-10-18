<?php
/**
 * The authorization endpoint and consent screen.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector\OAuth;

use Specflux\SiteAgent\Admin\Ui\Kit;
use Specflux\SiteAgent\Connector\Connector;
use Specflux\SiteAgent\Run\Clock;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * `GET|POST /wp-admin/admin-post.php?action=specflux_site_agent_oauth_authorize`.
 *
 * Why admin-post and not REST: a REST request only sees the browser's WordPress
 * login cookie when it carries a REST nonce, which an OAuth redirect cannot. admin-post
 * is a normal cookie-authenticated request and has a `nopriv` twin for visitors who
 * are not logged in yet.
 *
 * Order matters and is the open-redirect guard (MCP auth "Open Redirection",
 * RFC 6749 §4.1.2.1): nothing redirects to the client's redirect_uri until the
 * client is known AND the redirect_uri is an exact registered string. Every
 * earlier failure is shown on this page.
 */
final class Authorize {

	private const CHALLENGE_PATTERN = '/^[A-Za-z0-9_-]{43}$/';
	private const MAX_STATE_LENGTH  = 512;

	public static function register(): void {
		add_action( 'admin_post_' . OAuth::AUTHORIZE_ACTION, array( self::class, 'handleRequest' ) );
		add_action( 'admin_post_nopriv_' . OAuth::AUTHORIZE_ACTION, array( self::class, 'handleRequest' ) );
	}

	/** admin-post entry: run the flow and send the result. */
	public static function handleRequest(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- the consent POST verifies its nonce inside run(); GET only renders.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$input  = 'POST' === $method ? $_POST : $_GET; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		self::emit( self::run( $method, (array) wp_unslash( $input ) ) );
	}

	/**
	 * The whole flow, as data.
	 *
	 * @param string              $method GET or POST.
	 * @param array<string,mixed> $in     Unslashed request parameters.
	 */
	public static function run( string $method, array $in ): Page {
		if ( ! OAuth::serving() ) {
			return self::error( __( 'This site is not accepting app connections.', 'specflux-site-agent' ), 404 );
		}
		if ( ! is_user_logged_in() ) {
			// Not a redirect to the client: this goes to WordPress's own login and comes straight back.
			return 'POST' === $method
				? self::error( __( 'Your session expired. Start the connection again from the app.', 'specflux-site-agent' ), 403 )
				: new Page( 302, '', wp_login_url( self::returnUrl( $in ) ) );
		}
		if ( ! current_user_can( Connector::capability() ) ) {
			return self::error( __( 'Your account is not allowed to connect apps to this site. Ask a site administrator.', 'specflux-site-agent' ), 403 );
		}

		// 1. Who is asking, and where may we send the browser? Failures here never redirect.
		$client = Clients::resolve( self::str( $in, 'client_id' ) );
		if ( null === $client ) {
			return self::error( __( 'This app is not recognised. Start the connection again from the app.', 'specflux-site-agent' ), 400 );
		}
		$redirect = self::str( $in, 'redirect_uri' );
		if ( '' === $redirect || ! $client->allowsRedirect( $redirect ) ) {
			return self::error( __( 'The app asked to return to an address it has not registered, so the request was stopped.', 'specflux-site-agent' ), 400 );
		}

		// 2. The redirect target is now trusted: further errors go back to the client (RFC 6749 §4.1.2.1).
		$state = self::str( $in, 'state' );
		if ( strlen( $state ) > self::MAX_STATE_LENGTH ) {
			return self::fail( $redirect, 'invalid_request', '' );
		}
		if ( 'code' !== self::str( $in, 'response_type' ) ) {
			return self::fail( $redirect, 'unsupported_response_type', $state );
		}
		// PKCE is mandatory and S256 only (MCP auth "Authorization Code Protection"); `plain` is refused.
		$challenge = self::str( $in, 'code_challenge' );
		if ( 'S256' !== self::str( $in, 'code_challenge_method' ) || 1 !== preg_match( self::CHALLENGE_PATTERN, $challenge ) ) {
			return self::fail( $redirect, 'invalid_request', $state );
		}
		// RFC 8707: the resource, when given, must be exactly this server. Absent is allowed
		// because the audience is always this server regardless (one resource server per authorization server).
		if ( array_key_exists( 'resource', $in ) && ( ! is_string( $in['resource'] ) || OAuth::serverUrl() !== $in['resource'] ) ) {
			return self::fail( $redirect, 'invalid_target', $state );
		}

		$requested = OAuth::knownScopes( self::str( $in, 'scope' ) );
		if ( '' !== trim( self::str( $in, 'scope' ) ) && array() === $requested ) {
			return self::fail( $redirect, 'invalid_scope', $state );
		}

		$nonce_action = self::nonceAction( $client->id, $challenge );
		if ( 'POST' !== $method ) {
			return self::consent( $client, $redirect, $in, $nonce_action );
		}

		// 3. The decision. Without a valid nonce this is a forged cross-site POST: refuse, do not redirect.
		$nonce = self::str( $in, '_wpnonce' );
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, $nonce_action ) ) {
			return self::error( __( 'This request could not be verified. Start the connection again from the app.', 'specflux-site-agent' ), 403 );
		}
		if ( 'approve' !== self::str( $in, 'decision' ) ) {
			return self::fail( $redirect, 'access_denied', $state );
		}

		// The person approving decides, not the app: Claude.ai asks for read only even when write is
		// advertised (RFC 6749 §3.3 lets the server grant a different scope; the token response says which).
		$scope = 'write' === self::str( $in, 'grant' ) ? OAuth::SCOPE_READ . ' ' . OAuth::SCOPE_WRITE : OAuth::SCOPE_READ;
		$code  = OAuth::secret();
		Store::putCode(
			$code,
			array(
				'client_id'    => $client->id,
				'redirect_uri' => $redirect,
				'user'         => (int) get_current_user_id(),
				'scope'        => $scope,
				'resource'     => OAuth::serverUrl(),
				'challenge'    => $challenge,
				'exp'          => Clock::now() + OAuth::CODE_TTL,
			)
		);

		return new Page( 302, '', self::withQuery( $redirect, array( 'code' => $code ) + ( '' !== $state ? array( 'state' => $state ) : array() ) ), array( 'Cache-Control' => 'no-store' ) );
	}

	/** The nonce is bound to the client and the PKCE challenge, so it cannot be replayed for another request. */
	private static function nonceAction( string $client_id, string $challenge ): string {
		return 'specflux_site_agent_oauth_consent_' . substr( OAuth::hash( $client_id . '|' . $challenge ), 0, 24 );
	}

	/**
	 * @param array<string,mixed> $in  Parameters.
	 * @param string              $key Key.
	 */
	private static function str( array $in, string $key ): string {
		return isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? $in[ $key ] : '';
	}

	/**
	 * Where login should return to: this same request. Carries no secrets (the code does not exist yet).
	 *
	 * @param array<string,mixed> $in Parameters.
	 */
	private static function returnUrl( array $in ): string {
		$params = array();
		foreach ( $in as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) && 'action' !== $key ) {
				$params[ $key ] = $value;
			}
		}

		return self::withQuery( OAuth::authorizeUrl(), $params );
	}

	/**
	 * @param string               $url    URL, possibly already with a query.
	 * @param array<string,string> $params Parameters to append.
	 */
	private static function withQuery( string $url, array $params ): string {
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/** An error returned to the (already validated) redirect_uri. */
	private static function fail( string $redirect, string $error, string $state ): Page {
		$params = array( 'error' => $error );
		if ( '' !== $state ) {
			$params['state'] = $state;
		}

		return new Page( 302, '', self::withQuery( $redirect, $params ), array( 'Cache-Control' => 'no-store' ) );
	}

	/** An error shown on this page. Never a redirect. */
	private static function error( string $message, int $status ): Page {
		return new Page(
			$status,
			self::document( __( 'Cannot connect', 'specflux-site-agent' ), '<h1>' . esc_html__( 'Cannot connect this app', 'specflux-site-agent' ) . '</h1><p>' . esc_html( $message ) . '</p>' ),
			null,
			self::securityHeaders()
		);
	}

	/**
	 * The consent screen.
	 *
	 * @param Client              $client       Client.
	 * @param string              $redirect     Validated redirect URI.
	 * @param array<string,mixed> $in           Parameters, echoed back as hidden fields.
	 * @param string              $nonce_action Nonce action.
	 */
	private static function consent( Client $client, string $redirect, array $in, string $nonce_action ): Page {
		$host   = RedirectUri::displayHost( $redirect );
		$hidden = '';
		foreach ( array( 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'resource', 'scope' ) as $key ) {
			if ( isset( $in[ $key ] ) && is_string( $in[ $key ] ) ) {
				$hidden .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $in[ $key ] ) . '">';
			}
		}
		$hidden .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $nonce_action ) ) . '">';

		$notes = '';
		if ( RedirectUri::isLoopback( $redirect ) ) {
			$notes .= '<div class="aui-notice aui-notice--warning warn" role="alert">' . Kit::icon( 'warning' ) . '<div>' . esc_html__( 'This app runs on the computer you are using right now. Only continue if you just started this connection yourself.', 'specflux-site-agent' ) . '</div></div>';
		}
		$write = '<label class="consent-choice"><input type="radio" name="grant" value="write"> <span><strong>' . esc_html__( 'Read and write', 'specflux-site-agent' ) . '</strong> ' . esc_html__( 'It can also ask to change things. Changes still follow this site\'s approval rules.', 'specflux-site-agent' ) . '</span></label>';

		// A dynamically registered client picks its own name, so the name proves nothing: say so, and
		// make the redirect host (which the client cannot choose freely) the dominant line.
		$identity = $client->document
			? '<p class="meta"><span class="aui-badge aui-badge--ok">' . Kit::icon( 'shield' ) . esc_html( sprintf( /* translators: %s: host name of the app's verified client metadata document. */ __( 'Verified by %s', 'specflux-site-agent' ), (string) wp_parse_url( $client->id, PHP_URL_HOST ) ) ) . '</span></p>'
			: '<p class="meta unverified"><span class="aui-badge aui-badge--warn">' . Kit::icon( 'warning' ) . esc_html__( 'Not verified. This name was chosen by the app itself.', 'specflux-site-agent' ) . '</span></p>';

		$body = '<h1>' . esc_html( sprintf( /* translators: %s: name of the app asking to connect. */ __( 'Connect %s?', 'specflux-site-agent' ), $client->name ) ) . '</h1>'
			. $identity
			. '<div class="host">' . esc_html__( 'After you approve, you\'ll be sent to', 'specflux-site-agent' ) . '<br><strong>' . esc_html( $host ) . '</strong></div>'
			. '<p class="lede">' . esc_html__( 'This app is asking to use Specflux Site Agent on this site.', 'specflux-site-agent' ) . '</p>'
			. $notes
			. '<form method="post" action="' . esc_url( OAuth::authorizeUrl() ) . '">'
			. $hidden
			. '<fieldset><legend>' . esc_html__( 'What may it do?', 'specflux-site-agent' ) . '</legend>'
			. '<label class="consent-choice"><input type="radio" name="grant" value="read" checked> <span><strong>' . esc_html__( 'Read only', 'specflux-site-agent' ) . '</strong> ' . esc_html__( 'It can look at pages, posts, media and settings.', 'specflux-site-agent' ) . '</span></label>'
			. $write
			. '</fieldset>'
			. '<div class="consent-actions"><button type="submit" name="decision" value="approve" class="aui-btn aui-btn--primary aui-btn--lg">' . esc_html__( 'Approve', 'specflux-site-agent' ) . '</button> '
			. '<button type="submit" name="decision" value="deny" class="aui-btn aui-btn--secondary aui-btn--lg">' . esc_html__( 'Deny', 'specflux-site-agent' ) . '</button></div>'
			. '</form>';

		return new Page( 200, self::document( __( 'Connect an app', 'specflux-site-agent' ), $body ), null, self::securityHeaders() );
	}

	/**
	 * Anti-clickjacking (X-Frame-Options and CSP frame-ancestors), no caching, no referrer.
	 *
	 * @return array<string,string>
	 */
	private static function securityHeaders(): array {
		return array(
			'X-Frame-Options'         => 'DENY',
			'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'",
			'Cache-Control'           => 'no-store, max-age=0',
			'Referrer-Policy'         => 'no-referrer',
			'X-Content-Type-Options'  => 'nosniff',
			'Content-Type'            => 'text/html; charset=utf-8',
		);
	}

	private static function document( string $title, string $body ): string {
		// The kit is inlined (the page's CSP allows inline styles and nothing else) and the standalone
		// layout is a few rules on top of it: no wp-admin chrome, one centred card.
		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html( $title ) . '</title><style>' . Kit::inlineCss() . self::standaloneCss() . '</style></head><body>'
			. Kit::sprite()
			. '<div class="aui consent" data-brand="specflux"><main class="aui-card consent-card">'
			. '<div class="consent-brand"><span class="aui-mark">' . Kit::icon( 'gear', 'aui-i--lg' ) . '</span><span>' . esc_html__( 'Site Agent', 'specflux-site-agent' ) . '</span></div>'
			. $body
			. '</main></div></body></html>';
	}

	/** The layout rules for the standalone consent page, on top of the kit. */
	private static function standaloneCss(): string {
		return 'html,body{margin:0;background:#F6F7F9}'
			. '.aui.consent{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;font-size:14px;line-height:22px}'
			. '.consent-card{width:100%;max-width:480px;padding:32px;display:flex;flex-direction:column;gap:16px}'
			. '.consent-brand{display:flex;align-items:center;gap:12px;font-weight:600;color:var(--aui-text-2)}'
			. '.aui.consent h1{font-size:22px;line-height:28px}'
			. '.consent .meta{margin:-8px 0 0}'
			. '.consent .host{padding:12px 16px;border:1px solid var(--aui-border-strong);border-radius:var(--aui-r-lg);background:var(--aui-inset);color:var(--aui-text-2)}'
			. '.consent .host strong{display:block;margin-top:4px;font-family:var(--aui-mono);font-size:20px;line-height:28px;color:var(--aui-text);word-break:break-all}'
			. '.consent .lede{color:var(--aui-text-2)}'
			. '.consent fieldset{margin:0;padding:0;border:0;display:flex;flex-direction:column;gap:8px}'
			. '.consent legend{padding:0;margin-bottom:8px;font-weight:600}'
			. '.consent-choice{display:flex;align-items:flex-start;gap:12px;padding:12px 16px;border:1px solid var(--aui-border-strong);border-radius:var(--aui-r-lg);background:var(--aui-surface);cursor:pointer}'
			. '.consent-choice:has(input:checked){border-color:var(--aui-accent);background:var(--aui-accent-tint)}'
			. '.consent-choice input{margin-top:3px}'
			. '.consent-choice strong{display:block}'
			. '.consent-actions{display:flex;gap:12px;margin-top:16px}'
			. '.consent-actions .aui-btn{flex:1 1 0}'
			. '@media (max-width:480px){.consent-card{padding:20px}.aui.consent{padding:12px}}';
	}

	/** Send a {@see Page}. */
	public static function emit( Page $page ): void {
		foreach ( $page->headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( null !== $page->location ) {
			wp_redirect( $page->location, $page->status ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- target is an exact-match registered redirect URI, or WordPress's own login URL.
			exit;
		}
		status_header( $page->status );
		echo $page->body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in this class.
		exit;
	}
}
