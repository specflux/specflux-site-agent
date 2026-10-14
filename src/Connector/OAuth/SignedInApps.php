<?php
/**
 * Admin page: apps signed in over OAuth, and registered apps.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector\OAuth;

use Specflux\SiteAgent\Connector\Connector;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/** Lists signed-in grants and registered clients; revokes either. Every action is nonce- and capability-checked. */
final class SignedInApps {

	public const SLUG = 'specflux-site-agent-signed-in-apps';

	public const NONCE_REVOKE = 'specflux_site_agent_oauth_revoke_';
	public const NONCE_REMOVE = 'specflux_site_agent_oauth_remove_';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_post_specflux_site_agent_oauth_revoke', array( self::class, 'handleRevoke' ) );
		add_action( 'admin_post_specflux_site_agent_oauth_remove', array( self::class, 'handleRemove' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'specflux-site-agent-runs',
			__( 'Signed-in apps', 'specflux-site-agent' ),
			__( 'Signed-in apps', 'specflux-site-agent' ),
			Connector::capability(),
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
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

	public static function render(): void {
		if ( ! current_user_can( Connector::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Signed-in apps', 'specflux-site-agent' ) . '</h1>';
		echo '<p>' . esc_html__( 'Apps such as Claude or ChatGPT that signed in to this site with your WordPress account. Revoking stops an app at once.', 'specflux-site-agent' ) . '</p>';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped inline; button() returns escaped markup.
		echo '<h2>' . esc_html__( 'Signed in now', 'specflux-site-agent' ) . '</h2>';
		$grants = Store::grantIndex();
		if ( array() === $grants ) {
			echo '<p>' . esc_html__( 'No apps are signed in.', 'specflux-site-agent' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'App', 'specflux-site-agent' ) . '</th><th>' . esc_html__( 'User', 'specflux-site-agent' ) . '</th><th>' . esc_html__( 'Access', 'specflux-site-agent' ) . '</th><th>' . esc_html__( 'Signed in', 'specflux-site-agent' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( array_keys( $grants ) as $id ) {
				$grant = Store::grant( (string) $id );
				if ( null === $grant ) {
					continue;
				}
				$client = Clients::resolveStoredName( (string) $grant['client'] );
				$user   = function_exists( 'get_userdata' ) ? get_userdata( (int) $grant['user'] ) : false;
				echo '<tr><td>' . esc_html( $client ) . '</td><td>' . esc_html( $user ? (string) $user->user_login : '#' . (int) $grant['user'] ) . '</td><td>'
					. esc_html( OAuth::allowsWrite( (string) $grant['scope'] ) ? __( 'Read and write', 'specflux-site-agent' ) : __( 'Read only', 'specflux-site-agent' ) )
					. '</td><td>' . esc_html( gmdate( 'Y-m-d H:i', (int) $grant['created'] ) . ' UTC' ) . '</td><td>'
					. self::button( 'specflux_site_agent_oauth_revoke', 'grant', (string) $id, self::NONCE_REVOKE . $id, __( 'Revoke', 'specflux-site-agent' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Registered apps', 'specflux-site-agent' ) . '</h2>';
		$clients = Store::clientIndex();
		if ( array() === $clients ) {
			echo '<p>' . esc_html__( 'No apps have registered.', 'specflux-site-agent' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'App', 'specflux-site-agent' ) . '</th><th>' . esc_html__( 'Returns to', 'specflux-site-agent' ) . '</th><th>' . esc_html__( 'Registered', 'specflux-site-agent' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( array_keys( $clients ) as $id ) {
				$client = Store::client( (string) $id );
				if ( null === $client ) {
					continue;
				}
				$hosts = array_unique( array_map( array( RedirectUri::class, 'displayHost' ), $client['redirect_uris'] ) );
				echo '<tr><td>' . esc_html( $client['name'] ) . '</td><td>' . esc_html( implode( ', ', $hosts ) ) . '</td><td>' . esc_html( gmdate( 'Y-m-d H:i', $client['created'] ) . ' UTC' ) . '</td><td>'
					. self::button( 'specflux_site_agent_oauth_remove', 'client', (string) $id, self::NONCE_REMOVE . $id, __( 'Remove', 'specflux-site-agent' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		// phpcs:enable
		echo '</div>';
	}

	private static function button( string $action, string $field, string $value, string $nonce_action, string $label ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $value ) . '">'
			. wp_nonce_field( $nonce_action, '_wpnonce', true, false )
			. '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
	}
}
