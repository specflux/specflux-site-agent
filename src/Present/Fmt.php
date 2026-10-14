<?php
/**
 * Shared value-cleaning helpers for presenters.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Present;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Everything a presenter copies out of a response goes through here: text is
 * tag-stripped and capped (the Runs screen renders it as text, escaped at
 * output), URLs must be http(s).
 */
final class Fmt {

	private const TEXT_CAP = 200;

	/**
	 * A scalar as short plain text; '' for anything else.
	 *
	 * @param mixed $value Any value.
	 */
	public static function text( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'specflux-site-agent' ) : __( 'No', 'specflux-site-agent' );
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES ) ) ) ?? '' );

		return mb_strlen( $text ) > self::TEXT_CAP ? mb_substr( $text, 0, self::TEXT_CAP ) . '…' : $text;
	}

	/**
	 * The URL when it is http(s), else ''.
	 *
	 * @param mixed $value Any value.
	 */
	public static function url( mixed $value ): string {
		$url = is_string( $value ) ? trim( $value ) : '';

		return 1 === preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	/**
	 * The first non-empty text among the named keys.
	 *
	 * @param array<string,mixed> $source Source array.
	 * @param list<string>        $keys   Keys, in preference order.
	 */
	public static function first( array $source, array $keys ): string {
		foreach ( $keys as $key ) {
			$text = self::text( $source[ $key ] ?? null );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return '';
	}

	/**
	 * Whether the array is a non-empty list of arrays (a "rows" value).
	 *
	 * @param mixed $value Any value.
	 */
	public static function isRows( mixed $value ): bool {
		if ( ! is_array( $value ) || array() === $value || ! array_is_list( $value ) ) {
			return false;
		}

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A link entry for a payload; null when the URL is not usable.
	 *
	 * @param string $label Link text.
	 * @param string|null $url   URL; null or unusable omits the link.
	 * @param string $kind  edit|preview|view.
	 * @return array{label:string,url:string,kind:string}|null
	 */
	public static function link( string $label, ?string $url, string $kind ): ?array {
		$url = self::url( $url );

		return '' === $url ? null : array(
			'label' => $label,
			'url'   => $url,
			'kind'  => $kind,
		);
	}

	/**
	 * Drop nulls from a list of links.
	 *
	 * @param list<array{label:string,url:string,kind:string}|null> $links Links.
	 * @return list<array{label:string,url:string,kind:string}>
	 */
	public static function links( array $links ): array {
		return array_values( array_filter( $links ) );
	}
}
