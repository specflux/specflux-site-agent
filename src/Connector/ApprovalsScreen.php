<?php
/**
 * The "Waiting for approval" section of the AI apps tab: parked connector calls
 * waiting for an admin (K2).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector;

use Specflux\SiteAgent\Admin\Ui\Kit;
use Specflux\SiteAgent\Admin\Ui\Shell;
use Specflux\SiteAgent\Tools\AbilityLabels;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Lists the calls AI apps parked in built-in mode and lets an admin approve or
 * deny each with one click. Both buttons post to admin-post with a per-call
 * nonce and re-check the connector capability; nothing here trusts the page
 * the form came from.
 */
final class ApprovalsScreen {

	/** The `tab` query value the Runs screen used for this view before the AI apps tab existed. */
	public const TAB = Shell::LEGACY_APPROVALS_TAB;

	/** Nonce action prefix; the call's hash is appended. */
	public const NONCE_PREFIX = 'specflux_site_agent_connector_decision_';

	/** Hook the two admin-post handlers. */
	public static function register(): void {
		add_action( 'admin_post_specflux_site_agent_connector_approve', array( self::class, 'handleApprove' ) );
		add_action( 'admin_post_specflux_site_agent_connector_deny', array( self::class, 'handleDeny' ) );
	}

	/** Where the agent is told to send the site owner: the waiting-calls section of the AI apps tab. */
	public static function url(): string {
		return Shell::url( 'apps', array(), Shell::SECTION_WAITING );
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
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}

		if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			$approve ? Approvals::approve( $hash ) : Approvals::deny( $hash );
		}

		wp_safe_redirect( self::url() );
	}

	/** Render the section: one card per waiting call, with who, what, why and the two buttons. */
	public static function render(): void {
		$pending = Approvals::pending();

		echo '<section id="' . esc_attr( Shell::SECTION_WAITING ) . '" class="specflux-site-agent-waiting" aria-labelledby="specflux-site-agent-waiting-t"><div class="aui-row-flex"><div class="aui-grow"><h2 class="aui-card-t" id="specflux-site-agent-waiting-t">' . esc_html__( 'Waiting for approval', 'specflux-site-agent' ) . '</h2>';
		echo '<p class="aui-card-d">' . esc_html__( 'Changes that AI apps asked for and are waiting on you. Approve one and the app can repeat the same request within 15 minutes; it runs once.', 'specflux-site-agent' ) . '</p></div>';
		if ( array() !== $pending ) {
			echo '<span class="aui-count aui-count--warn">' . esc_html( (string) count( $pending ) ) . '</span>';
		}
		echo '</div>';

		if ( array() === $pending ) {
			echo '<p class="aui-muted">' . esc_html__( 'Nothing is waiting.', 'specflux-site-agent' ) . '</p></section>';

			return;
		}

		foreach ( $pending as $hash => $entry ) {
			self::renderCard( $hash, $entry );
		}
		echo '</section>';
	}

	/**
	 * One pending call as a card.
	 *
	 * @param string                                                                                                  $hash  The call's hash.
	 * @param array{created:int,user:int,uuid:string,ability:string,args:array<string,mixed>,approved:int} $entry The entry.
	 */
	private static function renderCard( string $hash, array $entry ): void {
		$user  = get_userdata( $entry['user'] );
		$name  = is_object( $user ) && isset( $user->user_login ) ? (string) $user->user_login : '#' . $entry['user'];
		$app   = ConnectScreen::connectionLabel( $entry['uuid'] );
		$label = AbilityLabels::display( $entry['ability'] );
		$args  = (string) wp_json_encode( $entry['args'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		echo '<article class="aui-card specflux-site-agent-waiting-card"><div class="aui-card-b" style="display:flex;flex-direction:column;gap:12px">';
		echo '<div class="aui-row-flex"><span class="aui-tile aui-tile--warn">' . Kit::icon( 'clock', 'aui-i--lg' ) . '</span><div class="aui-grow">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
		echo '<h3 class="aui-approval-sum">' . esc_html( $label ) . '</h3>';
		echo '<span class="aui-mono aui-muted">' . esc_html( $entry['ability'] ) . '</span></div>';
		echo '<span class="aui-badge aui-badge--warn">' . esc_html__( 'Needs approval', 'specflux-site-agent' ) . '</span></div>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: app name, 2: WordPress user name, 3: ability label. */
				__( '%1$s, signed in as %2$s, wants to: %3$s.', 'specflux-site-agent' ),
				$app,
				$name,
				$label
			)
		) . '</p>';
		echo '<p class="aui-muted">' . esc_html__( 'Held because it can change your site. Nothing happens unless you approve.', 'specflux-site-agent' ) . ' <span class="aui-mono">' . esc_html( (string) wp_date( 'Y-m-d H:i', $entry['created'] ) ) . '</span></p>';
		echo '<details class="specflux-site-agent-details"><summary>' . esc_html__( 'Details', 'specflux-site-agent' ) . '</summary><pre class="specflux-site-agent-pre">' . esc_html( $args ) . '</pre></details>';
		echo '</div><div class="aui-card-f">';
		self::renderButton( 'specflux_site_agent_connector_approve', __( 'Approve', 'specflux-site-agent' ), $hash, true );
		self::renderButton( 'specflux_site_agent_connector_deny', __( 'Deny', 'specflux-site-agent' ), $hash, false );
		echo '</div></article>';
	}

	/** One admin-post button. */
	private static function renderButton( string $action, string $label, string $hash, bool $primary ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="hash" value="' . esc_attr( $hash ) . '">';
		wp_nonce_field( self::NONCE_PREFIX . $hash );
		printf( '<button type="submit" class="aui-btn aui-btn--sm %s">%s</button>', $primary ? 'aui-btn--primary' : 'aui-btn--secondary', esc_html( $label ) );
		echo '</form>';
	}
}
