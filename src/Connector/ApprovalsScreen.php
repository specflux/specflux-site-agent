<?php
/**
 * The "From AI apps" tab: parked connector calls waiting for an admin (K2).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector;

use Specflux\SenroFlux\Admin\RunsScreen;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Lists the calls AI apps parked in built-in mode and lets an admin approve or
 * deny each with one click. Both buttons post to admin-post with a per-call
 * nonce and re-check the connector capability; nothing here trusts the page
 * the form came from.
 */
final class ApprovalsScreen {

	/** The `tab` query value that selects this view. */
	public const TAB = 'ai-apps';

	/** Nonce action prefix; the call's hash is appended. */
	public const NONCE_PREFIX = 'senroflux_connector_decision_';

	/** Hook the two admin-post handlers. */
	public static function register(): void {
		add_action( 'admin_post_senroflux_connector_approve', array( self::class, 'handleApprove' ) );
		add_action( 'admin_post_senroflux_connector_deny', array( self::class, 'handleDeny' ) );
	}

	/** Where the agent is told to send the site owner. */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . RunsScreen::SLUG . '&tab=' . self::TAB );
	}

	/** Is the current request asking for this tab? */
	public static function requested(): bool {
		return isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) && self::TAB === sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
	}

	/** admin-post: approve one parked call. */
	public static function handleApprove(): void {
		self::decide( true );
	}

	/** admin-post: deny one parked call. */
	public static function handleDeny(): void {
		self::decide( false );
	}

	/**
	 * Shared handler body: nonce, capability, then the decision.
	 *
	 * @param bool $approve True to approve, false to deny.
	 */
	private static function decide( bool $approve ): void {
		$hash = isset( $_POST['hash'] ) && is_string( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		check_admin_referer( self::NONCE_PREFIX . $hash );

		if ( ! current_user_can( Connector::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'senroflux' ) );
		}

		if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			$approve ? Approvals::approve( $hash ) : Approvals::deny( $hash );
		}

		wp_safe_redirect( self::url() );
	}

	/** The tab links, shown once the connector is on or something is waiting. */
	public static function renderTabs(): void {
		if ( ! Connector::stored() && array() === Approvals::pending() ) {
			return;
		}

		$count = count( Approvals::pending() );
		echo '<nav class="nav-tab-wrapper">';
		printf(
			'<a href="%s" class="nav-tab%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . RunsScreen::SLUG ) ),
			self::requested() ? '' : ' nav-tab-active',
			esc_html__( 'Runs', 'senroflux' )
		);
		printf(
			'<a href="%s" class="nav-tab%s">%s</a>',
			esc_url( self::url() ),
			self::requested() ? ' nav-tab-active' : '',
			esc_html( $count > 0 ? sprintf( /* translators: %d: calls waiting. */ __( 'From AI apps (%d)', 'senroflux' ), $count ) : __( 'From AI apps', 'senroflux' ) )
		);
		echo '</nav>';
	}

	/** Render the tab body (the caller prints the page chrome). */
	public static function render(): void {
		echo '<h2>' . esc_html__( 'From AI apps', 'senroflux' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Changes that AI apps asked for and are waiting on you. Approve one and the app can repeat the same request within 15 minutes; it runs once.', 'senroflux' ) . '</p>';

		$pending = Approvals::pending();
		if ( array() === $pending ) {
			echo '<p>' . esc_html__( 'Nothing is waiting.', 'senroflux' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Asked', 'senroflux' ), __( 'User', 'senroflux' ), __( 'Connection', 'senroflux' ), __( 'Tool', 'senroflux' ), __( 'Arguments', 'senroflux' ), '' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $pending as $hash => $entry ) {
			self::renderRow( $hash, $entry );
		}
		echo '</tbody></table>';
	}

	/**
	 * One pending call.
	 *
	 * @param string                                                                                                  $hash  The call's hash.
	 * @param array{created:int,user:int,uuid:string,ability:string,args:array<string,mixed>,approved:int} $entry The entry.
	 */
	private static function renderRow( string $hash, array $entry ): void {
		$user = get_userdata( $entry['user'] );
		$name = is_object( $user ) && isset( $user->user_login ) ? (string) $user->user_login : '#' . $entry['user'];
		$args = (string) wp_json_encode( $entry['args'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		echo '<tr>';
		echo '<td>' . esc_html( (string) wp_date( 'Y-m-d H:i', $entry['created'] ) ) . '</td>';
		echo '<td>' . esc_html( $name ) . '</td>';
		echo '<td><code>' . esc_html( substr( $entry['uuid'], 0, 8 ) ) . '</code></td>';
		echo '<td><code>' . esc_html( $entry['ability'] ) . '</code></td>';
		echo '<td><pre style="margin:0;white-space:pre-wrap">' . esc_html( $args ) . '</pre></td>';
		echo '<td>';
		self::renderButton( 'senroflux_connector_approve', __( 'Approve', 'senroflux' ), $hash, true );
		self::renderButton( 'senroflux_connector_deny', __( 'Deny', 'senroflux' ), $hash, false );
		echo '</td></tr>';
	}

	/** One admin-post button. */
	private static function renderButton( string $action, string $label, string $hash, bool $primary ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:4px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="hash" value="' . esc_attr( $hash ) . '">';
		wp_nonce_field( self::NONCE_PREFIX . $hash );
		printf( '<button type="submit" class="button%s">%s</button>', $primary ? ' button-primary' : '', esc_html( $label ) );
		echo '</form>';
	}
}
