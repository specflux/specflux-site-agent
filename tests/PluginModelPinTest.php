<?php
/**
 * Plugin-level tests for A run pins its (provider, model) pair at
 * start(), never changes it afterwards, refuses an unavailable pair before
 * any DB write, and a follow-up run inherits the source's pin unless the
 * caller names one explicitly.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Model\ModelChoice;
use Specflux\SiteAgent\Plugin;
use WP_Error;
use wpdb;

final class PluginModelPinTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		$GLOBALS['specflux_site_agent_test_current_user_id'] = 1;
		$GLOBALS['specflux_site_agent_test_user_caps_by_id'] = array();
		$GLOBALS['specflux_site_agent_test_user_caps']       = array();
		$GLOBALS['wpdb']                                     = new wpdb();
		Plugin::set_dependency_probe( true );

		ModelChoice::setAvailableChoicesProbe(
			array(
				'openai' => array(
					'name'   => 'OpenAI',
					'models' => array(
						array(
							'id'   => 'gpt-4o',
							'name' => 'GPT-4o',
						),
					),
				),
			)
		);
	}

	protected function tearDown(): void {
		ModelChoice::setAvailableChoicesProbe( null );
		unset( $GLOBALS['wpdb'] );
		Plugin::reset();
	}

	public function test_a_valid_pair_is_pinned_and_shown_on_every_read(): void {
		$result = Plugin::instance()->start(
			'specflux-mac',
			'Write a post',
			array( 'specflux/read-content' ),
			array(),
			null,
			null,
			null,
			'openai',
			'gpt-4o'
		);

		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertSame(
			array(
				'provider' => 'openai',
				'id'       => 'gpt-4o',
			),
			$result['run']['model']
		);

		$fresh = Plugin::instance()->get( $result['run']['id'] );
		$this->assertIsArray( $fresh );
		$this->assertSame(
			array(
				'provider' => 'openai',
				'id'       => 'gpt-4o',
			),
			$fresh['run']['model']
		);
	}

	public function test_no_pair_pins_nothing_automatic(): void {
		$result = Plugin::instance()->start(
			'specflux-mac',
			'Write a post',
			array( 'specflux/read-content' ),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertNull( $result['run']['model'] );
	}

	public function test_an_unavailable_pair_is_refused_and_creates_no_run(): void {
		$prop = new \ReflectionMethod( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$runner       = $prop->invoke( Plugin::instance() );
		$before_count = count( $runner->store()->listRecent( 100 ) );

		$result = Plugin::instance()->start(
			'specflux-mac',
			'Write a post',
			array( 'specflux/read-content' ),
			array(),
			null,
			null,
			null,
			'openai',
			'not-a-real-model'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( ModelChoice::ERROR_UNAVAILABLE, $result->get_error_code() );

		$after_count = count( $runner->store()->listRecent( 100 ) );
		$this->assertSame( $before_count, $after_count, 'a refused model pin must not create a run' );
	}

	public function test_a_half_specified_pair_is_refused_as_a_bad_request(): void {
		$result = Plugin::instance()->start(
			'specflux-mac',
			'Write a post',
			array( 'specflux/read-content' ),
			array(),
			null,
			null,
			null,
			'openai',
			null
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'specflux_site_agent_bad_request', $result->get_error_code() );
	}

	public function test_a_follow_up_with_no_explicit_pair_inherits_the_sources_pin(): void {
		$this->registerFixturePack();

		$source_id = $this->seedFinishedPinnedPackRun();

		$result = Plugin::instance()->start( 'specflux-mac', 'Follow up', array(), array(), null, null, $source_id );

		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertSame(
			array(
				'provider' => 'openai',
				'id'       => 'gpt-4o',
			),
			$result['run']['model']
		);

		remove_all_filters( 'specflux_site_agent_packs' );
	}

	public function test_a_follow_up_with_an_explicit_pair_overrides_the_sources_pin(): void {
		$this->registerFixturePack();

		$source_id = $this->seedFinishedPinnedPackRun();

		ModelChoice::setAvailableChoicesProbe(
			array(
				'openai' => array(
					'name'   => 'OpenAI',
					'models' => array(
						array(
							'id'   => 'gpt-4o',
							'name' => 'GPT-4o',
						),
						array(
							'id'   => 'gpt-4o-mini',
							'name' => 'GPT-4o mini',
						),
					),
				),
			)
		);

		$result = Plugin::instance()->start(
			'specflux-mac',
			'Follow up',
			array(),
			array(),
			null,
			null,
			$source_id,
			'openai',
			'gpt-4o-mini'
		);

		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertSame(
			array(
				'provider' => 'openai',
				'id'       => 'gpt-4o-mini',
			),
			$result['run']['model']
		);

		remove_all_filters( 'specflux_site_agent_packs' );
	}

	public function test_a_follow_up_inheriting_a_no_longer_available_pin_still_starts_with_it(): void {
		$this->registerFixturePack();

		$source_id = $this->seedFinishedPinnedPackRun();

		ModelChoice::setAvailableChoicesProbe( array() );

		$result = Plugin::instance()->start( 'specflux-mac', 'Follow up', array(), array(), null, null, $source_id );

		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertSame(
			array(
				'provider' => 'openai',
				'id'       => 'gpt-4o',
			),
			$result['run']['model']
		);

		remove_all_filters( 'specflux_site_agent_packs' );
	}

	/** Registers a pack-bound fixture the current user may run (follow-ups need a pack source). */
	private function registerFixturePack(): void {
		add_filter(
			'specflux_site_agent_packs',
			static function ( array $packs ): array {
				$packs['fixture'] = new class() extends \Specflux\SiteAgent\Packs\Pack {
					public function __construct() {
						parent::__construct( array( 'read' => 'read-content' ) );
					}

					public function name(): string {
						return 'fixture';
					}

					public function verbMap(): array {
						return array( 'specflux/read-content' => 0 );
					}

					public function runCapability(): string {
						return 'edit_posts';
					}

					protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
						unset( $user_id );

						return null;
					}
				};

				return $packs;
			}
		);
		$GLOBALS['specflux_site_agent_test_user_caps_by_id'][1]['edit_posts'] = true;
		$GLOBALS['specflux_site_agent_test_user_caps']['edit_posts']          = true;
	}

	/** @return int A fresh, finished, pack-bound source run pinned to openai/gpt-4o. */
	private function seedFinishedPinnedPackRun(): int {
		$prop = new \ReflectionMethod( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$runner = $prop->invoke( Plugin::instance() );
		$store  = $runner->store();

		$run_id = $store->createRun(
			1,
			'test-consumer',
			'Original goal',
			array( 'specflux/read-content' ),
			array(),
			'fixture',
			null,
			null,
			\Specflux\SiteAgent\Run\GateMode::AgentSafety,
			array(),
			null,
			'openai',
			'gpt-4o'
		);
		$store->updateRun(
			$run_id,
			array(
				'status'      => 'completed',
				'finished_at' => gmdate( 'Y-m-d H:i:s' ),
				'result_json' => array(
					'summary' => '',
					'changes' => array(),
				),
			)
		);

		return $run_id;
	}
}
