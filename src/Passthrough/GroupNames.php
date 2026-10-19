<?php
/**
 * The name shown for a group of abilities from other plugins.
 *
 * Abilities are named `namespace/ability`; the namespace is usually the
 * registering plugin's folder slug or text domain, so the installed plugin's
 * own name ("Advanced Custom Fields") is looked up from that. Anything that
 * matches no plugin falls back to a readable form of the namespace.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Passthrough;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/** Display names for ability namespaces. */
final class GroupNames {

	/** Short words that read better in capitals than in title case. */
	private const ACRONYMS = array( 'acf', 'api', 'crm', 'faq', 'rss', 'seo', 'sms', 'smtp', 'url', 'wp', 'cdn', 'ssl', 'ui', 'ai', 'pdf' );

	/** Namespace to a display name that is not a plugin. */
	private const FIXED = array(
		'core' => 'WordPress',
	);

	/** @var array<string,array<string,mixed>>|null Test seam: the installed plugins. */
	private static ?array $plugins_probe = null;

	/**
	 * Test seam: pretend these are the installed plugins (null for the real list).
	 *
	 * @param array<string,array<string,mixed>>|null $plugins Plugin file => header data.
	 */
	public static function set_plugins_probe( ?array $plugins ): void {
		self::$plugins_probe = $plugins;
	}

	/**
	 * The display name for a namespace.
	 *
	 * @param string $namespace_slug The part of the ability name before the slash.
	 */
	public static function label( string $namespace_slug ): string {
		$slug = strtolower( trim( $namespace_slug, '/ ' ) );
		if ( '' === $slug ) {
			return '';
		}
		if ( isset( self::FIXED[ $slug ] ) ) {
			return self::FIXED[ $slug ];
		}

		foreach ( self::plugins() as $file => $data ) {
			$folder = strtolower( str_contains( (string) $file, '/' ) ? (string) strstr( (string) $file, '/', true ) : (string) preg_replace( '/\.php$/', '', (string) $file ) );
			$domain = strtolower( (string) ( $data['TextDomain'] ?? '' ) );
			$name   = (string) ( $data['Name'] ?? '' );
			if ( '' !== $name && ( $folder === $slug || $domain === $slug ) ) {
				return $name;
			}
		}

		return self::humanize( $slug );
	}

	/** A readable form of a namespace: `wp-mail-smtp` -> "WP Mail SMTP", `shop_tools` -> "Shop Tools". */
	public static function humanize( string $slug ): string {
		$words = preg_split( '/[-_\s]+/', $slug, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( is_array( $words ) ? $words : array() as $word ) {
			$out[] = in_array( strtolower( $word ), self::ACRONYMS, true ) ? strtoupper( $word ) : ucfirst( $word );
		}

		return implode( ' ', $out );
	}

	/**
	 * The installed plugins, `plugin file => header data`.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function plugins(): array {
		if ( null !== self::$plugins_probe ) {
			return self::$plugins_probe;
		}
		// get_plugins() is loaded on every wp-admin screen, which is the only place this renders.
		return function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
	}
}
