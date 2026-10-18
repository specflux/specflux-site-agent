<?php
/**
 * PHPUnit bootstrap for the Specflux Site Agent plugin suite.
 *
 * Defines the minimal WordPress shims the code under test calls — only when a
 * real WP load order hasn't already provided them. The hook functions are a
 * WORKING mini-registry (not no-ops) so tests can exercise filter contracts
 * like `specflux_site_agent_default_budget` for real.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

$specflux_site_agent_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! is_readable( $specflux_site_agent_autoload ) ) {
	fwrite( STDERR, "Missing vendor/autoload.php — run `composer install` first.\n" );
	exit( 1 );
}
require_once $specflux_site_agent_autoload;

// WordPress defines ABSPATH before any plugin file loads; every src file
// guards itself with `defined( 'ABSPATH' ) || exit`. Under bare PHPUnit that
// guard would silently EXIT the whole test run, so provide the constant.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/specflux-site-agent-tests/' );
}

// Test-only stand-ins for WordPress globals (a real WP load order wins).
if ( ! class_exists( 'wpdb', false ) ) {
	require_once __DIR__ . '/stubs/wpdb.php';
}
if ( ! class_exists( 'WP_Error', false ) ) {
	require_once __DIR__ . '/stubs/wp-error.php';
}
require_once __DIR__ . '/stubs/abilities.php';
require_once __DIR__ . '/Packs/BreadthFilter.php';
// Agent Safety host surface (S14): the belayline() locator, its grants
// service, and RequestContext's correlation scope. Inert until a test opts in.
require_once __DIR__ . '/stubs/agent-safety.php';
require_once __DIR__ . '/stubs/agent-safety-context.php';
// Belayline Agent Governance 0.5 change-history API shapes (0.4 S3.2).
require_once __DIR__ . '/stubs/agent-safety-changes-core.php';
require_once __DIR__ . '/stubs/agent-safety-changes.php';
// Runner test doubles (FakeGateway, RecordingBridge) — class_exists-guarded.
require_once __DIR__ . '/stubs/run-doubles.php';
// wp_ai_client_prompt() double (defect 2): AiClientMediaGateway's timeout tests.
require_once __DIR__ . '/stubs/ai-client-prompt-builder.php';
// Admin-screen shims (S13): nonce/fields/enqueue for the Runs screen handlers.
require_once __DIR__ . '/stubs/admin.php';
// HTTP-surface shims (S9/S17): wp_send_json_*, check_ajax_referer, REST doubles.
require_once __DIR__ . '/stubs/http.php';
// Connect screen (K3): application passwords and an offline wp_remote_post().
require_once __DIR__ . '/stubs/connector-admin.php';
// Connector (K1): fake mcp-adapter adapter/server/tool (never the real class names).
require_once __DIR__ . '/stubs/mcp-adapter.php';
// Action Scheduler queue, version and wp_set_current_user() doubles (0.4 U2).
require_once __DIR__ . '/stubs/action-scheduler.php';
require_once __DIR__ . '/Connector/ConnectorTestCase.php';

// The global specflux_site_agent() locator (function_exists-guarded, same as main).
require_once dirname( __DIR__ ) . '/src/api.php';

$GLOBALS['specflux_site_agent_test_abilities'] = array();


if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
// RunsScreen::assets() (S10) reads these for its enqueue URLs/paths — a real
// WP load order defines both from the plugin's own file location; under bare
// PHPUnit there is no such file, so point them at the plugin root, matching
// specflux-site-agent.php's own definitions closely enough for tests that call
// assets() directly (it only needs `build/runs/index.asset.php` to resolve).
if ( ! defined( 'SPECFLUX_SITE_AGENT_PATH' ) ) {
	define( 'SPECFLUX_SITE_AGENT_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'SPECFLUX_SITE_AGENT_URL' ) ) {
	define( 'SPECFLUX_SITE_AGENT_URL', 'http://example.test/wp-content/plugins/specflux-site-agent/' );
}
// S23: specflux-site-agent.php (never loaded under bare PHPUnit) defines this; tests
// that read it (PublicSurfaceTest) need the same value.
if ( ! defined( 'SPECFLUX_SITE_AGENT_API_VERSION' ) ) {
	define( 'SPECFLUX_SITE_AGENT_API_VERSION', '0.4.0' );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// Site-timezone shims. Tests pick the zone via
// $GLOBALS['specflux_site_agent_test_timezone'] (default UTC).
if ( ! function_exists( 'wp_timezone_string' ) ) {
	/** Test stand-in: the configured test timezone string. */
	function wp_timezone_string(): string {
		return (string) ( $GLOBALS['specflux_site_agent_test_timezone'] ?? 'UTC' );
	}
}
if ( ! function_exists( 'wp_timezone' ) ) {
	/** Test stand-in: the configured test timezone. */
	function wp_timezone(): DateTimeZone {
		return new DateTimeZone( wp_timezone_string() );
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	/** Test stand-in: format a timestamp in the given (or site) timezone. */
	function wp_date( string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null ): string {
		return ( new DateTimeImmutable( '@' . ( $timestamp ?? time() ) ) )
			->setTimezone( $timezone ?? wp_timezone() )
			->format( $format );
	}
}

// --- Working mini hook registry -------------------------------------------

$GLOBALS['specflux_site_agent_test_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Register a filter callback (priority + arg count accepted, dispatch in priority order).
	 *
	 * @param string   $hook         Filter name.
	 * @param callable $callback     Callback.
	 * @param int      $priority     Priority.
	 * @param int      $accepted_args Argument count.
	 * @return bool
	 */
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['specflux_site_agent_test_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );

		return true;
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	/**
	 * Minimal allow-list shim: drops every tag not in `$allowed`, and every
	 * attribute not allowed for a kept tag (href values also need an allowed
	 * protocol). Real `wp_kses()` is stricter; this only has to prove the
	 * production call site passes a tight allow-list.
	 *
	 * @param string                           $text              Markup.
	 * @param array<string,array<string,bool>> $allowed           Tag => attribute allow-list.
	 * @param list<string>                     $allowed_protocols Allowed URL schemes.
	 */
	function wp_kses( string $text, array $allowed, array $allowed_protocols = array( 'http', 'https' ) ): string {
		return (string) preg_replace_callback(
			'#<(/?)([a-z0-9]+)([^>]*)>#i',
			static function ( array $m ) use ( $allowed, $allowed_protocols ): string {
				$tag = strtolower( $m[2] );
				if ( ! isset( $allowed[ $tag ] ) ) {
					return '';
				}
				if ( '/' === $m[1] ) {
					return '</' . $tag . '>';
				}
				$attrs = '';
				if ( preg_match_all( '#([a-z-]+)\s*=\s*"([^"]*)"#i', $m[3], $pairs, PREG_SET_ORDER ) ) {
					foreach ( $pairs as $pair ) {
						$name = strtolower( $pair[1] );
						if ( empty( $allowed[ $tag ][ $name ] ) ) {
							continue;
						}
						if ( 'href' === $name && ! preg_match( '#^(' . implode( '|', array_map( 'preg_quote', $allowed_protocols ) ) . '):#i', $pair[2] ) && ! str_starts_with( $pair[2], '/' ) ) {
							continue;
						}
						$attrs .= ' ' . $name . '="' . $pair[2] . '"';
					}
				}

				return '<' . $tag . $attrs . '>';
			},
			$text
		);
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Dispatch through registered callbacks, WP-style value threading.
	 *
	 * @param string $hook    Filter name.
	 * @param mixed  $value   Value.
	 * @param mixed  ...$args Extra args.
	 * @return mixed
	 */
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['specflux_site_agent_test_filters'][ $hook ] ?? array() as $callbacks ) {
			foreach ( $callbacks as list( $callback, $accepted_args ) ) {
				$value = $callback( $value, ...array_slice( $args, 0, $accepted_args - 1 ) );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	/** Registry-backed shim: is any callback attached to the hook? */
	function has_filter( string $hook, $callback = false ): bool {
		return ! empty( array_filter( $GLOBALS['specflux_site_agent_test_filters'][ $hook ] ?? array() ) );
	}
}

if ( ! function_exists( 'get_stylesheet' ) ) {
	/**
	 * Active theme slug; Twenty Twenty-Five unless a test sets
	 * `$GLOBALS['specflux_site_agent_test_stylesheet']`.
	 */
	function get_stylesheet(): string {
		return $GLOBALS['specflux_site_agent_test_stylesheet'] ?? 'twentytwentyfive';
	}
}

if ( ! function_exists( 'get_template' ) ) {
	/**
	 * Parent theme slug; follows the stylesheet unless a test sets
	 * `$GLOBALS['specflux_site_agent_test_template']`.
	 */
	function get_template(): string {
		return $GLOBALS['specflux_site_agent_test_template'] ?? get_stylesheet();
	}
}

if ( ! function_exists( 'remove_all_filters' ) ) {
	/**
	 * Clear one or all filters between tests.
	 *
	 * @param string|null $hook Filter name or null for all.
	 * @return void
	 */
	function remove_all_filters( ?string $hook = null ): void {
		if ( null === $hook ) {
			$GLOBALS['specflux_site_agent_test_filters'] = array();
			return;
		}
		unset( $GLOBALS['specflux_site_agent_test_filters'][ $hook ] );
	}
}

// --- Minimal function shims (recording where useful) -----------------------

if ( ! function_exists( 'add_action' ) ) {
	$GLOBALS['specflux_site_agent_test_actions'] = array();

	/**
	 * Recording shim: $GLOBALS['specflux_site_agent_test_actions'][$hook][] = callback,
	 * and $GLOBALS['specflux_site_agent_test_action_priorities'][$hook][] = [callback, priority].
	 *
	 * @param mixed ...$args Hook args.
	 * @return bool
	 */
	function add_action( ...$args ): bool {
		$GLOBALS['specflux_site_agent_test_actions'][ (string) $args[0] ][]           = $args[1] ?? null;
		$GLOBALS['specflux_site_agent_test_action_priorities'][ (string) $args[0] ][] = array( $args[1] ?? null, (int) ( $args[2] ?? 10 ) );

		return true;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Identity shim.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	$GLOBALS['specflux_site_agent_test_user_caps'] = array();

	/**
	 * Test knob: $GLOBALS['specflux_site_agent_test_user_caps'][$cap] = bool. A
	 * per-object entry under "$cap:$object_id" wins when a call passes an id.
	 */
	function current_user_can( string $capability, mixed ...$args ): bool {
		if ( isset( $args[0] ) && isset( $GLOBALS['specflux_site_agent_test_user_caps'][ $capability . ':' . $args[0] ] ) ) {
			return (bool) $GLOBALS['specflux_site_agent_test_user_caps'][ $capability . ':' . $args[0] ];
		}

		return (bool) ( $GLOBALS['specflux_site_agent_test_user_caps'][ $capability ] ?? false );
	}
}

if ( ! function_exists( 'user_can' ) ) {
	/**
	 * Test knob: $GLOBALS['specflux_site_agent_test_user_caps_by_id'][$user_id][$cap] = bool.
	 * Falls back to the current-user knob when no per-user entry exists, so
	 * tests that only set current_user_can()'s knob keep working.
	 */
	function user_can( int $user_id, string $capability ): bool {
		if ( isset( $GLOBALS['specflux_site_agent_test_user_caps_by_id'][ $user_id ][ $capability ] ) ) {
			return (bool) $GLOBALS['specflux_site_agent_test_user_caps_by_id'][ $user_id ][ $capability ];
		}

		return (bool) ( $GLOBALS['specflux_site_agent_test_user_caps'][ $capability ] ?? false );
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	$GLOBALS['specflux_site_agent_test_user_meta'] = array();

	/**
	 * Test knob: $GLOBALS['specflux_site_agent_test_user_meta'][$user_id][$key] = mixed.
	 * `$single` is always honoured (the harness never reads a meta array).
	 */
	function get_user_meta( int $user_id, string $key = '', bool $single = false ) {
		unset( $single );

		return $GLOBALS['specflux_site_agent_test_user_meta'][ $user_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	/** Test knob: records the write in $GLOBALS['specflux_site_agent_test_user_meta']. */
	function update_user_meta( int $user_id, string $key, $value ) {
		$GLOBALS['specflux_site_agent_test_user_meta'][ $user_id ][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	/** Test knob: clears one meta key for one user. */
	function delete_user_meta( int $user_id, string $key ) {
		unset( $GLOBALS['specflux_site_agent_test_user_meta'][ $user_id ][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	/** Truncate shim. */
	function wp_trim_words( string $text, int $num_words = 55, string $more = '…' ): string {
		$trimmed = trim( $text );
		$words   = preg_split( '/\s+/', '' === $trimmed ? '0' : $trimmed );
		if ( count( $words ) <= $num_words ) {
			return trim( $text );
		}

		return implode( ' ', array_slice( $words, 0, $num_words ) ) . $more;
	}
}

if ( ! function_exists( 'absint' ) ) {
	/** Absint shim. */
	function absint( mixed $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_nonce_url' ) ) {
	/** Nonce-URL shim (test: deterministic suffix). */
	function wp_nonce_url( string $url, string $action ): string {
		return $url . '&_wpnonce=test-' . md5( $action );
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	/** No-op shim; $GLOBALS['specflux_site_agent_test_bad_nonce'] makes it die like core does. */
	function check_admin_referer( string $action ): bool {
		unset( $action );

		if ( ! empty( $GLOBALS['specflux_site_agent_test_bad_nonce'] ) ) {
			wp_die( 'The link you followed has expired.' );
		}

		return true;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	$GLOBALS['specflux_site_agent_test_redirect'] = '';

	/** Recording shim. */
	function wp_safe_redirect( string $location ): void {
		$GLOBALS['specflux_site_agent_test_redirect'] = $location;
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	/** Recording shim so tests can load the main plugin file. */
	function register_activation_hook( string $file, callable $callback ): void {
		$GLOBALS['specflux_site_agent_test_activation_hooks'][] = $callback;
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	/** Recording shim so tests can load the main plugin file (0.4 K4 registers a deactivation hook). */
	function register_deactivation_hook( string $file, callable $callback ): void {
		$GLOBALS['specflux_site_agent_test_deactivation_hooks'][] = $callback;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/** Recording shim: $GLOBALS['specflux_site_agent_test_rest_routes'][] = [namespace, route, args]. */
	function register_rest_route( string $route_namespace, string $route, array $args = array() ): bool {
		$GLOBALS['specflux_site_agent_test_rest_routes'][] = array( $route_namespace, $route, $args );

		return true;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	/** Toggle shim: tests set $GLOBALS['specflux_site_agent_test_multisite']. */
	function is_multisite(): bool {
		return ! empty( $GLOBALS['specflux_site_agent_test_multisite'] );
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	/** Identity-ish shim: strips nothing, tests don't depend on the shape. */
	function plugin_basename( string $file ): string {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

if ( ! function_exists( 'deactivate_plugins' ) ) {
	$GLOBALS['specflux_site_agent_test_deactivated_plugins'] = array();

	/** Recording shim: $GLOBALS['specflux_site_agent_test_deactivated_plugins'][]. */
	function deactivate_plugins( $plugins ): void {
		foreach ( (array) $plugins as $plugin ) {
			$GLOBALS['specflux_site_agent_test_deactivated_plugins'][] = $plugin;
		}
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/** Throwing shim so tests can observe a death. */
	function wp_die( string $message ): void {
		throw new RuntimeException( $message );
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Identity shim: picks singular/plural by count, no actual translation.
	 *
	 * @param string $single Singular text.
	 * @param string $plural Plural text.
	 * @param int    $number Count.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Identity shim.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/** Minimal shim (core also strips script/style bodies). */
	function wp_strip_all_tags( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/** Identity shim. */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/** Identity shim. */
	function esc_url( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Identity shim.
	 *
	 * @param mixed $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	$GLOBALS['specflux_site_agent_test_current_user_id'] = 0;

	/** Test control knob: set $GLOBALS['specflux_site_agent_test_current_user_id'] per-test. */
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['specflux_site_agent_test_current_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/** Minimal shim. */
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'menu_page_url' ) ) {
	/** Minimal shim: a page is "registered" when the test seeded its URL. */
	function menu_page_url( string $menu_slug, bool $display = true ): string {
		return (string) ( $GLOBALS['specflux_site_agent_test_menu_urls'][ $menu_slug ] ?? '' );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	$GLOBALS['specflux_site_agent_test_transients'] = array();

	/** Recording shim mirroring the options-table backing. */
	function get_transient( string $key ): mixed {
		return $GLOBALS['specflux_site_agent_test_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/** Recording shim. */
	function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['specflux_site_agent_test_transients'][ $key ]     = $value;
		$GLOBALS['specflux_site_agent_test_transient_ttls'][ $key ] = $expiration;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/** Recording shim. */
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['specflux_site_agent_test_transients'][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON shim.
	 *
	 * @param mixed $data  Data.
	 * @param int   $flags json_encode() flags (S23: PublicSurfaceTest writes
	 *                     a pretty-printed snapshot).
	 * @return string|false
	 */
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

// activation-probe.php defines this to leave dbDelta() undefined, as it is during real
// activation, so only Schema::install()'s own require_once can provide it.
if ( ! function_exists( 'dbDelta' ) && empty( $GLOBALS['specflux_site_agent_test_lazy_dbdelta'] ) ) {
	$GLOBALS['specflux_site_agent_test_dbdelta_queries'] = array();

	/**
	 * Recording shim: captures statements instead of touching a database.
	 *
	 * @param string|list<string> $queries SQL statements.
	 * @return array<string,string>
	 */
	function dbDelta( $queries = '', bool $execute = true ): array {
		foreach ( (array) $queries as $query ) {
			$GLOBALS['specflux_site_agent_test_dbdelta_queries'][] = (string) $query;
		}

		return array();
	}
}

if ( ! function_exists( 'site_url' ) ) {
	/** Test site URL; override with $GLOBALS['specflux_site_agent_test_site_url']. */
	function site_url( string $path = '' ): string {
		return ( $GLOBALS['specflux_site_agent_test_site_url'] ?? 'http://example.test' ) . $path;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	$GLOBALS['specflux_site_agent_test_options'] = array();

	/** Test knob: options backed by an in-memory array. */
	function get_option( string $option, mixed $fallback = false ): mixed {
		return $GLOBALS['specflux_site_agent_test_options'][ $option ] ?? $fallback;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/** Test knob: options backed by an in-memory array. */
	function update_option( string $option, mixed $value, ?bool $autoload = null ): bool {
		$GLOBALS['specflux_site_agent_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/** Test knob: options backed by an in-memory array. */
	function delete_option( string $option ): bool {
		if ( ! array_key_exists( $option, $GLOBALS['specflux_site_agent_test_options'] ) ) {
			return false; // Like core: nothing deleted, nothing to report.
		}
		unset( $GLOBALS['specflux_site_agent_test_options'][ $option ] );

		return true;
	}
}

// 0.3 S11 follow-up: `Plugin::start()` now asks the SAME `specflux/provider`
// harness check the setup panel renders (Checks::providerCheck()). Most of
// the suite starts runs without ever configuring a model provider and is not
// testing that check at all, so the suite-wide default is "configured" —
// exactly like `Plugin::set_dependency_probe()` already defaults Agent Safety
// presence per test. Tests that ARE about the provider check (tests/Setup/ChecksTest.php,
// tests/Admin/RunsScreenTest.php, tests/PluginProviderCheckTest.php) override
// this explicitly and restore it to `true` (never to `null`, which would fall
// through to the real, unconfigured `wordpress/php-ai-client` registry and
// strand every OTHER test file that runs afterwards in the same process).
\Specflux\SiteAgent\Setup\Checks::setProviderProbe( true );

// K1: the connector resolves the request's connection from core's REST
// auth. Test knob: $GLOBALS['specflux_site_agent_test_app_password'] is the application
// password UUID the request authenticated with (null = a cookie session).
if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
	/** Knob-backed stand-in for core's helper. */
	function rest_get_authenticated_app_password(): ?string {
		$uuid = $GLOBALS['specflux_site_agent_test_app_password'] ?? null;

		return is_string( $uuid ) ? $uuid : null;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/** Identity-ish shim. */
	function rest_url( string $path = '' ): string {
		return 'http://example.test/wp-json/' . ltrim( $path, '/' );
	}
}
