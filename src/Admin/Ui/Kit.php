<?php
/**
 * The shared admin UI kit, vendored under assets/vendor/admin-ui-kit/.
 *
 * Plain CSS and vanilla JS that ship inside the plugin: nothing is loaded from
 * a CDN. Every rule in the kit's stylesheet is nested under `.aui`, so enqueueing
 * it never restyles the rest of wp-admin, and it is only enqueued on this
 * plugin's own screens (handles prefixed `specflux-site-agent-`).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Admin\Ui;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/** Locates, enqueues and inlines the vendored kit files. */
final class Kit {

	public const DIR = 'assets/vendor/admin-ui-kit/';

	public const HANDLE_TOKENS = 'specflux-site-agent-ui-tokens';
	public const HANDLE_STYLE  = 'specflux-site-agent-ui-kit';
	public const HANDLE_KIT_JS = 'specflux-site-agent-ui-kit-js';
	public const HANDLE_JS     = 'specflux-site-agent-ui';
	public const HANDLE_CSS    = 'specflux-site-agent-ui-extra';

	/** Absolute path of a vendored file. */
	public static function path( string $file ): string {
		return SPECFLUX_SITE_AGENT_PATH . self::DIR . $file;
	}

	/** Contents of a vendored file, or '' when it is missing. */
	private static function read( string $path ): string {
		$contents = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local plugin file, not a remote URL.

		return is_string( $contents ) ? $contents : '';
	}

	/** Cache-busting version: the kit's own version plus a short hash of its manifest. */
	public static function version(): string {
		$kit = trim( self::read( self::path( 'VERSION' ) ) );

		return ( '' === $kit ? '0' : $kit ) . '-' . substr( md5( self::read( self::path( 'MANIFEST.sha256' ) ) ), 0, 8 );
	}

	/** The plugin's own version, from its header (the chip in the app header). */
	public static function pluginVersion(): string {
		$head = substr( self::read( SPECFLUX_SITE_AGENT_PATH . 'specflux-site-agent.php' ), 0, 2048 );

		return 1 === preg_match( '/^[ \t\/*#@]*Version:\s*(\S+)/mi', $head, $match ) ? $match[1] : '';
	}

	/** Enqueue the kit's stylesheets and scripts. Call only on this plugin's screens. */
	public static function enqueue(): void {
		$base    = SPECFLUX_SITE_AGENT_URL . self::DIR;
		$version = self::version();

		wp_enqueue_style( self::HANDLE_TOKENS, $base . 'tokens.css', array(), $version );
		wp_enqueue_style( self::HANDLE_STYLE, $base . 'components.css', array( self::HANDLE_TOKENS ), $version );
		wp_enqueue_script( self::HANDLE_KIT_JS, $base . 'aui.js', array(), $version, true );
		$plugin = self::pluginVersion();
		wp_enqueue_style( self::HANDLE_CSS, SPECFLUX_SITE_AGENT_URL . 'assets/admin/site-agent-ui.css', array( self::HANDLE_STYLE ), $plugin );
		wp_enqueue_script( self::HANDLE_JS, SPECFLUX_SITE_AGENT_URL . 'assets/admin/site-agent-ui.js', array( self::HANDLE_KIT_JS ), $plugin, true );
	}

	/**
	 * Tokens and components as one string, for a standalone page (the OAuth
	 * consent page) that has no wp-admin and no enqueue system.
	 */
	public static function inlineCss(): string {
		return self::read( self::path( 'tokens.css' ) ) . "\n" . self::read( self::path( 'components.css' ) );
	}

	/** The icon sprite as inline markup (hidden), printed once per page so `<use href="#aui-i-x">` resolves. */
	public static function sprite(): string {
		return self::read( self::path( 'icons.svg' ) );
	}

	/**
	 * One icon from the sprite.
	 *
	 * @param string $name  Sprite id without the `aui-i-` prefix.
	 * @param string $extra Extra classes (e.g. `aui-i--lg`).
	 */
	public static function icon( string $name, string $extra = '' ): string {
		$class = trim( 'aui-i ' . $extra );

		return '<svg class="' . esc_attr( $class ) . '" aria-hidden="true" focusable="false"><use href="#aui-i-' . esc_attr( $name ) . '"/></svg>';
	}
}
