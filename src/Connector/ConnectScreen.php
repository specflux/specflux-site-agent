<?php
/**
 * Connections for the AI apps tab (K3): creation, the one-time config view,
 * revoke and re-scope, and the app cards and tables the tab prints.
 *
 * The page itself (header, connector switch, signed-in apps, waiting calls, the
 * right rail) is {@see AppsScreen}; this class keeps the connection logic and
 * handlers exactly as they were.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector;

use Specflux\SiteAgent\Admin\Ui\Kit;
use Specflux\SiteAgent\Admin\Ui\Shell;
use Specflux\SiteAgent\Connector\OAuth\Clients;
use Specflux\SiteAgent\Connector\OAuth\OAuth;
use Specflux\SiteAgent\Connector\OAuth\Store;
use Specflux\SiteAgent\MultisiteGuard;
use WP_Application_Passwords;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Creates connections (a core application password plus a registry entry),
 * shows the password once with the exact config for the chosen app, lists the
 * current user's connections, and revokes or re-scopes them. The plain
 * password exists only in the request that creates it: it is rendered into
 * that one response and written nowhere.
 */
final class ConnectScreen {

	/** The old submenu slug; it redirects to the AI apps tab ({@see Shell::redirectLegacy()}). */
	public const SLUG = Shell::LEGACY_CONNECT;

	/** Nonce action for the create / test forms. */
	public const NONCE_FORM = 'specflux_site_agent_connect_form';

	/** Nonce action prefix for the per-connection buttons; the UUID is appended. */
	public const NONCE_CONNECTION = 'specflux_site_agent_connection_';

	/** Hook the two admin-post handlers. */
	public static function register(): void {
		add_action( 'admin_post_specflux_site_agent_connection_scope', array( self::class, 'handleScope' ) );
		add_action( 'admin_post_specflux_site_agent_connection_revoke', array( self::class, 'handleRevoke' ) );
	}

	/** This screen's URL: the AI apps tab. */
	public static function url(): string {
		return Shell::url( 'apps' );
	}

	/** The connector's server URL. */
	public static function serverUrl(): string {
		return rest_url( Connector::ROUTE_NAMESPACE . '/' . Connector::ROUTE );
	}

	/**
	 * Why sign-in (OAuth) cannot work on this site, or null when it can.
	 *
	 * The bearer guard only recognises the front controller index.php in
	 * ABSPATH (OAuth\Bearer::entryIsFrontController()), and discovery needs
	 * pretty permalinks.
	 */
	public static function oauthBlocker(): ?string {
		if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
			return __( 'Sign-in is not available on this site: it uses plain permalinks. Choose any other structure under Settings > Permalinks, then come back.', 'specflux-site-agent' );
		}

		if ( ! OAuth::availableOnThisSite() ) {
			return __( 'Sign-in is not available on this site: it is a subsite of a subdirectory multisite network, and sign-in needs the domain root. Use an application-password connection above instead.', 'specflux-site-agent' );
		}

		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$site = untrailingslashit( (string) wp_parse_url( site_url(), PHP_URL_PATH ) );
		if ( $home !== $site ) {
			return __( 'Sign-in is not available on this site: WordPress is installed in its own directory, so the sign-in check never sees requests. Use an application-password connection above instead.', 'specflux-site-agent' );
		}

		return null;
	}

	/** @return array<string,string> client id => label, for the app-password cards. */
	public static function clientLabels(): array {
		return array(
			ClientConfig::CLAUDE_CODE    => __( 'Claude Code', 'specflux-site-agent' ),
			ClientConfig::CURSOR         => __( 'Cursor', 'specflux-site-agent' ),
			ClientConfig::VS_CODE        => __( 'VS Code', 'specflux-site-agent' ),
			ClientConfig::CLAUDE_DESKTOP => __( 'Claude Desktop (local proxy)', 'specflux-site-agent' ),
			ClientConfig::OTHER          => __( 'Other (copy the URL)', 'specflux-site-agent' ),
		);
	}

	/**
	 * Why the screen is not usable, as a sentence with the fix; null when it is.
	 */
	public static function unavailableReason(): ?string {
		if ( MultisiteGuard::refused() ) {
			return __( 'Specflux Site Agent does not support WordPress multisite, so AI apps cannot connect to this site.', 'specflux-site-agent' );
		}
		if ( ! Connector::stored() ) {
			return __( 'AI apps are switched off. Turn on "Let AI apps connect to this site" first.', 'specflux-site-agent' );
		}
		$version = Connector::adapterVersion();
		if ( null === $version ) {
			return sprintf(
				/* translators: %s: the minimum MCP Adapter version. */
				esc_html__( 'The MCP Adapter plugin is not installed. Install and activate version %s or newer from WordPress.org.', 'specflux-site-agent' ),
				esc_html( Connector::MIN_ADAPTER_VERSION )
			);
		}
		if ( ! Connector::adapterReady() ) {
			return sprintf(
				/* translators: 1: installed MCP Adapter version, 2: minimum version. */
				esc_html__( 'The MCP Adapter plugin is version %1$s; Specflux Site Agent needs %2$s or newer. Update it.', 'specflux-site-agent' ),
				esc_html( $version ),
				esc_html( Connector::MIN_ADAPTER_VERSION )
			);
		}

		return null;
	}

	/** Render the AI apps tab (the old Connect screen's address now lands there). */
	public static function render(): void {
		AppsScreen::render();
	}

	/**
	 * Handle the create / test POST done by the AI apps tab itself.
	 *
	 * @return array{client:string,uuid:string,password:string,scope:string,test:?array{ok:bool,category:string,message:string,tools:int}}|WP_Error|null
	 */
	public static function handlePost(): array|WP_Error|null {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() just below.
		$action = isset( $_POST['specflux_site_agent_connect_action'] ) && is_string( $_POST['specflux_site_agent_connect_action'] )
			? sanitize_key( wp_unslash( $_POST['specflux_site_agent_connect_action'] ) )
			: '';
		if ( ! in_array( $action, array( 'create', 'test' ), true ) ) {
			return null;
		}
		check_admin_referer( self::NONCE_FORM );

		$client = isset( $_POST['client'] ) && is_string( $_POST['client'] ) ? sanitize_key( wp_unslash( $_POST['client'] ) ) : '';
		if ( ! ClientConfig::known( $client ) ) {
			return new WP_Error( 'specflux_site_agent_connect_client', __( 'Pick an app from the list.', 'specflux-site-agent' ) );
		}

		if ( 'create' === $action ) {
			$scope = isset( $_POST['scope'] ) && is_string( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';

			return self::create( get_current_user_id(), $client, Connection::sanitizeScope( $scope ) );
		}

		$uuid     = isset( $_POST['uuid'] ) && is_string( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		$password = isset( $_POST['password'] ) && is_string( $_POST['password'] ) ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$registry = Connections::forUser( get_current_user_id() );
		$user     = get_userdata( get_current_user_id() );
		if ( ! isset( $registry[ $uuid ] ) || '' === $password || ! is_object( $user ) ) {
			return new WP_Error( 'specflux_site_agent_connect_test', __( 'That connection no longer exists.', 'specflux-site-agent' ) );
		}

		return array(
			'client'   => $client,
			'uuid'     => $uuid,
			'password' => $password,
			'scope'    => $registry[ $uuid ]['scope'],
			'test'     => ConnectionTester::run( self::serverUrl(), ClientConfig::authorization( (string) $user->user_login, $password ) ),
		);
	}

	/**
	 * Make the application password and register it.
	 *
	 * @param int    $user_id Owner.
	 * @param string $client  A {@see ClientConfig} id.
	 * @param string $scope   A {@see Connection} scope.
	 * @return array{client:string,uuid:string,password:string,scope:string,test:null}|WP_Error
	 */
	public static function create( int $user_id, string $client, string $scope ): array|WP_Error {
		if ( function_exists( 'wp_is_application_passwords_available_for_user' ) && ! wp_is_application_passwords_available_for_user( $user_id ) ) {
			return new WP_Error( 'specflux_site_agent_connect_unavailable', __( 'Application passwords are turned off for your account, so no connection can be made. Your host or another plugin has disabled them.', 'specflux-site-agent' ) );
		}

		$made = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => 'Specflux Site Agent – ' . ( self::clientLabels()[ $client ] ?? $client ) )
		);
		if ( $made instanceof WP_Error ) {
			return $made;
		}

		Connections::add( $user_id, (string) $made[1]['uuid'], $scope );

		return array(
			'client'   => $client,
			'uuid'     => (string) $made[1]['uuid'],
			'password' => (string) $made[0],
			'scope'    => Connection::sanitizeScope( $scope ),
			'test'     => null,
		);
	}

	/**
	 * The one-time view: password, config, copy, test.
	 *
	 * @param array{client:string,uuid:string,password:string,scope:string,test:?array{ok:bool,category:string,message:string,tools:int}} $shown What to show.
	 */
	public static function renderCreated( array $shown ): void {
		$user   = get_userdata( get_current_user_id() );
		$login  = is_object( $user ) ? (string) $user->user_login : '';
		$config = ClientConfig::render( $shown['client'], self::serverUrl(), $login, $shown['password'] );

		echo '<div class="aui-notice aui-notice--success" role="status">' . Kit::icon( 'check' ) . '<div><strong>' . esc_html__( 'Connection created.', 'specflux-site-agent' ) . '</strong> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Kit::icon() is escaped markup.
		echo esc_html__( 'The password below is shown once. Copy the config now: it cannot be shown again, only replaced by a new connection.', 'specflux-site-agent' ) . '</div></div>';

		echo '<section class="aui-card"><div class="aui-card-h"><h2 class="aui-card-t">' . esc_html( self::clientLabels()[ $shown['client'] ] ?? $shown['client'] ) . '</h2></div><div class="aui-card-b" style="display:flex;flex-direction:column;gap:12px">';
		echo '<p>' . esc_html( $config['where'] ) . '</p>';
		echo '<pre id="specflux-site-agent-config" class="specflux-site-agent-pre">' . esc_html( $config['text'] ) . '</pre>';
		echo '<p><button type="button" class="aui-btn aui-btn--secondary aui-btn--sm" data-aui-copy data-aui-target="#specflux-site-agent-config" data-copied="' . esc_attr__( 'Copied', 'specflux-site-agent' ) . '">' . Kit::icon( 'copy' ) . '<span data-aui-copy-label>' . esc_html__( 'Copy', 'specflux-site-agent' ) . '</span></button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Kit::icon() is escaped markup.

		if ( is_array( $shown['test'] ) ) {
			printf(
				'<div class="aui-notice %s" role="%s">%s<div>%s</div></div>',
				$shown['test']['ok'] ? 'aui-notice--success' : 'aui-notice--error',
				$shown['test']['ok'] ? 'status' : 'alert',
				Kit::icon( $shown['test']['ok'] ? 'check' : 'x' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
				esc_html( $shown['test']['message'] )
			);
		}

		echo '<form method="post" action="' . esc_url( self::url() ) . '" class="aui-row-flex">';
		wp_nonce_field( self::NONCE_FORM );
		echo '<input type="hidden" name="specflux_site_agent_connect_action" value="test">';
		printf( '<input type="hidden" name="client" value="%s">', esc_attr( $shown['client'] ) );
		printf( '<input type="hidden" name="uuid" value="%s">', esc_attr( $shown['uuid'] ) );
		printf( '<input type="hidden" name="password" value="%s">', esc_attr( $shown['password'] ) );
		echo '<button type="submit" class="aui-btn aui-btn--secondary aui-btn--sm">' . esc_html__( 'Test connection', 'specflux-site-agent' ) . '</button> ';
		echo '<span class="aui-help">' . esc_html__( 'Makes two requests from this site to its own address, with this connection\'s password.', 'specflux-site-agent' ) . '</span>';
		echo '</form></div></section>';
	}

	/**
	 * The vendor docs each app card links to. Every one of these was read when
	 * the card copy below was checked (2026-10-09); a client without a page of
	 * its own has no link.
	 *
	 * @return array<string,string> card key => docs URL.
	 */
	private static function docsLinks(): array {
		return array(
			'claude'                     => 'https://claude.com/docs/connectors/custom/remote-mcp',
			'chatgpt'                    => 'https://developers.openai.com/apps-sdk/deploy/connect-chatgpt',
			ClientConfig::CURSOR         => 'https://cursor.com/docs/context/mcp',
			ClientConfig::VS_CODE        => 'https://code.visualstudio.com/docs/agents/reference/mcp-configuration',
			ClientConfig::CLAUDE_CODE    => 'https://code.claude.com/docs/en/mcp',
			ClientConfig::CLAUDE_DESKTOP => 'https://github.com/Automattic/mcp-wordpress-remote',
		);
	}

	/** One card's header: initial tile, title, optional badge. */
	private static function cardHeader( string $title, string $badge_html ): string {
		$words   = preg_split( '/[\s(]+/', $title );
		$initial = strtoupper( substr( (string) ( is_array( $words ) ? $words[0] : $title ), 0, 1 ) );

		return '<div class="aui-card-h"><span class="aui-tile aui-tile--sm"><b aria-hidden="true">' . esc_html( $initial ) . '</b></span><h3 class="aui-card-t">' . esc_html( $title ) . '</h3>' . $badge_html . '</div>';
	}

	/**
	 * How many of the current user's connections were made from each app card.
	 *
	 * @return array<string,int> client id => count.
	 */
	private static function connectionCountsByClient(): array {
		$counts   = array();
		$registry = Connections::forUser( get_current_user_id() );
		foreach ( WP_Application_Passwords::get_user_application_passwords( get_current_user_id() ) as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['uuid'] ) || ! isset( $registry[ (string) $item['uuid'] ] ) ) {
				continue;
			}
			foreach ( self::clientLabels() as $client => $label ) {
				if ( (string) ( $item['name'] ?? '' ) === 'Specflux Site Agent – ' . $label ) {
					$counts[ $client ] = ( $counts[ $client ] ?? 0 ) + 1;
				}
			}
		}

		return $counts;
	}

	/** Is a signed-in (OAuth) app whose name contains one of these words connected? */
	private static function signedInMatches( string ...$needles ): bool {
		foreach ( array_keys( Store::grantIndex() ) as $id ) {
			$grant = Store::grant( (string) $id );
			if ( null === $grant ) {
				continue;
			}
			$name = strtolower( Clients::resolveStoredName( (string) $grant['client'] ) );
			foreach ( $needles as $needle ) {
				if ( str_contains( $name, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/** The app cards: the two sign-in apps, then every application-password client. */
	public static function renderCards(): void {
		$blocker = self::oauthBlocker();
		$url     = self::serverUrl();
		$docs    = self::docsLinks();

		echo '<div><h2 class="aui-card-t">' . esc_html__( 'Connect an app', 'specflux-site-agent' ) . '</h2>';
		echo '<p class="aui-card-d">' . esc_html__( 'Pick the app you use. Specflux Site Agent makes a WordPress application password for you, named after the app, and shows the exact setup text to paste in. Read only is the safe default: the app can look but not change anything.', 'specflux-site-agent' ) . '</p></div>';

		$sign_in = array(
			'claude'  => array(
				// Menu labels checked against https://claude.com/docs/connectors/custom/remote-mcp
				// (Customize > Connectors > Add custom connector).
				'label' => __( 'Claude (web, desktop and mobile)', 'specflux-site-agent' ),
				'steps' => __( 'In Claude, open Customize > Connectors and click Add custom connector. Paste the URL above, click Add, then Connect. You will sign in to this site and choose Read only or Read and write.', 'specflux-site-agent' ),
				'on'    => self::signedInMatches( 'claude' ),
				'open'  => __( 'Claude setup guide', 'specflux-site-agent' ),
			),
			'chatgpt' => array(
				// Checked against https://developers.openai.com/apps-sdk/deploy/connect-chatgpt
				// (ChatGPT Plugins > plus > Add custom MCP server; the page does not mention developer mode).
				// Seen live 2026-10-09 on a Free account: Plugins > Add > Add custom MCP server, no developer mode.
				'label' => __( 'ChatGPT', 'specflux-site-agent' ),
				'steps' => __( 'In ChatGPT, go to Plugins, open the Add menu, then Add custom MCP server. Enter a name, paste the URL above (it must include the /mcp path), and finish the sign-in prompt. You will sign in to this site and choose Read only or Read and write. Your ChatGPT account or workspace policies decide whether you can add custom servers.', 'specflux-site-agent' ),
				'on'    => self::signedInMatches( 'chatgpt', 'openai' ),
				'open'  => __( 'ChatGPT setup guide', 'specflux-site-agent' ),
			),
		);

		echo '<div class="aui-grid-2">';
		foreach ( $sign_in as $key => $card ) {
			$badge = $card['on'] ? '<span class="aui-badge aui-badge--ok aui-end">' . esc_html__( 'Connected', 'specflux-site-agent' ) . '</span>' : '';
			echo '<section class="aui-card">' . self::cardHeader( $card['label'], $badge ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			echo '<div class="aui-card-b" style="display:flex;flex-direction:column;gap:12px">';
			if ( null !== $blocker ) {
				echo '<div class="aui-notice aui-notice--warning" role="status">' . Kit::icon( 'warning' ) . '<div>' . esc_html( $blocker ) . '</div></div></div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
				continue;
			}
			$id = 'specflux-site-agent-oauth-url-' . $key;
			echo '<div class="aui-copy"><label class="aui-sr" for="' . esc_attr( $id ) . '">' . esc_html__( 'MCP URL', 'specflux-site-agent' ) . '</label>';
			echo '<input class="aui-input" id="' . esc_attr( $id ) . '" readonly value="' . esc_attr( $url ) . '" title="' . esc_attr( $url ) . '">';
			echo '<button type="button" class="aui-btn aui-btn--secondary aui-btn--sm" data-aui-copy data-aui-target="#' . esc_attr( $id ) . '" data-copied="' . esc_attr__( 'Copied', 'specflux-site-agent' ) . '">' . Kit::icon( 'copy' ) . '<span data-aui-copy-label>' . esc_html__( 'Copy URL', 'specflux-site-agent' ) . '</span></button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
			echo '<p>' . esc_html( $card['steps'] ) . '</p></div>';
			echo '<div class="aui-card-f"><a class="aui-btn aui-btn--ghost aui-btn--sm" href="#' . esc_attr( Shell::SECTION_SIGNED_IN ) . '">' . esc_html__( 'Signed-in apps: see or revoke these sign-ins', 'specflux-site-agent' ) . '</a>';
			echo '<a class="aui-btn aui-btn--ghost aui-btn--sm aui-end" href="' . esc_url( $docs[ $key ] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $card['open'] ) . Kit::icon( 'arrow-square-out' ) . '</a></div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
		}
		echo '</div>';

		echo '<div><h3 class="aui-card-t">' . esc_html__( 'Apps that use an application password', 'specflux-site-agent' ) . '</h3></div>';
		$counts = self::connectionCountsByClient();
		echo '<div class="aui-grid-2">';
		foreach ( self::clientLabels() as $client => $label ) {
			$count = $counts[ $client ] ?? 0;
			$badge = $count > 0
				? '<span class="aui-badge aui-badge--ok aui-end">' . esc_html( sprintf( /* translators: %d: number of connections made for this app. */ _n( '%d connection', '%d connections', $count, 'specflux-site-agent' ), $count ) ) . '</span>'
				: '';
			echo '<section class="aui-card">' . self::cardHeader( $label, $badge ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			echo '<form method="post" action="' . esc_url( self::url() ) . '">';
			wp_nonce_field( self::NONCE_FORM );
			echo '<input type="hidden" name="specflux_site_agent_connect_action" value="create">';
			printf( '<input type="hidden" name="client" value="%s">', esc_attr( $client ) );
			echo '<div class="aui-card-b"><div class="aui-field"><label class="aui-label" for="specflux-site-agent-scope-' . esc_attr( $client ) . '">' . esc_html__( 'Access', 'specflux-site-agent' ) . '</label><select class="aui-select" name="scope" id="specflux-site-agent-scope-' . esc_attr( $client ) . '">';
			printf( '<option value="%s">%s</option>', esc_attr( Connection::SCOPE_READ ), esc_html__( 'Read only', 'specflux-site-agent' ) );
			printf( '<option value="%s">%s</option>', esc_attr( Connection::SCOPE_READ_WRITE ), esc_html__( 'Read and write', 'specflux-site-agent' ) );
			echo '</select></div></div>';
			echo '<div class="aui-card-f"><button type="submit" class="aui-btn aui-btn--primary aui-btn--sm">' . esc_html__( 'Create connection', 'specflux-site-agent' ) . '</button>';
			if ( isset( $docs[ $client ] ) ) {
				echo '<a class="aui-btn aui-btn--ghost aui-btn--sm aui-end" href="' . esc_url( $docs[ $client ] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Setup guide', 'specflux-site-agent' ) . Kit::icon( 'arrow-square-out' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
			}
			echo '</div></form></section>';
		}
		echo '</div>';
	}

	/** The current user's connections. */
	public static function renderConnections(): void {
		$user_id  = get_current_user_id();
		$registry = Connections::forUser( $user_id );
		$core     = array();
		foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( is_array( $item ) && isset( $item['uuid'] ) ) {
				$core[ (string) $item['uuid'] ] = $item;
			}
		}

		$rows = array_intersect_key( $registry, $core );
		echo '<section class="aui-card"><div class="aui-card-h"><h2 class="aui-card-t">' . esc_html__( 'Your connections', 'specflux-site-agent' ) . '</h2>';
		if ( array() !== $rows ) {
			echo '<span class="aui-count">' . esc_html( (string) count( $rows ) ) . '</span>';
		}
		echo '</div>';
		if ( array() === $rows ) {
			echo '<div class="aui-card-b"><p class="aui-muted">' . esc_html__( 'No connections yet.', 'specflux-site-agent' ) . '</p></div></section>';

			return;
		}

		echo '<div class="aui-table-wrap"><table class="aui-table"><thead><tr>';
		foreach ( array( __( 'Connection', 'specflux-site-agent' ), __( 'Access', 'specflux-site-agent' ), __( 'Created', 'specflux-site-agent' ), __( 'Last used', 'specflux-site-agent' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '<th scope="col"><span class="aui-sr">' . esc_html__( 'Actions', 'specflux-site-agent' ) . '</span></th></tr></thead><tbody>';
		foreach ( $rows as $uuid => $entry ) {
			$item  = $core[ $uuid ];
			$write = Connection::SCOPE_READ_WRITE === $entry['scope'];
			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) ( $item['name'] ?? '' ) ) . '</strong> <span class="aui-mono aui-muted">' . esc_html( substr( $uuid, 0, 8 ) ) . '</span></td>';
			$access = $write
				? '<span class="aui-badge aui-badge--info">' . Kit::icon( 'pencil' ) . esc_html__( 'Read and write', 'specflux-site-agent' ) . '</span>'
				: '<span class="aui-badge">' . Kit::icon( 'book' ) . esc_html__( 'Read only', 'specflux-site-agent' ) . '</span>';
			echo '<td>' . $access . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup built above.
			echo '<td class="aui-mono">' . esc_html( (string) wp_date( 'Y-m-d H:i', (int) ( $item['created'] ?? 0 ) ) ) . '</td>';
			$used = (int) ( $item['last_used'] ?? 0 );
			echo '<td class="aui-mono">' . esc_html( $used > 0 ? (string) wp_date( 'Y-m-d H:i', $used ) : __( 'Never', 'specflux-site-agent' ) ) . '</td>';
			echo '<td><div class="aui-actions">';
			self::renderConnectionForm( 'specflux_site_agent_connection_scope', $uuid, $write ? Connection::SCOPE_READ : Connection::SCOPE_READ_WRITE, $write ? __( 'Make read only', 'specflux-site-agent' ) : __( 'Allow writes', 'specflux-site-agent' ), 'aui-btn--secondary' );
			self::renderConnectionForm( 'specflux_site_agent_connection_revoke', $uuid, '', __( 'Revoke', 'specflux-site-agent' ), 'aui-btn--danger-outline' );
			echo '</div></td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	/**
	 * Connection uuid => name for the current user's live connections.
	 *
	 * @return array<string,string>
	 */
	private static function connectionNames(): array {
		$names = array();
		foreach ( WP_Application_Passwords::get_user_application_passwords( get_current_user_id() ) as $item ) {
			if ( is_array( $item ) && isset( $item['uuid'] ) ) {
				$names[ (string) $item['uuid'] ] = (string) ( $item['name'] ?? '' );
			}
		}

		return $names;
	}

	/**
	 * Outcome => [label, badge modifier, dot modifier].
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	private static function outcomes(): array {
		return array(
			ActivityLog::RAN     => array( __( 'Ran', 'specflux-site-agent' ), 'aui-badge--ok', 'ok' ),
			ActivityLog::WAITING => array( __( 'Waiting for approval', 'specflux-site-agent' ), 'aui-badge--warn', 'warn' ),
			ActivityLog::REFUSED => array( __( 'Refused', 'specflux-site-agent' ), 'aui-badge--bad', 'bad' ),
			ActivityLog::ERROR   => array( __( 'Error', 'specflux-site-agent' ), 'aui-badge--bad', 'bad' ),
		);
	}

	/** The right-rail feed: the latest few calls. */
	public static function renderFeed(): void {
		$rows = ActivityLog::recent( 6 );
		if ( array() === $rows ) {
			echo '<p class="aui-muted">' . esc_html__( 'No calls yet.', 'specflux-site-agent' ) . '</p>';

			return;
		}
		$outcomes = self::outcomes();
		echo '<ul class="aui-feed">';
		foreach ( $rows as $row ) {
			$tone = $outcomes[ $row['outcome'] ][2] ?? 'info';
			$when = (string) wp_date( 'M j, H:i', (int) strtotime( $row['created_at'] . ' UTC' ) );
			echo '<li><span class="aui-dot aui-dot--' . esc_attr( $tone ) . '"></span><div><span class="aui-mono">' . esc_html( $row['ability'] ) . '</span> · ' . esc_html( $outcomes[ $row['outcome'] ][0] ?? $row['outcome'] ) . '<time>' . esc_html( $when ) . '</time></div></li>';
		}
		echo '</ul><p style="margin-top:12px"><a href="#' . esc_attr( Shell::SECTION_ACTIVITY ) . '">' . esc_html__( 'View all activity', 'specflux-site-agent' ) . '</a></p>';
	}

	/** The last 50 calls, optionally for one connection. */
	public static function renderActivity(): void {
		$user_id  = get_current_user_id();
		$registry = Connections::forUser( $user_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$filter = isset( $_GET['connection'] ) && is_string( $_GET['connection'] ) ? sanitize_text_field( wp_unslash( $_GET['connection'] ) ) : '';
		$filter = isset( $registry[ $filter ] ) ? $filter : '';
		$names  = self::connectionNames();

		echo '<section class="aui-card" id="' . esc_attr( Shell::SECTION_ACTIVITY ) . '"><div class="aui-card-h"><div><h2 class="aui-card-t">' . esc_html__( 'Recent activity', 'specflux-site-agent' ) . '</h2>';
		echo '<p class="aui-card-d">' . esc_html__( 'The last 50 calls from AI apps, kept for 30 days. Only what was called and how it ended is kept, never the details of the request or its result.', 'specflux-site-agent' ) . '</p></div></div>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) . '#' . Shell::SECTION_ACTIVITY ) . '" class="aui-toolbar"><input type="hidden" name="page" value="' . esc_attr( Shell::APPS ) . '">';
		echo '<label class="aui-label" for="specflux-site-agent-activity-filter">' . esc_html__( 'Connection', 'specflux-site-agent' ) . '</label><div style="width:240px"><select class="aui-select" name="connection" id="specflux-site-agent-activity-filter">';
		printf( '<option value="">%s</option>', esc_html__( 'All', 'specflux-site-agent' ) );
		foreach ( array_intersect_key( $names, $registry ) as $uuid => $name ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $uuid ), selected( $filter, $uuid, false ), esc_html( $name ) );
		}
		echo '</select></div><button type="submit" class="aui-btn aui-btn--secondary aui-btn--sm">' . esc_html__( 'Filter', 'specflux-site-agent' ) . '</button></form>';

		$rows = ActivityLog::recent( 50, '' === $filter ? null : $filter );
		if ( array() === $rows ) {
			echo '<div class="aui-card-b"><p class="aui-muted">' . esc_html__( 'No calls yet.', 'specflux-site-agent' ) . '</p></div></section>';

			return;
		}

		$outcomes = self::outcomes();
		echo '<div class="aui-table-wrap"><table class="aui-table"><thead><tr>';
		foreach ( array( __( 'Time', 'specflux-site-agent' ), __( 'Connection', 'specflux-site-agent' ), __( 'User', 'specflux-site-agent' ), __( 'Tool', 'specflux-site-agent' ), __( 'Outcome', 'specflux-site-agent' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$user = get_userdata( $row['user_id'] );
			$out  = $outcomes[ $row['outcome'] ] ?? array( $row['outcome'], '', 'info' );
			echo '<tr>';
			echo '<td class="aui-mono">' . esc_html( (string) wp_date( 'Y-m-d H:i:s', (int) strtotime( $row['created_at'] . ' UTC' ) ) ) . '</td>';
			echo '<td>' . esc_html( $names[ $row['uuid'] ] ?? '' ) . ' <span class="aui-mono aui-muted">' . esc_html( substr( $row['uuid'], 0, 8 ) ) . '</span></td>';
			echo '<td>' . esc_html( is_object( $user ) && isset( $user->user_login ) ? (string) $user->user_login : '#' . $row['user_id'] ) . '</td>';
			echo '<td class="aui-mono">' . esc_html( $row['ability'] ) . '</td>';
			echo '<td><span class="aui-badge ' . esc_attr( $out[1] ) . '">' . esc_html( $out[0] ) . '</span></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div></section>';
	}

	/** One per-connection button. */
	private static function renderConnectionForm( string $action, string $uuid, string $scope, string $label, string $variant ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="uuid" value="' . esc_attr( $uuid ) . '">';
		if ( '' !== $scope ) {
			echo '<input type="hidden" name="scope" value="' . esc_attr( $scope ) . '">';
		}
		wp_nonce_field( self::NONCE_CONNECTION . $uuid );
		printf( '<button type="submit" class="aui-btn aui-btn--sm %s">%s</button>', esc_attr( $variant ), esc_html( $label ) );
		echo '</form>';
	}

	/** admin-post: change one connection's scope. */
	public static function handleScope(): void {
		$uuid = self::authorisedUuid();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorisedUuid().
		$scope = isset( $_POST['scope'] ) && is_string( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		if ( null !== $uuid ) {
			Connections::add( get_current_user_id(), $uuid, $scope );
		}
		wp_safe_redirect( self::url() );
	}

	/** admin-post: delete the application password and forget the connection. */
	public static function handleRevoke(): void {
		$uuid = self::authorisedUuid();
		if ( null !== $uuid ) {
			WP_Application_Passwords::delete_application_password( get_current_user_id(), $uuid );
			Connections::remove( get_current_user_id(), $uuid );
		}
		wp_safe_redirect( self::url() );
	}

	/**
	 * Nonce, capability, and ownership for a per-connection action.
	 *
	 * @return string|null The UUID, or null when it is not one of this user's connections.
	 */
	private static function authorisedUuid(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() just below.
		$uuid = isset( $_POST['uuid'] ) && is_string( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		check_admin_referer( self::NONCE_CONNECTION . $uuid );

		if ( ! current_user_can( Connector::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}

		return isset( Connections::forUser( get_current_user_id() )[ $uuid ] ) ? $uuid : null;
	}
}
