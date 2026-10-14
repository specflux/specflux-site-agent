<?php
/**
 * The site-settings registrar: `specflux/read-settings` (read-only) and
 * `specflux/update-settings` (allow-listed, atomic, before/after reported).
 *
 * Only the options in {@see ALLOWED} are ever writable. Identity, routing and
 * access options (`siteurl`, `home`, `admin_email`, `users_can_register`,
 * `default_role`, `blog_public`, ...) are never writable here; the first four
 * plus `users_can_register` and `default_role` are readable (read_only block).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Packs\Site;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the two settings abilities.
 */
final class Settings {

	/** The capability that gates both abilities. */
	private const CAPABILITY = 'manage_options';

	/** Options visible through read-settings but never writable. */
	private const READ_ONLY = array( 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role' );

	/**
	 * The WRITE allow-list: option => group + type (+ bounds). Anything not
	 * listed here is refused.
	 */
	private const ALLOWED = array(
		// General.
		'blogname'                     => array(
			'group' => 'general',
			'type'  => 'text',
			'max'   => 200,
		),
		'blogdescription'              => array(
			'group' => 'general',
			'type'  => 'text',
			'max'   => 500,
			'empty' => true,
		),
		'timezone_string'              => array(
			'group' => 'general',
			'type'  => 'timezone',
		),
		'date_format'                  => array(
			'group' => 'general',
			'type'  => 'text',
			'max'   => 50,
		),
		'time_format'                  => array(
			'group' => 'general',
			'type'  => 'text',
			'max'   => 50,
		),
		'start_of_week'                => array(
			'group' => 'general',
			'type'  => 'int',
			'min'   => 0,
			'max'   => 6,
		),
		// Reading.
		'posts_per_page'               => array(
			'group' => 'reading',
			'type'  => 'int',
			'min'   => 1,
			'max'   => 100,
		),
		'posts_per_rss'                => array(
			'group' => 'reading',
			'type'  => 'int',
			'min'   => 1,
			'max'   => 100,
		),
		'rss_use_excerpt'              => array(
			'group' => 'reading',
			'type'  => 'flag',
		),
		'show_on_front'                => array(
			'group' => 'reading',
			'type'  => 'enum',
			'enum'  => array( 'posts', 'page' ),
		),
		'page_on_front'                => array(
			'group' => 'reading',
			'type'  => 'page',
		),
		'page_for_posts'               => array(
			'group' => 'reading',
			'type'  => 'page',
		),
		// Discussion.
		'default_comment_status'       => array(
			'group' => 'discussion',
			'type'  => 'enum',
			'enum'  => array( 'open', 'closed' ),
		),
		'default_ping_status'          => array(
			'group' => 'discussion',
			'type'  => 'enum',
			'enum'  => array( 'open', 'closed' ),
		),
		'comment_moderation'           => array(
			'group' => 'discussion',
			'type'  => 'flag',
		),
		'comment_previously_approved'  => array(
			'group' => 'discussion',
			'type'  => 'flag',
		),
		'require_name_email'           => array(
			'group' => 'discussion',
			'type'  => 'flag',
		),
		'comment_registration'         => array(
			'group' => 'discussion',
			'type'  => 'flag',
		),
		'close_comments_for_old_posts' => array(
			'group' => 'discussion',
			'type'  => 'flag',
		),
		'close_comments_days_old'      => array(
			'group' => 'discussion',
			'type'  => 'int',
			'min'   => 1,
			'max'   => 3650,
		),
		'thread_comments'              => array(
			'group' => 'discussion',
			'type'  => 'flag',
		),
		'thread_comments_depth'        => array(
			'group' => 'discussion',
			'type'  => 'int',
			'min'   => 2,
			'max'   => 10,
		),
		'page_comments'                => array(
			'group' => 'discussion',
			'type'  => 'flag',
		),
		'comments_per_page'            => array(
			'group' => 'discussion',
			'type'  => 'int',
			'min'   => 1,
			'max'   => 200,
		),
		'default_comments_page'        => array(
			'group' => 'discussion',
			'type'  => 'enum',
			'enum'  => array( 'newest', 'oldest' ),
		),
		'comment_order'                => array(
			'group' => 'discussion',
			'type'  => 'enum',
			'enum'  => array( 'asc', 'desc' ),
		),
		'show_avatars'                 => array(
			'group' => 'discussion',
			'type'  => 'flag',
		),
		'avatar_rating'                => array(
			'group' => 'discussion',
			'type'  => 'enum',
			'enum'  => array( 'G', 'PG', 'R', 'X' ),
		),
		'comment_max_links'            => array(
			'group' => 'discussion',
			'type'  => 'int',
			'min'   => 0,
			'max'   => 100,
		),
	);

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

		self::registerRead();
		self::registerUpdate();
	}

	/** specflux/read-settings. */
	private static function registerRead(): void {
		wp_register_ability(
			'specflux/read-settings',
			array(
				'label'               => __( 'Read site settings', 'specflux-site-agent' ),
				'description'         => __( 'Read the current value of the site settings this plugin may change (general, reading and discussion groups), plus a read_only block (siteurl, home, admin_email, users_can_register, default_role) that can be seen but never changed.', 'specflux-site-agent' ),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => array(),
					'properties'           => array(
						'group' => array(
							'type'    => 'string',
							'enum'    => array( 'general', 'reading', 'discussion', 'all' ),
							'default' => 'all',
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'settings', 'read_only' ),
					'additionalProperties' => false,
					'properties'           => array(
						'settings'  => array( 'type' => 'object' ),
						'read_only' => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeRead( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function () {
					return current_user_can( self::CAPABILITY );
				},
				'meta'                => self::meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);
	}

	/** specflux/update-settings. */
	private static function registerUpdate(): void {
		wp_register_ability(
			'specflux/update-settings',
			array(
				'label'               => __( 'Update site settings', 'specflux-site-agent' ),
				'description'         => sprintf(
					/* translators: %s: comma-separated list of option names. */
					__( 'Change one or more allow-listed site settings in one atomic call: if any entry is invalid or not allowed, nothing is written. Allowed options: %s. Text limits: blogname up to 200 characters, blogdescription up to 500, date_format and time_format up to 50. Integer and enum ranges are enforced; page_on_front and page_for_posts must be 0 or a published page you can edit. Identity and access options (siteurl, home, admin_email, users_can_register, default_role, blog_public) can never be changed. Each call returns the before and after value of every option so a change can be reverted by calling this again with the before values.', 'specflux-site-agent' ),
					implode( ', ', array_keys( self::ALLOWED ) )
				),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'settings' ),
					'additionalProperties' => false,
					'properties'           => array(
						'settings' => array(
							'type'                 => 'object',
							'description'          => __( 'Map of option name to new value.', 'specflux-site-agent' ),
							'additionalProperties' => true,
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'updated' ),
					'additionalProperties' => false,
					'properties'           => array(
						'updated' => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeUpdate( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = null ) {
					return self::canUpdate( is_array( $input ) ? $input : array() );
				},
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);
	}

	/**
	 * Base capability plus the per-object edit check for every page the call
	 * would point an option at.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function canUpdate( array $input ): bool {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}

		$settings = $input['settings'] ?? null;
		if ( ! is_array( $settings ) ) {
			return true;
		}

		foreach ( array( 'page_on_front', 'page_for_posts' ) as $key ) {
			if ( ! isset( $settings[ $key ] ) ) {
				continue;
			}
			$id = self::intOrNull( $settings[ $key ] );
			if ( null !== $id && $id > 0 && ! current_user_can( 'edit_post', $id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeRead( array $input ): array|WP_Error {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return self::forbidden();
		}

		$group = (string) ( $input['group'] ?? 'all' );
		if ( ! in_array( $group, array( 'general', 'reading', 'discussion', 'all' ), true ) ) {
			return new WP_Error(
				'invalid_group',
				__( 'group must be one of general, reading, discussion or all.', 'specflux-site-agent' ),
				array( 'status' => 400 )
			);
		}

		$settings = array();
		foreach ( self::ALLOWED as $key => $rule ) {
			if ( 'all' === $group || $rule['group'] === $group ) {
				$settings[ $key ] = self::current( $key );
			}
		}

		$read_only = array();
		foreach ( self::READ_ONLY as $key ) {
			$value = get_option( $key, '' );
			// Plain scalars only: this block is informational.
			$read_only[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return array(
			'settings'  => $settings,
			'read_only' => $read_only,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeUpdate( array $input ): array|WP_Error {
		if ( ! self::canUpdate( $input ) ) {
			return self::forbidden();
		}

		$requested = $input['settings'] ?? null;
		if ( ! is_array( $requested ) || array() === $requested || array_is_list( $requested ) ) {
			return new WP_Error(
				'invalid_settings',
				__( 'settings must be a non-empty object mapping option names to values.', 'specflux-site-agent' ),
				array( 'status' => 400 )
			);
		}

		// Validate EVERY entry before writing anything (atomic).
		$normalised = array();
		foreach ( $requested as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( self::ALLOWED[ $key ] ) ) {
				return new WP_Error(
					'setting_not_allowed',
					sprintf(
						/* translators: %s: option name. */
						__( 'The setting "%s" cannot be changed here.', 'specflux-site-agent' ),
						$key
					),
					array(
						'status' => 403,
						'key'    => $key,
					)
				);
			}

			$result = self::normalise( $key, $value );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
			$normalised[ $key ] = $result;
		}

		// Cross-field rule: the front page and the posts page must differ.
		if ( isset( $normalised['page_on_front'] ) || isset( $normalised['page_for_posts'] ) ) {
			$front = (int) ( $normalised['page_on_front'] ?? self::current( 'page_on_front' ) );
			$posts = (int) ( $normalised['page_for_posts'] ?? self::current( 'page_for_posts' ) );
			if ( $front > 0 && $front === $posts ) {
				$key = isset( $normalised['page_for_posts'] ) ? 'page_for_posts' : 'page_on_front';

				return self::invalid( $key, __( 'page_on_front and page_for_posts must be different pages.', 'specflux-site-agent' ) );
			}
		}

		$updated = array();
		foreach ( $normalised as $key => $value ) {
			$before = self::current( $key );
			// Stored as strings, the form the options table holds.
			update_option( $key, (string) $value );
			$updated[ $key ] = array(
				'before' => $before,
				'after'  => self::current( $key ),
			);
		}

		return array( 'updated' => $updated );
	}

	/**
	 * Validate and normalise one value against its allow-list rule.
	 *
	 * @param string $key   Allow-listed option name.
	 * @param mixed  $value Raw value from the call.
	 * @return int|string|WP_Error
	 */
	private static function normalise( string $key, mixed $value ): int|string|WP_Error {
		$rule = self::ALLOWED[ $key ];

		switch ( $rule['type'] ) {
			case 'text':
				if ( ! is_string( $value ) ) {
					return self::invalid( $key, __( 'must be a string.', 'specflux-site-agent' ) );
				}
				$text = sanitize_text_field( $value );
				if ( '' === $text && empty( $rule['empty'] ) ) {
					return self::invalid( $key, __( 'must not be empty.', 'specflux-site-agent' ) );
				}
				if ( mb_strlen( $text ) > (int) $rule['max'] ) {
					return self::invalid( $key, __( 'is too long.', 'specflux-site-agent' ) );
				}

				return $text;

			case 'timezone':
				if ( ! is_string( $value ) ) {
					return self::invalid( $key, __( 'must be a string.', 'specflux-site-agent' ) );
				}
				if ( '' !== $value && ! in_array( $value, timezone_identifiers_list(), true ) ) {
					return self::invalid( $key, __( 'must be a valid timezone identifier or empty.', 'specflux-site-agent' ) );
				}

				return $value;

			case 'int':
				$number = self::intOrNull( $value );
				if ( null === $number || $number < (int) $rule['min'] || $number > (int) $rule['max'] ) {
					return self::invalid( $key, __( 'is out of range.', 'specflux-site-agent' ) );
				}

				return $number;

			case 'flag':
				if ( true === $value || 1 === $value || '1' === $value ) {
					return 1;
				}
				if ( false === $value || 0 === $value || '0' === $value ) {
					return 0;
				}

				return self::invalid( $key, __( 'must be 0 or 1.', 'specflux-site-agent' ) );

			case 'enum':
				if ( ! is_string( $value ) || ! in_array( $value, $rule['enum'] ?? array(), true ) ) {
					return self::invalid( $key, __( 'is not an allowed value.', 'specflux-site-agent' ) );
				}

				return $value;

			case 'page':
				$id = self::intOrNull( $value );
				if ( null === $id || $id < 0 ) {
					return self::invalid( $key, __( 'must be 0 or a page ID.', 'specflux-site-agent' ) );
				}
				if ( 0 === $id ) {
					return 0;
				}
				$post = get_post( $id );
				if ( ! is_object( $post ) || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
					return self::invalid( $key, __( 'must be 0 or the ID of a published page.', 'specflux-site-agent' ) );
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return self::forbidden();
				}

				return $id;
		}
	}

	/**
	 * Strict integer coercion: ints and integer-looking strings only.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function intOrNull( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^-?\d{1,9}$/', $value ) ) {
			return (int) $value;
		}

		return null;
	}

	/**
	 * The current typed value of an allow-listed option.
	 *
	 * @param string $key Allow-listed option name.
	 */
	private static function current( string $key ): int|string {
		$raw = get_option( $key, '' );
		$raw = is_scalar( $raw ) ? $raw : '';

		return match ( self::ALLOWED[ $key ]['type'] ) {
			'int', 'flag', 'page' => (int) $raw,
			default               => (string) $raw,
		};
	}

	/**
	 * @param string $key     Offending option.
	 * @param string $message What is wrong with it.
	 */
	private static function invalid( string $key, string $message ): WP_Error {
		return new WP_Error(
			'invalid_setting_value',
			$key . ': ' . $message,
			array(
				'status' => 400,
				'key'    => $key,
			)
		);
	}

	private static function forbidden(): WP_Error {
		return new WP_Error(
			'forbidden',
			__( 'You are not allowed to do that.', 'specflux-site-agent' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * @param array<string,mixed> $annotations Ability meta annotations.
	 * @return array<string,mixed>
	 */
	private static function meta( array $annotations ): array {
		return array(
			'annotations' => $annotations,
			'specflux'    => array( 'hidden' => false ),
		);
	}
}
