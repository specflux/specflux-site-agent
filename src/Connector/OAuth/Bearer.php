<?php
/**
 * Turns a bearer token into the request's user and connection, on the MCP route only.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector\OAuth;

use Specflux\SenroFlux\Connector\Connection;
use Specflux\SenroFlux\Connector\Connector;
use Specflux\SenroFlux\MultisiteGuard;
use Specflux\SenroFlux\Run\Clock;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The resource-server half. MCP auth "Token Handling": a server MUST only
 * accept tokens issued for it, and "MUST NOT accept or transit any other
 * tokens". These tokens therefore authenticate exactly one route, in two
 * independent layers:
 *
 * 1. `determine_current_user` accepts a token only when the request's
 *    effective REST route is the SenroFlux MCP route. "Effective" matters:
 *    `?rest_route=` in the query overrides the URL path in WordPress, so
 *    `/wp-json/senroflux/mcp?rest_route=/wp/v2/users` is the users route.
 * 2. `rest_pre_dispatch` refuses any OAuth-authenticated request whose
 *    dispatched route is not the MCP route, whatever step 1 concluded.
 */
final class Bearer {

	/** @var array{grant:string,user:int,scope:string}|null The token this request authenticated with. */
	private static ?array $active = null;

	/** A bearer token was presented on the MCP route but did not validate. */
	private static bool $rejected = false;

	/** The first dispatch of a request is the real one; later ones are internal sub-requests. */
	private static bool $dispatched = false;

	public static function register(): void {
		// After core's application-password check (priority 20): never override an existing user.
		add_filter( 'determine_current_user', array( self::class, 'authenticate' ), 21 );
		add_filter( 'senroflux_connector_connection', array( self::class, 'connection' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'guardDispatch' ), 0, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'challenge' ), 10, 3 );
	}

	/** Test seam: forget per-request state. */
	public static function reset(): void {
		self::$active     = null;
		self::$rejected   = false;
		self::$dispatched = false;
	}

	/**
	 * `determine_current_user` filter.
	 *
	 * @param mixed $user_id User id resolved so far (false/0 when none).
	 * @return mixed
	 */
	public static function authenticate( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}
		// Not Connector::enabled(): this runs before plugins finish loading; the MCP route's own
		// permission check still requires the full enabled() and the capability.
		if ( ! Connector::stored() || MultisiteGuard::refused() || ! self::onMcpRoute() ) {
			return $user_id;
		}
		$token = self::bearerToken();
		if ( null === $token ) {
			return $user_id;
		}

		$record = self::validate( $token );
		if ( null === $record ) {
			self::$rejected = true;

			return $user_id;
		}
		self::$active = $record;

		return $record['user'];
	}

	/**
	 * A token's record when it is current, unrevoked and issued for this server; else null.
	 *
	 * @param string $token Plaintext access token.
	 * @return array{grant:string,user:int,scope:string}|null
	 */
	public static function validate( string $token ): ?array {
		$access = Store::access( $token );
		if ( null === $access || $access['exp'] < Clock::now() ) {
			return null;
		}
		$grant = Store::grant( $access['g'] );
		// Audience (RFC 8707 §2 / MCP auth): the grant must have been issued for exactly this server.
		if ( null === $grant || ! hash_equals( OAuth::serverUrl(), (string) $grant['resource'] ) ) {
			return null;
		}
		$user = (int) $grant['user'];
		if ( $user <= 0 ) {
			return null;
		}

		return array(
			'grant' => $access['g'],
			'user'  => $user,
			'scope' => $access['scope'],
		);
	}

	/** The bearer token on this request's Authorization header, or null. */
	private static function bearerToken(): ?string {
		$header = '';
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && '' !== $_SERVER[ $key ] ) {
				$header = $_SERVER[ $key ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched against a strict pattern below.
				break;
			}
		}
		// RFC 6750 §2.1; the token is a 64-char hex string, so anything else is not ours.
		if ( 1 !== preg_match( '/^[Bb]earer ([A-Za-z0-9]{64})$/', $header, $m ) ) {
			return null;
		}

		return $m[1];
	}

	/**
	 * Is this PHP request served by the site's front controller (`index.php` in ABSPATH)?
	 *
	 * A positive allowlist of the entry point. `?rest_route=` is honoured by REST routing only,
	 * so without this a token could ride `admin-ajax.php?rest_route=/senroflux/mcp` (or
	 * admin-post.php, wp-login.php, xmlrpc.php, wp-cron.php, any wp-admin page) past layer 2,
	 * which only sees REST dispatches. `REST_REQUEST` cannot be used: it is not yet defined
	 * when `determine_current_user` resolves the user.
	 */
	private static function entryIsFrontController(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || defined( 'IFRAME_REQUEST' ) ) {
			return false;
		}
		if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		// core sets $pagenow from PHP_SELF in wp-includes/vars.php, before any plugin loads.
		if ( 'index.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return false;
		}
		$script = isset( $_SERVER['SCRIPT_FILENAME'] ) && is_string( $_SERVER['SCRIPT_FILENAME'] ) ? $_SERVER['SCRIPT_FILENAME'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, never used.
		if ( '' === $script || 'index.php' !== basename( $script ) ) {
			return false;
		}
		$dir = trailingslashit( wp_normalize_path( dirname( $script ) ) );
		if ( trailingslashit( wp_normalize_path( ABSPATH ) ) === $dir ) {
			return true;
		}
		// Symlinked docroots: compare resolved paths, only when both resolve.
		$real_dir  = realpath( dirname( $script ) );
		$real_root = realpath( ABSPATH );

		return false !== $real_dir && false !== $real_root && trailingslashit( wp_normalize_path( $real_dir ) ) === trailingslashit( wp_normalize_path( $real_root ) );
	}

	/**
	 * Is the REST route this request will dispatch exactly the MCP route?
	 *
	 * Mirrors WordPress: a `rest_route` query/body parameter wins over the path.
	 */
	public static function onMcpRoute(): bool {
		if ( ! self::entryIsFrontController() ) {
			return false;
		}
		$route = '/' . Connector::ROUTE_NAMESPACE . '/' . Connector::ROUTE;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- routing decision only; the value is compared, never used.
		$param = $_GET['rest_route'] ?? $_POST['rest_route'] ?? null;
		// phpcs:enable
		if ( null !== $param ) {
			return is_string( $param ) && rtrim( $param, '/\\' ) === $route;
		}

		$resource_path = (string) wp_parse_url( OAuth::serverUrl(), PHP_URL_PATH );
		if ( ! str_ends_with( $resource_path, $route ) || ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return false; // Plain permalinks: only the rest_route form above can match.
		}
		$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return rtrim( $path, '/' ) === rtrim( $resource_path, '/' );
	}

	/**
	 * `senroflux_connector_connection` filter: the OAuth-authenticated request's connection.
	 *
	 * @param mixed $connection Connection resolved so far.
	 * @return mixed
	 */
	public static function connection( $connection ) {
		if ( $connection instanceof Connection || null === self::$active ) {
			return $connection;
		}
		if ( (int) get_current_user_id() !== self::$active['user'] ) {
			return $connection;
		}

		return new Connection(
			self::$active['user'],
			'oauth:' . self::$active['grant'],
			OAuth::allowsWrite( self::$active['scope'] ) ? Connection::SCOPE_READ_WRITE : Connection::SCOPE_READ
		);
	}

	/**
	 * `rest_pre_dispatch`: the second layer. An OAuth-authenticated request may only reach the MCP route.
	 *
	 * @param mixed $result  Short-circuit value so far.
	 * @param mixed $server  REST server.
	 * @param mixed $request Request.
	 * @return mixed
	 */
	public static function guardDispatch( $result, $server = null, $request = null ) {
		unset( $server );
		if ( null === self::$active || self::$dispatched || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}
		self::$dispatched = true;
		if ( rtrim( (string) $request->get_route(), '/' ) === '/' . Connector::ROUTE_NAMESPACE . '/' . Connector::ROUTE ) {
			return $result;
		}
		self::$active = null;

		return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', array( 'status' => 403 ) );
	}

	/**
	 * `rest_post_dispatch`: attach `WWW-Authenticate` to the MCP route's 401/403 (RFC 6750 §3, RFC 9728 §5.1).
	 *
	 * @param mixed $response REST response.
	 * @param mixed $server   REST server.
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public static function challenge( $response, $server = null, $request = null ) {
		unset( $server );
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_status' ) || ! method_exists( $response, 'header' ) || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $response;
		}
		if ( rtrim( (string) $request->get_route(), '/' ) !== '/' . Connector::ROUTE_NAMESPACE . '/' . Connector::ROUTE ) {
			return $response;
		}
		$status = (int) $response->get_status();
		if ( 401 === $status ) {
			$response->header( 'WWW-Authenticate', self::$rejected ? Discovery::challenge( 'invalid_token', 'The access token is invalid or has expired.' ) : Discovery::challenge() );
		} elseif ( 403 === $status && null !== self::$active ) {
			$response->header( 'WWW-Authenticate', Discovery::challenge( 'insufficient_scope', 'The token does not permit this request.' ) );
		}

		return $response;
	}
}
