<?php
/**
 * The user abilities: `specflux/list-users` and `specflux/read-user`
 * (read-only) and `specflux/set-user-role` (one role, never an escalation by
 * a non-administrator).
 *
 * Output is an allow-list: id, login, display name, roles, registration date,
 * url and email. Never password hashes, activation keys, session tokens,
 * application passwords, capability arrays or user meta.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Packs\Site;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the three user abilities.
 */
final class Users {

	/** Largest page size list-users will honour. */
	private const MAX_PER_PAGE = 100;

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

	/** Register the three abilities. Idempotent per request. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::registerList();
		self::registerRead();
		self::registerSetRole();
	}

	/** specflux/list-users. */
	private static function registerList(): void {
		wp_register_ability(
			'specflux/list-users',
			array(
				'label'               => __( 'List users', 'specflux-site-agent' ),
				'description'         => __( 'List the site\'s users: id, login, display name, roles, registration date, url and email. Requires the list_users capability, so the email is included. Filter by search text and role; per_page is at most 100 (default 20). Passwords, keys, sessions and capability lists are never returned.', 'specflux-site-agent' ),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => array(),
					'properties'           => array(
						'search'   => array( 'type' => 'string' ),
						'role'     => array( 'type' => 'string' ),
						'page'     => array( 'type' => 'integer' ),
						'per_page' => array(
							'type'    => 'integer',
							'maximum' => self::MAX_PER_PAGE,
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'users', 'total', 'total_pages' ),
					'additionalProperties' => false,
					'properties'           => array(
						'users'       => array(
							'type'  => 'array',
							'items' => self::userSchema(),
						),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeList( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function () {
					return current_user_can( 'list_users' );
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

	/** specflux/read-user. */
	private static function registerRead(): void {
		wp_register_ability(
			'specflux/read-user',
			array(
				'label'               => __( 'Read a user', 'specflux-site-agent' ),
				'description'         => __( 'Read one user: id, login, display name, roles, registration date, url and email. You can always read yourself; reading anyone else needs the list_users and edit_user capabilities for that user.', 'specflux-site-agent' ),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => self::userSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeRead( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					return self::mayRead( (int) ( ( is_array( $input ) ? $input : array() )['id'] ?? 0 ) );
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

	/** specflux/set-user-role. */
	private static function registerSetRole(): void {
		wp_register_ability(
			'specflux/set-user-role',
			array(
				'label'               => __( 'Set a user\'s role', 'specflux-site-agent' ),
				'description'         => __( 'Set one user\'s single role (replacing their current roles). You cannot change your own role, grant or change administrators unless you are an administrator, or change a network super admin. Cannot create or delete users or edit capabilities.', 'specflux-site-agent' ),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'role' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'   => array( 'type' => 'integer' ),
						'role' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'previous_roles', 'role' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'             => array( 'type' => 'integer' ),
						'previous_roles' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'role'           => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeSetRole( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					$id = (int) ( ( is_array( $input ) ? $input : array() )['id'] ?? 0 );

					return $id > 0
						&& current_user_can( 'promote_users' )
						&& current_user_can( 'promote_user', $id );
				},
				// A role can be set back, so this is not destructive (Belayline Agent Governance
				// parks destructive=true calls as irreversible).
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
	 * @return array<string,mixed>
	 */
	private static function userSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'login'        => array( 'type' => 'string' ),
				'display_name' => array( 'type' => 'string' ),
				'roles'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'registered'   => array( 'type' => 'string' ),
				'url'          => array( 'type' => 'string' ),
				'email'        => array( 'type' => 'string' ),
			),
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

	/** The single generic refusal (no existence oracle). */
	private static function forbidden(): WP_Error {
		return new WP_Error(
			'forbidden',
			__( 'You are not allowed to do that.', 'specflux-site-agent' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Allow-listed user shape.
	 *
	 * @param \WP_User $user A user.
	 * @return array<string,mixed>
	 */
	private static function shape( object $user, bool $include_email ): array {
		$registered = (string) $user->user_registered;
		$time       = '' === $registered ? false : strtotime( $registered . ' UTC' );
		$out        = array(
			'id'           => (int) $user->ID,
			'login'        => (string) $user->user_login,
			'display_name' => (string) $user->display_name,
			'roles'        => array_values( array_map( 'strval', (array) $user->roles ) ),
			'registered'   => false === $time ? '' : gmdate( 'c', $time ),
			'url'          => (string) $user->user_url,
		);
		if ( $include_email ) {
			$out['email'] = (string) $user->user_email;
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeList( array $input ): array|WP_Error {
		if ( ! current_user_can( 'list_users' ) ) {
			return self::forbidden();
		}

		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $input['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );

		$args = array(
			'number'      => $per_page,
			'paged'       => $page,
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'count_total' => true,
		);
		$role = trim( (string) ( $input['role'] ?? '' ) );
		if ( '' !== $role ) {
			$args['role'] = $role;
		}
		$search = trim( (string) ( $input['search'] ?? '' ) );
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_nicename', 'user_email', 'display_name' );
		}

		$query = new \WP_User_Query( $args );
		$users = array();
		foreach ( (array) $query->get_results() as $user ) {
			/** @var \WP_User $user */
			$users[] = self::shape( $user, true );
		}
		$total = (int) $query->get_total();

		return array(
			'users'       => $users,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Who may read user $id.
	 *
	 * Yourself: always (core REST allows /users/me for any logged-in user).
	 * Anyone else: `list_users` (core REST's gate for viewing other users)
	 * AND the object cap `edit_user` for that user. `edit_user` is stricter
	 * than `list_users` alone on purpose: it denies a non-super-admin the
	 * email/roles of a network super admin on multisite, and ties visibility
	 * to users the caller is actually allowed to manage. On multisite the
	 * target must also be a member of this site.
	 */
	private static function mayRead( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		if ( get_current_user_id() === $id ) {
			return true;
		}
		if ( ! current_user_can( 'list_users' ) || ! current_user_can( 'edit_user', $id ) ) {
			return false;
		}

		return ! is_multisite() || ! function_exists( 'is_user_member_of_blog' ) || is_user_member_of_blog( $id );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeRead( array $input ): array|WP_Error {
		$id = (int) ( $input['id'] ?? 0 );
		if ( ! self::mayRead( $id ) ) {
			return self::forbidden();
		}

		$user = get_userdata( $id );
		if ( ! is_object( $user ) ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'specflux-site-agent' ), array( 'status' => 404 ) );
		}

		return self::shape( $user, get_current_user_id() === $id || current_user_can( 'list_users' ) );
	}

	/** Whether the caller is an administrator in the sense this ability needs. */
	private static function callerIsAdministrator(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'promote_users' );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeSetRole( array $input ): array|WP_Error {
		$id   = (int) ( $input['id'] ?? 0 );
		$role = (string) ( $input['role'] ?? '' );

		if ( $id <= 0 || ! current_user_can( 'promote_users' ) || ! current_user_can( 'promote_user', $id ) ) {
			return self::forbidden();
		}

		$user = get_userdata( $id );
		if ( ! is_object( $user ) ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'specflux-site-agent' ), array( 'status' => 404 ) );
		}

		if ( get_current_user_id() === $id ) {
			return self::refuse( 'cannot_change_own_role', __( 'You cannot change your own role.', 'specflux-site-agent' ) );
		}

		if ( is_multisite() && function_exists( 'is_super_admin' ) && is_super_admin( $id ) ) {
			return self::refuse( 'super_admin_target', __( 'A network super admin\'s role cannot be changed here.', 'specflux-site-agent' ) );
		}

		$is_admin = self::callerIsAdministrator();
		$previous = array_values( array_map( 'strval', (array) $user->roles ) );
		if ( ! $is_admin && in_array( 'administrator', $previous, true ) ) {
			return self::refuse( 'administrator_target', __( 'Only an administrator can change an administrator\'s role.', 'specflux-site-agent' ) );
		}

		if ( '' === $role || null === get_role( $role ) ) {
			return new WP_Error( 'role_not_found', __( 'That role does not exist.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}
		if ( 'administrator' === $role && ! $is_admin ) {
			return self::refuse( 'administrator_role_forbidden', __( 'Only an administrator can grant the administrator role.', 'specflux-site-agent' ) );
		}

		if ( ! function_exists( 'get_editable_roles' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/user.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php'; // @phpstan-ignore requireOnce.fileNotFound
		}
		$editable = function_exists( 'get_editable_roles' ) ? get_editable_roles() : array();
		if ( ! isset( $editable[ $role ] ) ) {
			return self::refuse( 'role_not_editable', __( 'You are not allowed to assign that role.', 'specflux-site-agent' ) );
		}

		$user->set_role( $role );

		return array(
			'id'             => $id,
			'previous_roles' => $previous,
			'role'           => $role,
		);
	}

	/**
	 * @param string $code    Error code.
	 * @param string $message Refusal message.
	 */
	private static function refuse( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 403 ) );
	}
}
