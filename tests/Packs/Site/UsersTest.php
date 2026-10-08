<?php
/**
 * Site\Users tests: list / read / set role.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Site;

use PHPUnit\Framework\TestCase;
use SenroFlux_Test_User;
use Specflux\SenroFlux\Packs\Site\Users;
use WP_Error;

final class UsersTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/comments-users.php';

		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_ability_categories'] = array();
		$GLOBALS['senroflux_test_multisite']          = false;
		$GLOBALS['senroflux_test_super_admins']       = array();
		$GLOBALS['senroflux_test_roles']              = array( 'administrator', 'editor', 'subscriber' );
		$GLOBALS['senroflux_test_editable_roles']     = array( 'administrator', 'editor', 'subscriber' );
		$GLOBALS['senroflux_test_current_user_id']    = 1;
		$GLOBALS['senroflux_test_users']              = array(
			1 => new SenroFlux_Test_User( 1, 'admin', 'Admin', 'admin@example.test', array( 'administrator' ) ),
			2 => new SenroFlux_Test_User( 2, 'sam', 'Sam', 'sam@example.test', array( 'subscriber' ), 'https://sam.test' ),
			3 => new SenroFlux_Test_User( 3, 'root', 'Root', 'root@example.test', array( 'administrator' ) ),
		);
		$GLOBALS['senroflux_test_user_caps']          = array(
			'list_users'     => true,
			'edit_user'      => true,
			'promote_users'  => true,
			'promote_user'   => true,
			'manage_options' => true,
		);

		Users::reset();
		Users::register();
	}

	private function ability( string $name ): object {
		return $GLOBALS['senroflux_test_abilities'][ 'senroflux/' . $name ];
	}

	private function caps( array $caps ): void {
		$GLOBALS['senroflux_test_user_caps'] = $caps;
	}

	/** An editor-like caller: may promote, but is not an administrator. */
	private function nonAdminPromoter(): void {
		$this->caps(
			array(
				'list_users'    => true,
				'promote_users' => true,
				'promote_user'  => true,
			)
		);
	}

	private function assertNoSecrets( mixed $output ): void {
		$json = (string) wp_json_encode( $output );
		foreach ( array( 'user_pass', 'user_activation_key', 'session_tokens', 'capabilities', 'caps', '$P$hash', 'activation-key' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
	}

	// --- list-users ------------------------------------------------------

	public function test_list_refused_without_list_users(): void {
		$this->caps( array() );
		$this->assertFalse( $this->ability( 'list-users' )->check_permissions( array() ) );
		$this->assertInstanceOf( WP_Error::class, $this->ability( 'list-users' )->execute( array() ) );
	}

	public function test_list_happy_path_pages_and_no_secrets(): void {
		$out = $this->ability( 'list-users' )->execute( array( 'per_page' => 2 ) );

		$this->assertSame( 3, $out['total'] );
		$this->assertSame( 2, $out['total_pages'] );
		$this->assertCount( 2, $out['users'] );
		$this->assertSame( 'admin@example.test', $out['users'][0]['email'] );
		$this->assertSame( array( 'administrator' ), $out['users'][0]['roles'] );
		$this->assertSame( '2026-01-02T03:04:05+00:00', $out['users'][1]['registered'] );
		$this->assertNoSecrets( $out );
	}

	public function test_list_filters_by_role_and_search(): void {
		$this->assertSame( 2, $this->ability( 'list-users' )->execute( array( 'role' => 'administrator' ) )['total'] );
		$this->assertSame( 'sam', $this->ability( 'list-users' )->execute( array( 'search' => 'sam' ) )['users'][0]['login'] );
	}

	// --- read-user -------------------------------------------------------

	public function test_read_other_refused_without_list_users_or_object_cap(): void {
		$this->caps( array( 'edit_user' => true ) );
		$this->assertFalse( $this->ability( 'read-user' )->check_permissions( array( 'id' => 2 ) ) );

		$this->caps(
			array(
				'list_users'  => true,
				'edit_user:2' => false,
			)
		);
		$this->assertFalse( $this->ability( 'read-user' )->check_permissions( array( 'id' => 2 ) ) );
		$this->assertSame( 'forbidden', $this->ability( 'read-user' )->execute( array( 'id' => 2 ) )->get_error_code() );
	}

	public function test_read_self_always_allowed_with_email(): void {
		$this->caps( array() );
		$GLOBALS['senroflux_test_current_user_id'] = 2;

		$this->assertTrue( $this->ability( 'read-user' )->check_permissions( array( 'id' => 2 ) ) );
		$out = $this->ability( 'read-user' )->execute( array( 'id' => 2 ) );
		$this->assertSame( 'sam@example.test', $out['email'] );
		$this->assertNoSecrets( $out );
	}

	public function test_read_other_happy_path_and_multisite_non_member(): void {
		$out = $this->ability( 'read-user' )->execute( array( 'id' => 2 ) );
		$this->assertSame( 2, $out['id'] );
		$this->assertArrayNotHasKey( 'users', $out );
		$this->assertNoSecrets( $out );

		$this->assertSame( 'not_found', $this->ability( 'read-user' )->execute( array( 'id' => 404 ) )->get_error_code() );
	}

	// --- set-user-role ---------------------------------------------------

	public function test_set_role_refused_without_base_or_object_cap(): void {
		$in = array(
			'id'   => 2,
			'role' => 'editor',
		);
		$this->caps( array( 'promote_user' => true ) );
		$this->assertFalse( $this->ability( 'set-user-role' )->check_permissions( $in ) );

		$this->caps(
			array(
				'promote_users'  => true,
				'promote_user:2' => false,
			)
		);
		$this->assertFalse( $this->ability( 'set-user-role' )->check_permissions( $in ) );
		$this->assertSame( 'forbidden', $this->ability( 'set-user-role' )->execute( $in )->get_error_code() );
		$this->assertSame( array( 'subscriber' ), $GLOBALS['senroflux_test_users'][2]->roles );
	}

	public function test_set_role_happy_path(): void {
		$out = $this->ability( 'set-user-role' )->execute(
			array(
				'id'   => 2,
				'role' => 'editor',
			)
		);

		$this->assertSame(
			array(
				'id'             => 2,
				'previous_roles' => array( 'subscriber' ),
				'role'           => 'editor',
			),
			$out
		);
		$this->assertSame( array( 'editor' ), $GLOBALS['senroflux_test_users'][2]->roles );
	}

	public function test_set_role_refuses_own_role(): void {
		$out = $this->ability( 'set-user-role' )->execute(
			array(
				'id'   => 1,
				'role' => 'editor',
			)
		);
		$this->assertSame( 'cannot_change_own_role', $out->get_error_code() );
		$this->assertSame( array( 'administrator' ), $GLOBALS['senroflux_test_users'][1]->roles );
	}

	public function test_set_role_refuses_nonexistent_and_non_editable_role(): void {
		$ab = $this->ability( 'set-user-role' );
		$this->assertSame(
			'role_not_found',
			$ab->execute(
				array(
					'id'   => 2,
					'role' => 'ghost',
				)
			)->get_error_code()
		);

		$GLOBALS['senroflux_test_editable_roles'] = array( 'subscriber' );
		$this->assertSame(
			'role_not_editable',
			$ab->execute(
				array(
					'id'   => 2,
					'role' => 'editor',
				)
			)->get_error_code()
		);
	}

	public function test_set_role_refuses_administrator_escalation_by_non_admin(): void {
		$this->nonAdminPromoter();
		$ab = $this->ability( 'set-user-role' );

		$this->assertSame(
			'administrator_role_forbidden',
			$ab->execute(
				array(
					'id'   => 2,
					'role' => 'administrator',
				)
			)->get_error_code()
		);
		$this->assertSame( array( 'subscriber' ), $GLOBALS['senroflux_test_users'][2]->roles );
	}

	public function test_set_role_refuses_demoting_administrator_by_non_admin(): void {
		$this->nonAdminPromoter();

		$out = $this->ability( 'set-user-role' )->execute(
			array(
				'id'   => 3,
				'role' => 'subscriber',
			)
		);
		$this->assertSame( 'administrator_target', $out->get_error_code() );
		$this->assertSame( array( 'administrator' ), $GLOBALS['senroflux_test_users'][3]->roles );
	}

	public function test_set_role_admin_may_grant_administrator(): void {
		$out = $this->ability( 'set-user-role' )->execute(
			array(
				'id'   => 2,
				'role' => 'administrator',
			)
		);
		$this->assertSame( 'administrator', $out['role'] );
	}

	public function test_set_role_refuses_super_admin_on_multisite(): void {
		$GLOBALS['senroflux_test_multisite']    = true;
		$GLOBALS['senroflux_test_super_admins'] = array( 3 );

		$out = $this->ability( 'set-user-role' )->execute(
			array(
				'id'   => 3,
				'role' => 'subscriber',
			)
		);
		$this->assertSame( 'super_admin_target', $out->get_error_code() );
	}

	public function test_meta_and_schemas(): void {
		$meta = $this->ability( 'set-user-role' )->get_meta()['annotations'];
		$this->assertFalse( $meta['destructive'] );
		$this->assertTrue( $meta['idempotent'] );
		$this->assertTrue( $this->ability( 'list-users' )->get_meta()['annotations']['readonly'] );

		foreach ( array( 'list-users', 'read-user', 'set-user-role' ) as $name ) {
			$ab = $this->ability( $name );
			$this->assertStringNotContainsString( 'maxLength', wp_json_encode( $ab->get_input_schema() ), $name );
			$this->assertStringNotContainsString( 'maxLength', wp_json_encode( $ab->get_output_schema() ), $name );
		}
	}
}
