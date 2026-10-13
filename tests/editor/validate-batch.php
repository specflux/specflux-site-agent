<?php
/**
 * Reads a JSON array of markup strings on STDIN and prints, for each, the
 * pages-pack verdict: `true` when Validator::clean() accepts it, else the
 * refusal code. Used by the editor-parity differential test.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

require dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/stubs/blocks.php';

use Specflux\SiteAgent\Packs\Pages\Validator;
use Specflux\SiteAgent\Packs\Pages\Vocabulary;

// A palette with real colours, so a toned page resolves its pair as it does in the export.
require_once dirname( __DIR__ ) . '/stubs/theme-patterns.php';
$GLOBALS['specflux_site_agent_test_global_settings'] = array(
	'color' => array(
		'palette' => array(
			array(
				'slug'  => 'base',
				'color' => '#FFFFFF',
			),
			array(
				'slug'  => 'contrast',
				'color' => '#111111',
			),
			array(
				'slug'  => 'accent-1',
				'color' => '#FFEE58',
			),
			array(
				'slug'  => 'accent-3',
				'color' => '#503AA8',
			),
			array(
				'slug'  => 'accent-6',
				'color' => 'color-mix(in srgb, currentColor 20%, transparent)',
			),
		),
	),
);

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- STDIN of a dev-only CLI script.
$specflux_site_agent_inputs    = json_decode( (string) file_get_contents( 'php://stdin' ), true );
$specflux_site_agent_validator = new Validator( new Vocabulary() );
$specflux_site_agent_verdicts  = array();
foreach ( (array) $specflux_site_agent_inputs as $specflux_site_agent_markup ) {
	$specflux_site_agent_result     = $specflux_site_agent_validator->clean( (string) $specflux_site_agent_markup );
	$specflux_site_agent_verdicts[] = $specflux_site_agent_result['ok']
		? array(
			'ok'      => true,
			'content' => $specflux_site_agent_result['content'],
		)
		: array(
			'ok'   => false,
			'code' => $specflux_site_agent_result['wp_error']?->get_error_code(),
		);
}

echo wp_json_encode( $specflux_site_agent_verdicts );
