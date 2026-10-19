<?php
/**
 * Group names for abilities from other plugins.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Passthrough;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Passthrough\GroupNames;

final class GroupNamesTest extends TestCase {

	protected function setUp(): void {
		GroupNames::set_plugins_probe(
			array(
				'advanced-custom-fields/acf.php' => array(
					'Name'       => 'Advanced Custom Fields',
					'TextDomain' => 'acf',
				),
				'wp-mail-smtp/wp_mail_smtp.php'  => array(
					'Name'       => 'WP Mail SMTP by WPForms',
					'TextDomain' => 'wp-mail-smtp',
				),
			)
		);
	}

	protected function tearDown(): void {
		GroupNames::set_plugins_probe( null );
	}

	public function test_a_namespace_matching_a_plugin_text_domain_uses_the_plugin_name(): void {
		$this->assertSame( 'Advanced Custom Fields', GroupNames::label( 'acf' ) );
	}

	public function test_a_namespace_matching_a_plugin_folder_uses_the_plugin_name(): void {
		$this->assertSame( 'WP Mail SMTP by WPForms', GroupNames::label( 'wp-mail-smtp' ) );
	}

	public function test_an_unknown_namespace_is_humanized_with_known_acronyms_in_capitals(): void {
		$this->assertSame( 'SEO', GroupNames::label( 'seo' ) );
		$this->assertSame( 'Shop Tools', GroupNames::label( 'shop_tools' ) );
		$this->assertSame( 'SMTP Relay', GroupNames::label( 'smtp-relay' ) );
		$this->assertSame( 'WordPress', GroupNames::label( 'core' ) );
		$this->assertSame( '', GroupNames::label( '' ) );
	}
}
