<?php
/**
 * The read-only inventory registrar: `specflux/list-plugins` and
 * `specflux/list-themes`. Nothing here activates, installs, updates or
 * switches anything, and update availability comes from the cached update
 * transients only (no network call).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Packs\Site;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the two inventory abilities.
 */
final class Inventory {

	/** Whether {@see register()} has run for this request. */
	private static bool $registered = false;

	/** Wire the ability registration hook (call once, from the composition root). */
	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_init', array( self::class, 'register' ) );
	}

	/** Forget the per-request registered flag (test-only). */
	public static function reset(): void {
		self::$registered = false;
	}

	/** Register the two abilities. Idempotent per request. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'specflux/list-plugins',
			array(
				'label'               => __( 'List installed plugins', 'specflux-site-agent' ),
				'description'         => __( 'List the installed plugins with name, version, active status and whether an update is available. Read-only: it cannot activate, deactivate, install or update anything.', 'specflux-site-agent' ),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => array(),
					'properties'           => array(
						'status' => array(
							'type'    => 'string',
							'enum'    => array( 'all', 'active', 'inactive' ),
							'default' => 'all',
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'plugins' ),
					'additionalProperties' => false,
					'properties'           => array(
						'plugins' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeListPlugins( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function () {
					return current_user_can( 'activate_plugins' );
				},
				'meta'                => self::meta(),
			)
		);

		wp_register_ability(
			'specflux/list-themes',
			array(
				'label'               => __( 'List installed themes', 'specflux-site-agent' ),
				'description'         => __( 'List the installed themes with name, version, whether each is active, its parent, whether it is a block theme and whether an update is available. Read-only: it cannot switch, install or update themes.', 'specflux-site-agent' ),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => array(),
					'properties'           => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'themes' ),
					'additionalProperties' => false,
					'properties'           => array(
						'themes' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => static function () {
					return self::executeListThemes();
				},
				'permission_callback' => static function () {
					return current_user_can( 'switch_themes' );
				},
				'meta'                => self::meta(),
			)
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeListPlugins( array $input ): array|WP_Error {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return self::forbidden();
		}

		$filter = (string) ( $input['status'] ?? 'all' );
		if ( ! in_array( $filter, array( 'all', 'active', 'inactive' ), true ) ) {
			return new WP_Error(
				'invalid_status',
				__( 'status must be all, active or inactive.', 'specflux-site-agent' ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once trailingslashit( ABSPATH ) . 'wp-admin/includes/plugin.php';
		}

		$updates = self::updateResponse( 'update_plugins' );
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$file = (string) $file;
			if ( is_plugin_active_for_network( $file ) ) {
				$status = 'network_active';
			} elseif ( is_plugin_active( $file ) ) {
				$status = 'active';
			} else {
				$status = 'inactive';
			}

			$is_active = 'inactive' !== $status;
			if ( ( 'active' === $filter && ! $is_active ) || ( 'inactive' === $filter && $is_active ) ) {
				continue;
			}

			$name      = (string) ( $data['Name'] ?? '' );
			$plugins[] = array(
				'file'             => $file,
				'name'             => '' !== $name ? $name : $file,
				'version'          => (string) ( $data['Version'] ?? '' ),
				'status'           => $status,
				'update_available' => isset( $updates[ $file ] ),
			);
		}

		return array( 'plugins' => $plugins );
	}

	/** @return array<string,mixed>|WP_Error */
	private static function executeListThemes(): array|WP_Error {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return self::forbidden();
		}

		$updates = self::updateResponse( 'update_themes' );
		$active  = get_stylesheet();
		$themes  = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$stylesheet = (string) $stylesheet;
			$template   = (string) $theme->get_template();
			$themes[]   = array(
				'stylesheet'       => $stylesheet,
				'name'             => (string) $theme->get( 'Name' ),
				'version'          => (string) $theme->get( 'Version' ),
				'active'           => $stylesheet === $active,
				'parent'           => $template !== $stylesheet ? $template : '',
				'block_theme'      => (bool) $theme->is_block_theme(),
				'update_available' => isset( $updates[ $stylesheet ] ),
			);
		}

		return array( 'themes' => $themes );
	}

	/**
	 * The `response` map of a cached update transient (items with an update
	 * pending). Never triggers a remote check.
	 *
	 * @param string $transient `update_plugins` or `update_themes`.
	 * @return array<mixed>
	 */
	private static function updateResponse( string $transient ): array {
		$value = get_site_transient( $transient );
		if ( ! is_object( $value ) || ! isset( $value->response ) || ! is_array( $value->response ) ) {
			return array();
		}

		return $value->response;
	}

	private static function forbidden(): WP_Error {
		return new WP_Error(
			'forbidden',
			__( 'You are not allowed to do that.', 'specflux-site-agent' ),
			array( 'status' => 403 )
		);
	}

	/** @return array<string,mixed> */
	private static function meta(): array {
		return array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'specflux'    => array( 'hidden' => false ),
		);
	}
}
