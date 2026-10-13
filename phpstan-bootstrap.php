<?php
/**
 * PHPStan bootstrap: declares the few WordPress constants src/ relies on that
 * wordpress-stubs does not provide.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/specflux-site-agent-phpstan/' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'SPECFLUX_SITE_AGENT_URL' ) ) {
	define( 'SPECFLUX_SITE_AGENT_URL', 'https://example.test/wp-content/plugins/specflux-site-agent/' );
}

if ( ! defined( 'SPECFLUX_SITE_AGENT_PATH' ) ) {
	// The REAL project root, not a fixture path: `RunsScreen::assets()`
	// `require`s the committed `build/runs/index.asset.php` (S10/S17), and a
	// literal path PHPStan can resolve on disk is what lets it check that
	// `require` for real rather than needing an ignoreErrors entry.
	define( 'SPECFLUX_SITE_AGENT_PATH', __DIR__ . '/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}
