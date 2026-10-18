<?php
/**
 * Site Agent -> AI apps: connect an app, see who is signed in, approve waiting calls.
 *
 * One tab that replaces three screens (Connect an AI app, Signed-in apps and the
 * Runs screen's "From AI apps" tab). Every action on it posts to the handler it
 * always had: the connector switch to Settings' `save_connector`, connections to
 * {@see ConnectScreen}, sign-ins to {@see OAuth\SignedInApps}, waiting calls to
 * {@see ApprovalsScreen}. Nothing here changes what those handlers check.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector;

use Specflux\SiteAgent\Admin\Ui\Kit;
use Specflux\SiteAgent\Admin\Ui\Shell;
use Specflux\SiteAgent\Connector\OAuth\SignedInApps;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/** Renders the AI apps tab. */
final class AppsScreen {

	/** Display-only flash after the connector switch is saved. */
	public const SAVED_FLAG = 'specflux_site_agent_connector_saved';

	/** Render the tab. */
	public static function render(): void {
		if ( ! current_user_can( Connector::capability() ) ) {
			return;
		}

		$reason = ConnectScreen::unavailableReason();
		$shown  = null === $reason ? ConnectScreen::handlePost() : null;
		$ready  = Connector::adapterReady() && ! \Specflux\SiteAgent\MultisiteGuard::refused();

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Kit::icon() and the sprite are escaped markup; everything else is escaped where it is printed.
		Shell::open( 'apps' );
		echo '<div class="aui-layout"><div class="aui-stack">';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
		if ( isset( $_GET[ self::SAVED_FLAG ] ) ) {
			echo '<div class="aui-notice aui-notice--success" role="status">' . Kit::icon( 'check' ) . '<div>' . esc_html__( 'Saved.', 'specflux-site-agent' ) . '</div></div>';
		}
		if ( is_array( $shown ) ) {
			ConnectScreen::renderCreated( $shown );
		} elseif ( $shown instanceof WP_Error ) {
			echo '<div class="aui-notice aui-notice--error" role="alert">' . Kit::icon( 'x' ) . '<div>' . esc_html( $shown->get_error_message() ) . '</div></div>';
		}

		self::renderConnectorCard( $ready );

		if ( null !== $reason ) {
			if ( $ready && ! Connector::stored() ) {
				self::renderOffState( $reason );
			} else {
				echo '<div class="aui-notice aui-notice--warning" role="status">' . Kit::icon( 'warning' ) . '<div>' . wp_kses( $reason, array( 'a' => array( 'href' => array() ) ) ) . '</div></div>';
			}
		}

		$waiting = Approvals::pending();
		if ( array() !== $waiting ) {
			ApprovalsScreen::render();
		}
		if ( null === $reason ) {
			ConnectScreen::renderCards();
		}
		ConnectScreen::renderConnections();
		SignedInApps::render();
		if ( array() === $waiting ) {
			ApprovalsScreen::render();
		}
		ConnectScreen::renderActivity();

		echo '</div>';
		self::renderRail();
		echo '</div>';
		Shell::close();
		// phpcs:enable
	}

	/** The connector on/off card: a form whose switch posts to the existing save handler. */
	private static function renderConnectorCard( bool $ready ): void {
		$on = Connector::stored();

		echo '<section class="aui-card"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aui-card-b aui-row-flex">';
		echo '<span class="aui-tile">' . Kit::icon( 'plug', 'aui-i--lg' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
		echo '<div class="aui-grow"><h2 class="aui-card-t">' . esc_html__( 'Let AI apps connect to this site', 'specflux-site-agent' ) . '</h2>';
		echo '<p class="aui-card-d">' . esc_html__( 'Lets apps such as Claude, ChatGPT or Cursor use Specflux Site Agent\'s tools, each through an application password or a sign-in you approve. Off until you turn it on. Turn it off to disconnect everyone at once.', 'specflux-site-agent' ) . '</p>';
		if ( ! $ready ) {
			$version = Connector::adapterVersion();
			echo '<p class="aui-help">' . esc_html(
				null === $version
					? sprintf(
						/* translators: %s: the minimum MCP Adapter version. */
						__( 'This needs the MCP Adapter plugin (version %s or newer) from WordPress.org. Install and activate it to turn this on.', 'specflux-site-agent' ),
						Connector::MIN_ADAPTER_VERSION
					)
					: sprintf(
						/* translators: 1: installed MCP Adapter version, 2: minimum version. */
						__( 'The MCP Adapter plugin is version %1$s; Specflux Site Agent needs %2$s or newer. Update it to turn this on.', 'specflux-site-agent' ),
						$version,
						Connector::MIN_ADAPTER_VERSION
					)
			) . '</p>';
		}
		echo '</div>';
		echo '<input type="hidden" name="action" value="specflux_site_agent_save_connector">';
		// The switch is a submit button: this posts the OPPOSITE of the stored state, so it also works without JavaScript.
		printf( '<input type="hidden" name="%s" value="%s">', esc_attr( Connector::OPTION ), $on ? '0' : '1' );
		wp_nonce_field( 'specflux_site_agent_save_connector' );
		printf(
			'<button type="submit" class="aui-switch" aria-pressed="%1$s" aria-label="%2$s"%3$s><span class="aui-switch-track"></span><span class="aui-switch-state" data-on="%4$s" data-off="%5$s">%6$s</span></button>',
			$on ? 'true' : 'false',
			esc_attr__( 'Let AI apps connect to this site', 'specflux-site-agent' ),
			disabled( $ready, false, false ),
			esc_attr__( 'On', 'specflux-site-agent' ),
			esc_attr__( 'Off', 'specflux-site-agent' ),
			esc_html( $on ? __( 'On', 'specflux-site-agent' ) : __( 'Off', 'specflux-site-agent' ) )
		);
		echo '</form></section>';
	}

	/** The empty state shown while the connector is off. */
	private static function renderOffState( string $reason ): void {
		echo '<section class="aui-card"><div class="aui-empty"><span class="aui-tile">' . Kit::icon( 'plug', 'aui-i--lg' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
		echo '<h2 class="aui-card-t">' . esc_html__( 'No AI apps connected yet', 'specflux-site-agent' ) . '</h2>';
		echo '<p>' . esc_html( wp_strip_all_tags( $reason ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="specflux_site_agent_save_connector">';
		printf( '<input type="hidden" name="%s" value="1">', esc_attr( Connector::OPTION ) );
		wp_nonce_field( 'specflux_site_agent_save_connector' );
		echo '<button type="submit" class="aui-btn aui-btn--primary">' . esc_html__( 'Turn on connector', 'specflux-site-agent' ) . '</button></form>';
		echo '</div></section>';
	}

	/** The right rail: the MCP URL, recent activity and help. */
	private static function renderRail(): void {
		$url = ConnectScreen::serverUrl();

		echo '<aside class="aui-rail" aria-label="' . esc_attr__( 'Sidebar', 'specflux-site-agent' ) . '">';
		echo '<section class="aui-card"><div class="aui-card-h"><h2 class="aui-card-t">' . esc_html__( 'MCP URL', 'specflux-site-agent' ) . '</h2></div><div class="aui-card-b" style="display:flex;flex-direction:column;gap:12px">';
		echo '<div class="aui-copy"><label class="aui-sr" for="specflux-site-agent-mcp-url">' . esc_html__( 'MCP URL', 'specflux-site-agent' ) . '</label>';
		echo '<input class="aui-input" id="specflux-site-agent-mcp-url" readonly value="' . esc_attr( $url ) . '" title="' . esc_attr( $url ) . '">';
		echo '<button type="button" class="aui-btn aui-btn--secondary aui-btn--sm" data-aui-copy data-aui-target="#specflux-site-agent-mcp-url" data-copied="' . esc_attr__( 'Copied', 'specflux-site-agent' ) . '">' . Kit::icon( 'copy' ) . '<span data-aui-copy-label>' . esc_html__( 'Copy', 'specflux-site-agent' ) . '</span></button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
		echo '<p class="aui-help">' . esc_html__( 'Paste this into any AI app that supports custom connectors.', 'specflux-site-agent' ) . '</p></div></section>';

		echo '<section class="aui-card"><div class="aui-card-h"><h2 class="aui-card-t">' . esc_html__( 'Recent activity', 'specflux-site-agent' ) . '</h2></div><div class="aui-card-b">';
		ConnectScreen::renderFeed();
		echo '</div></section>';

		$help = array(
			array( 'book', __( 'Connect Claude', 'specflux-site-agent' ), 'https://claude.com/docs/connectors/custom/remote-mcp' ),
			array( 'book', __( 'Connect ChatGPT', 'specflux-site-agent' ), 'https://developers.openai.com/apps-sdk/deploy/connect-chatgpt' ),
			array( 'lifebuoy', __( 'Report a problem', 'specflux-site-agent' ), 'https://github.com/specflux/specflux-site-agent/issues' ),
		);
		echo '<section class="aui-card"><div class="aui-card-h"><h2 class="aui-card-t">' . esc_html__( 'Help', 'specflux-site-agent' ) . '</h2></div><div class="aui-card-b"><ul class="aui-links">';
		foreach ( $help as $link ) {
			echo '<li><a href="' . esc_url( $link[2] ) . '" target="_blank" rel="noopener noreferrer">' . Kit::icon( $link[0] ) . '<span>' . esc_html( $link[1] ) . '</span>' . Kit::icon( 'arrow-square-out', 'aui-end' ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
		}
		echo '</ul></div></section></aside>';
	}
}
