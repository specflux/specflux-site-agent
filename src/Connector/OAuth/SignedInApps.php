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

	/**
	 * The apps signed in now, for the AI apps tab's Connected table.
	 *
	 * @return list<array{id:string,client:string,user:string,write:bool,created:int}>
	 */
	public static function grantRows(): array {
		$rows = array();
		foreach ( array_keys( Store::grantIndex() ) as $id ) {
			$grant = Store::grant( (string) $id );
			if ( null === $grant ) {
				continue;
			}
			$user   = function_exists( 'get_userdata' ) ? get_userdata( (int) $grant['user'] ) : false;
			$rows[] = array(
				'id'      => (string) $id,
				'client'  => Clients::resolveStoredName( (string) $grant['client'] ),
				'user'    => $user ? (string) $user->user_login : '#' . (int) $grant['user'],
				'write'   => OAuth::allowsWrite( (string) $grant['scope'] ),
				'created' => (int) $grant['created'],
			);
		}

		return $rows;
	}

	/** Render the registered apps, collapsed: an advanced view most owners never need. */
	public static function render(): void {
		if ( ! current_user_can( Connector::capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped inline; button() and Kit::icon() return escaped markup.
		$clients = Store::clientIndex();
		echo '<section class="aui-card"><details class="specflux-site-agent-disclosure"><summary class="aui-card-h"><div><h2 class="aui-card-t">' . esc_html__( 'Advanced: registered apps', 'specflux-site-agent' ) . '</h2>';
		echo '<p class="aui-card-d">' . esc_html__( 'Apps that registered themselves with this site. Removing one also signs out everything that signed in through it.', 'specflux-site-agent' ) . '</p></div>';
		if ( array() !== $clients ) {
			echo '<span class="aui-count aui-end">' . esc_html( (string) count( $clients ) ) . '</span>';
		}
		echo Kit::icon( 'caret-down' ) . '</summary>';
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
		echo '</details></section>';
		// phpcs:enable
	}

	public static function button( string $action, string $field, string $value, string $nonce_action, string $label, string $variant ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $value ) . '">'
			. wp_nonce_field( $nonce_action, '_wpnonce', true, false )
			. '<button type="submit" class="aui-btn aui-btn--sm ' . esc_attr( $variant ) . '">' . esc_html( $label ) . '</button></form>';
	}
}
