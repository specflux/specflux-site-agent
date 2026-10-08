<?php
/**
 * The "Connect an AI app" screen (K3).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector;

use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\MultisiteGuard;
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

	/** Submenu slug. */
	public const SLUG = 'senroflux-connect';

	/** Nonce action for the create / test forms. */
	public const NONCE_FORM = 'senroflux_connect_form';

	/** Nonce action prefix for the per-connection buttons; the UUID is appended. */
	public const NONCE_CONNECTION = 'senroflux_connection_';

	/** Hook the menu and the two admin-post handlers. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_senroflux_connection_scope', array( self::class, 'handleScope' ) );
		add_action( 'admin_post_senroflux_connection_revoke', array( self::class, 'handleRevoke' ) );
	}

	/** The submenu under SenroFlux. */
	public static function menu(): void {
		add_submenu_page(
			RunsScreen::SLUG,
			__( 'Connect an AI app', 'senroflux' ),
			__( 'Connect an AI app', 'senroflux' ),
			Connector::capability(),
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/** This screen's URL. */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/** The connector's server URL. */
	public static function serverUrl(): string {
		return rest_url( Connector::ROUTE_NAMESPACE . '/' . Connector::ROUTE );
	}

	/** @return array<string,string> client id => label, for the app-password cards. */
	public static function clientLabels(): array {
		return array(
			ClientConfig::CLAUDE_CODE    => __( 'Claude Code', 'senroflux' ),
			ClientConfig::CURSOR         => __( 'Cursor', 'senroflux' ),
			ClientConfig::VS_CODE        => __( 'VS Code', 'senroflux' ),
			ClientConfig::CLAUDE_DESKTOP => __( 'Claude Desktop (local proxy)', 'senroflux' ),
			ClientConfig::OTHER          => __( 'Other (copy the URL)', 'senroflux' ),
		);
	}

	/**
	 * Why the screen is not usable, as a sentence with the fix; null when it is.
	 */
	public static function unavailableReason(): ?string {
		if ( MultisiteGuard::refused() ) {
			return __( 'SenroFlux does not support WordPress multisite, so AI apps cannot connect to this site.', 'senroflux' );
		}
		if ( ! Connector::stored() ) {
			return sprintf(
				/* translators: %s: link to the settings screen. */
				__( 'AI apps are switched off. Turn on "Allow AI apps to connect" under %s first.', 'senroflux' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=senroflux-settings' ) ) . '">' . esc_html__( 'SenroFlux Settings', 'senroflux' ) . '</a>'
			);
		}
		$version = Connector::adapterVersion();
		if ( null === $version ) {
			return sprintf(
				/* translators: %s: the minimum MCP Adapter version. */
				esc_html__( 'The MCP Adapter plugin is not installed. Install and activate version %s or newer from WordPress.org.', 'senroflux' ),
				esc_html( Connector::MIN_ADAPTER_VERSION )
			);
		}
		if ( ! Connector::adapterReady() ) {
			return sprintf(
				/* translators: 1: installed MCP Adapter version, 2: minimum version. */
				esc_html__( 'The MCP Adapter plugin is version %1$s; SenroFlux needs %2$s or newer. Update it.', 'senroflux' ),
				esc_html( $version ),
				esc_html( Connector::MIN_ADAPTER_VERSION )
			);
		}

		return null;
	}

	/** Render the screen. */
	public static function render(): void {
		if ( ! current_user_can( Connector::capability() ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Connect an AI app', 'senroflux' ) . '</h1>';

		$reason = self::unavailableReason();
		if ( null !== $reason ) {
			echo '<div class="notice notice-warning inline"><p>' . wp_kses( $reason, array( 'a' => array( 'href' => array() ) ) ) . '</p></div></div>';

			return;
		}

		$shown = self::handlePost();
		if ( is_array( $shown ) ) {
			self::renderCreated( $shown );
		} elseif ( $shown instanceof WP_Error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $shown->get_error_message() ) . '</p></div>';
		}

		self::renderCards();
		self::renderConnections();
		echo '</div>';
	}

	/**
	 * Handle the create / test POST done by this page itself.
	 *
	 * @return array{client:string,uuid:string,password:string,scope:string,test:?array{ok:bool,category:string,message:string,tools:int}}|WP_Error|null
	 */
	private static function handlePost(): array|WP_Error|null {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() just below.
		$action = isset( $_POST['senroflux_connect_action'] ) && is_string( $_POST['senroflux_connect_action'] )
			? sanitize_key( wp_unslash( $_POST['senroflux_connect_action'] ) )
			: '';
		if ( ! in_array( $action, array( 'create', 'test' ), true ) ) {
			return null;
		}
		check_admin_referer( self::NONCE_FORM );

		$client = isset( $_POST['client'] ) && is_string( $_POST['client'] ) ? sanitize_key( wp_unslash( $_POST['client'] ) ) : '';
		if ( ! ClientConfig::known( $client ) ) {
			return new WP_Error( 'senroflux_connect_client', __( 'Pick an app from the list.', 'senroflux' ) );
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
			return new WP_Error( 'senroflux_connect_test', __( 'That connection no longer exists.', 'senroflux' ) );
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
			return new WP_Error( 'senroflux_connect_unavailable', __( 'Application passwords are turned off for your account, so no connection can be made. Your host or another plugin has disabled them.', 'senroflux' ) );
		}

		$made = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => 'SenroFlux – ' . ( self::clientLabels()[ $client ] ?? $client ) )
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
	private static function renderCreated( array $shown ): void {
		$user   = get_userdata( get_current_user_id() );
		$login  = is_object( $user ) ? (string) $user->user_login : '';
		$config = ClientConfig::render( $shown['client'], self::serverUrl(), $login, $shown['password'] );

		echo '<div class="notice notice-success inline"><p><strong>' . esc_html__( 'Connection created.', 'senroflux' ) . '</strong> ';
		echo esc_html__( 'The password below is shown once. Copy the config now: it cannot be shown again, only replaced by a new connection.', 'senroflux' ) . '</p></div>';
		echo '<p>' . esc_html( $config['where'] ) . '</p>';
		echo '<pre id="senroflux-config" style="background:#fff;border:1px solid #c3c4c7;padding:12px;white-space:pre-wrap;word-break:break-all">' . esc_html( $config['text'] ) . '</pre>';
		echo '<p><button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById(\'senroflux-config\').textContent)">' . esc_html__( 'Copy', 'senroflux' ) . '</button></p>';

		if ( is_array( $shown['test'] ) ) {
			printf(
				'<div class="notice %s inline"><p>%s</p></div>',
				$shown['test']['ok'] ? 'notice-success' : 'notice-error',
				esc_html( $shown['test']['message'] )
			);
		}

		echo '<form method="post" action="' . esc_url( self::url() ) . '">';
		wp_nonce_field( self::NONCE_FORM );
		echo '<input type="hidden" name="senroflux_connect_action" value="test">';
		printf( '<input type="hidden" name="client" value="%s">', esc_attr( $shown['client'] ) );
		printf( '<input type="hidden" name="uuid" value="%s">', esc_attr( $shown['uuid'] ) );
		printf( '<input type="hidden" name="password" value="%s">', esc_attr( $shown['password'] ) );
		echo '<button type="submit" class="button">' . esc_html__( 'Test connection', 'senroflux' ) . '</button> ';
		echo '<span class="description">' . esc_html__( 'Makes two requests from this site to its own address, with this connection\'s password.', 'senroflux' ) . '</span>';
		echo '</form><hr>';
	}

	/** The client cards. */
	private static function renderCards(): void {
		echo '<p>' . esc_html__( 'Pick the app you use. SenroFlux makes a WordPress application password for you, named after the app, and shows the exact setup text to paste in. Read only is the safe default: the app can look but not change anything.', 'senroflux' ) . '</p>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;max-width:1000px">';

		foreach ( self::clientLabels() as $client => $label ) {
			echo '<div class="card" style="margin:0"><h2 style="margin-top:0">' . esc_html( $label ) . '</h2>';
			echo '<form method="post" action="' . esc_url( self::url() ) . '">';
			wp_nonce_field( self::NONCE_FORM );
			echo '<input type="hidden" name="senroflux_connect_action" value="create">';
			printf( '<input type="hidden" name="client" value="%s">', esc_attr( $client ) );
			echo '<p><label>' . esc_html__( 'Access', 'senroflux' ) . ' <select name="scope">';
			printf( '<option value="%s">%s</option>', esc_attr( Connection::SCOPE_READ ), esc_html__( 'Read only', 'senroflux' ) );
			printf( '<option value="%s">%s</option>', esc_attr( Connection::SCOPE_READ_WRITE ), esc_html__( 'Read and write', 'senroflux' ) );
			echo '</select></label></p>';
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create connection', 'senroflux' ) . '</button></p>';
			echo '</form></div>';
		}

		foreach ( array(
			__( 'Claude (web, desktop and mobile)', 'senroflux' ),
			__( 'ChatGPT', 'senroflux' ),
		) as $label ) {
			echo '<div class="card" style="margin:0;opacity:.7"><h2 style="margin-top:0">' . esc_html( $label ) . '</h2>';
			echo '<p>' . esc_html__( 'Coming soon: needs sign-in support.', 'senroflux' ) . '</p></div>';
		}

		echo '</div>';
	}

	/** The current user's connections. */
	private static function renderConnections(): void {
		$user_id  = get_current_user_id();
		$registry = Connections::forUser( $user_id );
		$core     = array();
		foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( is_array( $item ) && isset( $item['uuid'] ) ) {
				$core[ (string) $item['uuid'] ] = $item;
			}
		}

		echo '<h2>' . esc_html__( 'Your connections', 'senroflux' ) . '</h2>';
		$rows = array_intersect_key( $registry, $core );
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No connections yet.', 'senroflux' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Connection', 'senroflux' ), __( 'Access', 'senroflux' ), __( 'Created', 'senroflux' ), __( 'Last used', 'senroflux' ), '' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $uuid => $entry ) {
			$item = $core[ $uuid ];
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $item['name'] ?? '' ) ) . ' <code>' . esc_html( substr( $uuid, 0, 8 ) ) . '</code></td>';
			echo '<td>' . esc_html( Connection::SCOPE_READ_WRITE === $entry['scope'] ? __( 'Read and write', 'senroflux' ) : __( 'Read only', 'senroflux' ) ) . '</td>';
			echo '<td>' . esc_html( (string) wp_date( 'Y-m-d H:i', (int) ( $item['created'] ?? 0 ) ) ) . '</td>';
			$used = (int) ( $item['last_used'] ?? 0 );
			echo '<td>' . esc_html( $used > 0 ? (string) wp_date( 'Y-m-d H:i', $used ) : __( 'Never', 'senroflux' ) ) . '</td>';
			echo '<td>';
			self::renderConnectionForm( 'senroflux_connection_scope', $uuid, Connection::SCOPE_READ_WRITE === $entry['scope'] ? Connection::SCOPE_READ : Connection::SCOPE_READ_WRITE, Connection::SCOPE_READ_WRITE === $entry['scope'] ? __( 'Make read only', 'senroflux' ) : __( 'Allow writes', 'senroflux' ) );
			self::renderConnectionForm( 'senroflux_connection_revoke', $uuid, '', __( 'Revoke', 'senroflux' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** One per-connection button. */
	private static function renderConnectionForm( string $action, string $uuid, string $scope, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:4px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="uuid" value="' . esc_attr( $uuid ) . '">';
		if ( '' !== $scope ) {
			echo '<input type="hidden" name="scope" value="' . esc_attr( $scope ) . '">';
		}
		wp_nonce_field( self::NONCE_CONNECTION . $uuid );
		printf( '<button type="submit" class="button">%s</button>', esc_html( $label ) );
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
			wp_die( esc_html__( 'Insufficient permissions.', 'senroflux' ) );
		}

		return isset( Connections::forUser( get_current_user_id() )[ $uuid ] ) ? $uuid : null;
	}
}
