<?php
/**
 * Admin page: apps signed in over OAuth, and registered apps.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector\OAuth;

use Specflux\SiteAgent\Admin\Ui\Kit;
use Specflux\SiteAgent\Admin\Ui\Shell;
use Specflux\SiteAgent\Connector\Connector;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/** Lists signed-in grants and registered clients; revokes either. Every action is nonce- and capability-checked. */
final class SignedInApps {

	/** The old submenu slug; it redirects to the signed-in section of the AI apps tab. */
	public const SLUG = Shell::LEGACY_SIGNED_IN;

	public const NONCE_REVOKE = 'specflux_site_agent_oauth_revoke_';
	public const NONCE_REMOVE = 'specflux_site_agent_oauth_remove_';

	public static function register(): void {
		add_action( 'admin_post_specflux_site_agent_oauth_revoke', array( self::class, 'handleRevoke' ) );
		add_action( 'admin_post_specflux_site_agent_oauth_remove', array( self::class, 'handleRemove' ) );
	}

	/** The signed-in section of the AI apps tab. */
	public static function url(): string {
		return Shell::url( 'apps', array(), Shell::SECTION_SIGNED_IN );
	}

	/** admin-post: revoke one signed-in grant. */
	public static function handleRevoke(): void {
		$id = isset( $_POST['grant'] ) && is_string( $_POST['grant'] ) ? sanitize_key( wp_unslash( $_POST['grant'] ) ) : '';
		check_admin_referer( self::NONCE_REVOKE . $id );
		if ( ! current_user_can( Connector::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}
		Store::revokeGrant( $id );
		wp_safe_redirect( self::url() );
	}

	/** admin-post: remove a registered app and everything signed in through it. */
	public static function handleRemove(): void {
		$id = isset( $_POST['client'] ) && is_string( $_POST['client'] ) ? sanitize_text_field( wp_unslash( $_POST['client'] ) ) : '';
		check_admin_referer( self::NONCE_REMOVE . $id );
		if ( ! current_user_can( Connector::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}
		if ( null !== Store::client( $id ) ) {
			Store::deleteClient( $id );
		}
		wp_safe_redirect( self::url() );
	}

	/** Render the two cards: apps signed in now, and apps that registered. */
	public static function render(): void {
		if ( ! current_user_can( Connector::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped inline; button() and Kit::icon() return escaped markup.
		$grants = Store::grantIndex();
		echo '<section class="aui-card" id="' . esc_attr( Shell::SECTION_SIGNED_IN ) . '"><div class="aui-card-h"><div><h2 class="aui-card-t">' . esc_html__( 'Signed-in apps', 'specflux-site-agent' ) . '</h2>';
		echo '<p class="aui-card-d">' . esc_html__( 'Apps such as Claude or ChatGPT that signed in to this site with your WordPress account. Revoking stops an app at once.', 'specflux-site-agent' ) . '</p></div>';
		if ( array() !== $grants ) {
			echo '<span class="aui-count aui-end">' . esc_html( (string) count( $grants ) ) . '</span>';
		}
		echo '</div>';
		if ( array() === $grants ) {
			echo '<div class="aui-card-b"><p class="aui-muted">' . esc_html__( 'No apps are signed in.', 'specflux-site-agent' ) . '</p></div>';
		} else {
			echo '<div class="aui-table-wrap"><table class="aui-table"><thead><tr><th scope="col">' . esc_html__( 'App', 'specflux-site-agent' ) . '</th><th scope="col">' . esc_html__( 'User', 'specflux-site-agent' ) . '</th><th scope="col">' . esc_html__( 'Access', 'specflux-site-agent' ) . '</th><th scope="col">' . esc_html__( 'Signed in', 'specflux-site-agent' ) . '</th><th scope="col"><span class="aui-sr">' . esc_html__( 'Actions', 'specflux-site-agent' ) . '</span></th></tr></thead><tbody>';
			foreach ( array_keys( $grants ) as $id ) {
				$grant = Store::grant( (string) $id );
				if ( null === $grant ) {
					continue;
				}
				$client = Clients::resolveStoredName( (string) $grant['client'] );
				$user   = function_exists( 'get_userdata' ) ? get_userdata( (int) $grant['user'] ) : false;
				$write  = OAuth::allowsWrite( (string) $grant['scope'] );
				echo '<tr><td><strong>' . esc_html( $client ) . '</strong></td><td>' . esc_html( $user ? (string) $user->user_login : '#' . (int) $grant['user'] ) . '</td><td>'
					. ( $write
						? '<span class="aui-badge aui-badge--info">' . Kit::icon( 'pencil' ) . esc_html__( 'Read and write', 'specflux-site-agent' ) . '</span>'
						: '<span class="aui-badge">' . Kit::icon( 'book' ) . esc_html__( 'Read only', 'specflux-site-agent' ) . '</span>' )
					. '</td><td class="aui-mono">' . esc_html( gmdate( 'Y-m-d H:i', (int) $grant['created'] ) . ' UTC' ) . '</td><td><div class="aui-actions">'
					. self::button( 'specflux_site_agent_oauth_revoke', 'grant', (string) $id, self::NONCE_REVOKE . $id, __( 'Revoke', 'specflux-site-agent' ), 'aui-btn--danger-outline' ) . '</div></td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section>';

		$clients = Store::clientIndex();
		echo '<section class="aui-card"><div class="aui-card-h"><h2 class="aui-card-t">' . esc_html__( 'Registered apps', 'specflux-site-agent' ) . '</h2>';
		if ( array() !== $clients ) {
			echo '<span class="aui-count aui-end">' . esc_html( (string) count( $clients ) ) . '</span>';
		}
		echo '</div>';
		if ( array() === $clients ) {
			echo '<div class="aui-card-b"><p class="aui-muted">' . esc_html__( 'No apps have registered.', 'specflux-site-agent' ) . '</p></div>';
		} else {
			echo '<div class="aui-table-wrap"><table class="aui-table"><thead><tr><th scope="col">' . esc_html__( 'App', 'specflux-site-agent' ) . '</th><th scope="col">' . esc_html__( 'Returns to', 'specflux-site-agent' ) . '</th><th scope="col">' . esc_html__( 'Registered', 'specflux-site-agent' ) . '</th><th scope="col"><span class="aui-sr">' . esc_html__( 'Actions', 'specflux-site-agent' ) . '</span></th></tr></thead><tbody>';
			foreach ( array_keys( $clients ) as $id ) {
				$client = Store::client( (string) $id );
				if ( null === $client ) {
					continue;
				}
				$hosts = array_unique( array_map( array( RedirectUri::class, 'displayHost' ), $client['redirect_uris'] ) );
				echo '<tr><td><strong>' . esc_html( $client['name'] ) . '</strong></td><td class="aui-mono">' . esc_html( implode( ', ', $hosts ) ) . '</td><td class="aui-mono">' . esc_html( gmdate( 'Y-m-d H:i', $client['created'] ) . ' UTC' ) . '</td><td><div class="aui-actions">'
					. self::button( 'specflux_site_agent_oauth_remove', 'client', (string) $id, self::NONCE_REMOVE . $id, __( 'Remove', 'specflux-site-agent' ), 'aui-btn--danger-outline' ) . '</div></td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section>';
		// phpcs:enable
	}

	private static function button( string $action, string $field, string $value, string $nonce_action, string $label, string $variant ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $value ) . '">'
			. wp_nonce_field( $nonce_action, '_wpnonce', true, false )
			. '<button type="submit" class="aui-btn aui-btn--sm ' . esc_attr( $variant ) . '">' . esc_html( $label ) . '</button></form>';
	}
}
