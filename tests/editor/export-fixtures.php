<?php
/**
 * Prints the pages-pack markup the editor-parity check validates, as JSON:
 * every shipped pattern on its own, and a full page after Validator::clean()
 * (the exact content a create/update write persists).
 *
 * Run by `tests/editor/editor-parity.test.cjs`; not part of the PHPUnit suite.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

require dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/stubs/blocks.php';

use Specflux\SiteAgent\Packs\Pages\Layouts;
use Specflux\SiteAgent\Packs\Pages\ThemePatterns;
use Specflux\SiteAgent\Packs\Pages\Validator;
use Specflux\SiteAgent\Packs\Pages\Vocabulary;
use Specflux\SiteAgent\Tests\Packs\Pages\LayoutsTest;

$specflux_site_agent_vocabulary = new Vocabulary();
$specflux_site_agent_patterns   = array();
foreach ( $specflux_site_agent_vocabulary->all() as $specflux_site_agent_pattern ) {
	$specflux_site_agent_patterns[ (string) $specflux_site_agent_pattern['slug'] ] = (string) $specflux_site_agent_pattern['markup'];
}

$specflux_site_agent_page  = implode(
	"\n\n",
	array_map(
		static fn ( string $slug ): string => $specflux_site_agent_patterns[ $slug ],
		array( 'hero', 'text-section', 'feature-grid', 'pricing-table', 'faq', 'testimonials', 'cta' )
	)
);
$specflux_site_agent_clean = ( new Validator( $specflux_site_agent_vocabulary ) )->clean( $specflux_site_agent_page );
if ( ! $specflux_site_agent_clean['ok'] ) {
	throw new RuntimeException( 'The full shipped page was refused: ' . esc_html( (string) $specflux_site_agent_clean['wp_error']?->get_error_code() ) );
}

require_once dirname( __DIR__ ) . '/Packs/Pages/LayoutsTest.php';
LayoutsTest::registerThemeFixtures();
$specflux_site_agent_layout_parts = array();
foreach ( LayoutsTest::outline() as $specflux_site_agent_index => $specflux_site_agent_section ) {
	$specflux_site_agent_built = Layouts::render( $specflux_site_agent_section, $specflux_site_agent_index, $specflux_site_agent_vocabulary );
	if ( ! is_string( $specflux_site_agent_built ) ) {
		throw new RuntimeException( 'A layout was refused: ' . esc_html( $specflux_site_agent_built->get_error_message() ) );
	}
	$specflux_site_agent_layout_parts[] = $specflux_site_agent_built;
}
$specflux_site_agent_layouts = ( new Validator( $specflux_site_agent_vocabulary ) )->clean( implode( "\n\n", $specflux_site_agent_layout_parts ) );
if ( ! $specflux_site_agent_layouts['ok'] ) {
	throw new RuntimeException( 'The layouts page was refused: ' . esc_html( (string) $specflux_site_agent_layouts['wp_error']?->get_error_message() ) );
}

// D3b (S6): curated sections wearing a tone (an image-less hero defaults to
// `contrast`; the text section takes `accent`), built with Twenty
// Twenty-Five's real palette and no theme patterns so every layout is the
// curated one, then cleaned by the Validator like any write.
$specflux_site_agent_theme_patterns                  = $GLOBALS['specflux_site_agent_test_theme_patterns'] ?? array();
$GLOBALS['specflux_site_agent_test_theme_patterns']  = array();
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
ThemePatterns::resetCache();
$specflux_site_agent_toned_outline  = LayoutsTest::outline();
$specflux_site_agent_toned_services = $specflux_site_agent_toned_outline[2];
foreach ( $specflux_site_agent_toned_services['items'] as $specflux_site_agent_toned_key => $specflux_site_agent_toned_item ) {
	unset( $specflux_site_agent_toned_services['items'][ $specflux_site_agent_toned_key ]['image'] );
}
$specflux_site_agent_toned_hero = $specflux_site_agent_toned_outline[0];
unset( $specflux_site_agent_toned_hero['image'] );
$specflux_site_agent_toned_parts = array();
foreach ( array( $specflux_site_agent_toned_hero, array( 'tone' => 'accent' ) + $specflux_site_agent_toned_outline[1], $specflux_site_agent_toned_services, $specflux_site_agent_toned_outline[5] ) as $specflux_site_agent_toned_index => $specflux_site_agent_toned_section ) {
	$specflux_site_agent_toned_built = Layouts::render( $specflux_site_agent_toned_section, $specflux_site_agent_toned_index, $specflux_site_agent_vocabulary );
	if ( ! is_string( $specflux_site_agent_toned_built ) ) {
		throw new RuntimeException( 'A toned layout was refused: ' . esc_html( $specflux_site_agent_toned_built->get_error_message() ) );
	}
	$specflux_site_agent_toned_parts[] = $specflux_site_agent_toned_built;
}
$specflux_site_agent_toned = ( new Validator( $specflux_site_agent_vocabulary ) )->clean( implode( "\n\n", $specflux_site_agent_toned_parts ) );
if ( ! $specflux_site_agent_toned['ok'] ) {
	throw new RuntimeException( 'The toned page was refused: ' . esc_html( $specflux_site_agent_toned['wp_error']?->get_error_message() ?? '' ) );
}
$GLOBALS['specflux_site_agent_test_theme_patterns'] = $specflux_site_agent_theme_patterns;
unset( $GLOBALS['specflux_site_agent_test_global_settings'] );
ThemePatterns::resetCache();

// D3a (S4): a real Ollie pattern (`tests/ThemePatterns/ollie/numbers-stacked.php`,
// GPL, same licence as this plugin), filled through the plugin's own fill
// path and joined to the curated hero so `checkPageShape()`'s `hero_first`
// passes, must open in the editor with its shipped `backgroundColor`/
// `textColor` still exactly as the theme shipped them.
require_once dirname( __DIR__ ) . '/stubs/theme-patterns.php';

$specflux_site_agent_ollie_path = dirname( __DIR__ ) . '/ThemePatterns/ollie/numbers-stacked.php';
ob_start();
require $specflux_site_agent_ollie_path;
$specflux_site_agent_ollie_content = trim( (string) ob_get_clean() );

$GLOBALS['specflux_site_agent_test_theme_patterns']  = array(
	array(
		'name'        => 'ollie/numbers-stacked',
		'title'       => 'Numbers Stacked',
		'description' => 'Display impressive numbers with a short description',
		'content'     => $specflux_site_agent_ollie_content,
		'filePath'    => $specflux_site_agent_ollie_path,
		'categories'  => array( 'ollie/features' ),
	),
);
$GLOBALS['specflux_site_agent_test_stylesheet_dir']  = dirname( __DIR__ ) . '/ThemePatterns/ollie';
$GLOBALS['specflux_site_agent_test_template_dir']    = dirname( __DIR__ ) . '/ThemePatterns/ollie';
$GLOBALS['specflux_site_agent_test_global_settings'] = array(
	'color' => array(
		'palette'   => array(
			array( 'slug' => 'primary' ),
			array( 'slug' => 'primary-accent' ),
			array( 'slug' => 'primary-alt' ),
			array( 'slug' => 'primary-alt-accent' ),
			array( 'slug' => 'main' ),
			array( 'slug' => 'main-accent' ),
			array( 'slug' => 'base' ),
			array( 'slug' => 'secondary' ),
			array( 'slug' => 'tertiary' ),
			array( 'slug' => 'border-light' ),
			array( 'slug' => 'border-dark' ),
		),
		'gradients' => array(),
	),
);
ThemePatterns::resetCache();

$specflux_site_agent_ollie_vocabulary = new Vocabulary();
$specflux_site_agent_ollie_slots      = ThemePatterns::textSlots( $specflux_site_agent_ollie_content );
$specflux_site_agent_ollie_values     = array();
foreach ( $specflux_site_agent_ollie_slots as $specflux_site_agent_ollie_slot ) {
	$specflux_site_agent_ollie_values[ $specflux_site_agent_ollie_slot['index'] ] = 'New short copy';
}

$specflux_site_agent_ollie_filled = ThemePatterns::fill( $specflux_site_agent_ollie_content, $specflux_site_agent_ollie_values );
if ( ! $specflux_site_agent_ollie_filled['ok'] ) {
	throw new RuntimeException( 'The Ollie pattern fill was refused: ' . esc_html( (string) $specflux_site_agent_ollie_filled['wp_error']?->get_error_code() ) );
}

$specflux_site_agent_ollie_page = ( new Validator( $specflux_site_agent_ollie_vocabulary ) )->clean(
	$specflux_site_agent_patterns['hero'] . "\n\n" . $specflux_site_agent_ollie_filled['content']
);
if ( ! $specflux_site_agent_ollie_page['ok'] ) {
	throw new RuntimeException( 'The Ollie pattern page was refused: ' . esc_html( (string) $specflux_site_agent_ollie_page['wp_error']?->get_error_code() ) );
}

// D1 amendment (S5b): a real Ollie pattern (`features-with-emojis`) adapted by
// the layouts path with all three bounded changes at once (each card's emoji
// paragraph dropped, four cards trimmed to three, the layout's heading
// inserted at the top), then cleaned by the Validator, must open in the
// editor without recovery.
$specflux_site_agent_adapted_path = dirname( __DIR__ ) . '/ThemePatterns/ollie/features-with-emojis.php';
ob_start();
require $specflux_site_agent_adapted_path;
$specflux_site_agent_adapted_content = trim( (string) ob_get_clean() );

$GLOBALS['specflux_site_agent_test_theme_patterns'] = array(
	array(
		'name'        => 'ollie/features-with-emojis',
		'title'       => 'Features With Emojis',
		'description' => '',
		'content'     => $specflux_site_agent_adapted_content,
		'filePath'    => $specflux_site_agent_adapted_path,
		'categories'  => array( 'ollie/features' ),
	),
);
$GLOBALS['specflux_site_agent_test_stylesheet']     = 'ollie';
ThemePatterns::resetCache();

$specflux_site_agent_adapted_section = LayoutsTest::outline()[2];
foreach ( array_keys( $specflux_site_agent_adapted_section['items'] ) as $specflux_site_agent_adapted_key ) {
	unset( $specflux_site_agent_adapted_section['items'][ $specflux_site_agent_adapted_key ]['image'] );
}
$specflux_site_agent_adapted_vocabulary = new Vocabulary();
$specflux_site_agent_adapted_built      = Layouts::render( $specflux_site_agent_adapted_section, 0, $specflux_site_agent_adapted_vocabulary );
if ( ! is_string( $specflux_site_agent_adapted_built ) ) {
	throw new RuntimeException( 'The adapted Ollie section was refused: ' . esc_html( $specflux_site_agent_adapted_built->get_error_message() ) );
}
$specflux_site_agent_adapted_page = ( new Validator( $specflux_site_agent_adapted_vocabulary ) )->clean(
	$specflux_site_agent_patterns['hero'] . "\n\n" . $specflux_site_agent_adapted_built
);
if ( ! $specflux_site_agent_adapted_page['ok'] ) {
	throw new RuntimeException( 'The adapted Ollie page was refused: ' . esc_html( (string) $specflux_site_agent_adapted_page['wp_error']?->get_error_code() ) );
}

echo wp_json_encode(
	array(
		'patterns' => $specflux_site_agent_patterns,
		'page'     => $specflux_site_agent_clean['content'],
		'layouts'  => $specflux_site_agent_layouts['content'],
		'toned'    => $specflux_site_agent_toned['content'],
		'ollie'    => $specflux_site_agent_ollie_page['content'],
		'adapted'  => $specflux_site_agent_adapted_page['content'],
	)
);
