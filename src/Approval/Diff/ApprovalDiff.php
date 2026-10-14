<?php
/**
 * The "What changes" payload for an approval card.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Approval\Diff;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Explanation only. The diff is computed from the pending call's arguments
 * and the object's current state, read just before the card is shown. The
 * approval still binds to the arguments: nothing here is sent back, and an
 * unknown ability or an unreadable object yields no diff at all.
 */
final class ApprovalDiff {

	/** Longest scalar value kept in a field row, in characters. */
	private const MAX_SCALAR = 1000;

	/** Most tokens per side the O(n*m) matcher will compare. */
	private const MAX_TOKENS = 1500;

	/**
	 * Argument name to diff field, per target kind.
	 */
	private const POST_FIELDS = array(
		'title'   => 'title',
		'status'  => 'status',
		'slug'    => 'slug',
		'excerpt' => 'excerpt',
	);

	private const PRODUCT_FIELDS = array(
		'name'              => 'title',
		'title'             => 'title',
		'status'            => 'status',
		'slug'              => 'slug',
		'short_description' => 'excerpt',
		'excerpt'           => 'excerpt',
		'regular_price'     => 'regular_price',
		'sale_price'        => 'sale_price',
	);

	/**
	 * @param ObjectReader $reader Current-state reader.
	 */
	public function __construct( private ObjectReader $reader ) {}

	/**
	 * Build the diff for one pending call.
	 *
	 * @param string              $ability Ability name, e.g. `specflux/update-post`.
	 * @param array<string,mixed> $args    The call's arguments.
	 * @return array<string,mixed>|null Null when there is nothing to compare.
	 */
	public function build( string $ability, array $args ): ?array {
		$pos  = strrpos( $ability, '/' );
		$base = false === $pos ? $ability : substr( $ability, $pos + 1 );

		if ( 'update-navigation' === $base ) {
			$diff = $this->menu( $args );
		} elseif ( 'update-post' === $base || 'publish-post' === $base ) {
			$diff = $this->object( $this->reader->post( $this->id( $args, 'id' ) ), $args, self::POST_FIELDS, 'content' );
		} elseif ( 'product-update' === $base ) {
			$diff = $this->object( $this->reader->product( $this->id( $args, 'id' ) ), $args, self::PRODUCT_FIELDS, 'description' );
		} elseif ( str_starts_with( $base, 'coupon-' ) && array_key_exists( 'amount', $args ) ) {
			$id   = $this->id( $args, 'coupon_id' );
			$id   = $id > 0 ? $id : $this->id( $args, 'id' );
			$diff = $this->object( $this->reader->coupon( $id ), $args, array( 'amount' => 'amount' ), null );
		} else {
			return null;
		}

		return array() === $diff ? null : $diff;
	}

	/**
	 * Compare an existing object against the args that would change it.
	 *
	 * @param array<string,string>|null $current Current state, or null.
	 * @param array<string,mixed>       $args    Call args.
	 * @param array<string,string>      $map     Arg name => field name.
	 * @param string|null               $content Alternate arg name for the body (`content` is always accepted).
	 * @return array<string,mixed>
	 */
	private function object( ?array $current, array $args, array $map, ?string $content ): array {
		if ( null === $current ) {
			return array();
		}

		$fields = array();
		foreach ( $map as $arg => $field ) {
			if ( ! array_key_exists( $arg, $args ) || ! is_scalar( $args[ $arg ] ) || isset( $fields[ $field ] ) ) {
				continue;
			}
			$old      = (string) ( $current[ $field ] ?? '' );
			$proposed = (string) $args[ $arg ];
			if ( $this->same( $old, $proposed ) ) {
				continue;
			}
			$fields[ $field ] = array(
				'field'    => $field,
				'current'  => $this->clip( $old ),
				'proposed' => $this->clip( $proposed ),
			);
		}

		$diff = array();
		if ( array() !== $fields ) {
			$diff['fields'] = array_values( $fields );
		}

		$body = null;
		foreach ( array_filter( array( 'content', $content ) ) as $arg ) {
			if ( isset( $args[ $arg ] ) && is_string( $args[ $arg ] ) ) {
				$body = $args[ $arg ];
				break;
			}
		}
		if ( null !== $body && isset( $current['content'] ) ) {
			$old_words = $this->words( $current['content'] );
			$new_words = $this->words( $body );
			if ( $old_words !== $new_words ) {
				$diff['content'] = $this->ops( $old_words, $new_words );
			}
		}

		return $diff;
	}

	/**
	 * Navigation: the label list, current against proposed.
	 *
	 * @param array<string,mixed> $args Call args.
	 * @return array<string,mixed>
	 */
	private function menu( array $args ): array {
		$items = $args['items'] ?? null;
		if ( ! is_array( $items ) ) {
			return array();
		}
		$current  = $this->reader->menuLabels();
		$proposed = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && is_string( $item['label'] ?? null ) ) {
				$proposed[] = $item['label'];
			}
		}
		if ( $current === $proposed ) {
			return array();
		}

		return array( 'menu' => $this->ops( $current, $proposed ) );
	}

	/**
	 * Token ops between two lists: a longest-common-subsequence walk, with the
	 * shared head and tail peeled off first. Runs of one kind are merged.
	 *
	 * @param list<string> $old Current tokens.
	 * @param list<string> $proposed Proposed tokens.
	 * @return array{ops:list<array{t:string,text:string}>,truncated:bool}
	 */
	private function ops( array $old, array $proposed ): array {
		$old_len = count( $old );
		$new_len = count( $proposed );
		$head    = 0;
		$max     = min( $old_len, $new_len );
		while ( $head < $max && $old[ $head ] === $proposed[ $head ] ) {
			++$head;
		}
		$tail = 0;
		while ( $tail < $max - $head && $old[ $old_len - 1 - $tail ] === $proposed[ $new_len - 1 - $tail ] ) {
			++$tail;
		}

		$mid_old = array_slice( $old, $head, $old_len - $head - $tail );
		$mid_new = array_slice( $proposed, $head, $new_len - $head - $tail );

		$truncated = count( $mid_old ) > self::MAX_TOKENS || count( $mid_new ) > self::MAX_TOKENS;
		$ops       = array();
		$this->push( $ops, 'eq', array_slice( $old, 0, $head ) );

		if ( $truncated ) {
			// Too large to align word by word: show the span as removed, then added.
			$this->push( $ops, 'del', $mid_old );
			$this->push( $ops, 'ins', $mid_new );
		} else {
			$this->align( $ops, $mid_old, $mid_new );
		}

		$this->push( $ops, 'eq', array_slice( $old, $old_len - $tail ) );

		return array(
			'ops'       => $ops,
			'truncated' => $truncated,
		);
	}

	/**
	 * LCS alignment of two short token lists into $ops.
	 *
	 * @param list<array{t:string,text:string}> $ops Output, by reference.
	 * @param list<string>                           $a   Current.
	 * @param list<string>                           $b   Proposed.
	 */
	private function align( array &$ops, array $a, array $b ): void {
		$n = count( $a );
		$m = count( $b );
		$l = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; --$i ) {
			for ( $j = $m - 1; $j >= 0; --$j ) {
				$l[ $i ][ $j ] = $a[ $i ] === $b[ $j ]
					? $l[ $i + 1 ][ $j + 1 ] + 1
					: max( $l[ $i + 1 ][ $j ], $l[ $i ][ $j + 1 ] );
			}
		}
		$i = 0;
		$j = 0;
		while ( $i < $n && $j < $m ) {
			if ( $a[ $i ] === $b[ $j ] ) {
				$this->push( $ops, 'eq', array( $a[ $i ] ) );
				++$i;
				++$j;
			} elseif ( $l[ $i + 1 ][ $j ] >= $l[ $i ][ $j + 1 ] ) {
				$this->push( $ops, 'del', array( $a[ $i ] ) );
				++$i;
			} else {
				$this->push( $ops, 'ins', array( $b[ $j ] ) );
				++$j;
			}
		}
		$this->push( $ops, 'del', array_slice( $a, $i ) );
		$this->push( $ops, 'ins', array_slice( $b, $j ) );
	}

	/**
	 * Append tokens as one op, merging into the previous op of the same kind.
	 *
	 * @param list<array{t:string,text:string}> $ops    Ops, by reference.
	 * @param string                                 $type   eq|ins|del.
	 * @param list<string>                           $tokens Tokens.
	 */
	private function push( array &$ops, string $type, array $tokens ): void {
		if ( array() === $tokens ) {
			return;
		}
		$text = implode( ' ', $tokens );
		$last = count( $ops ) - 1;
		if ( $last >= 0 && $ops[ $last ]['t'] === $type ) {
			$ops[ $last ]['text'] .= ' ' . $text;
			return;
		}
		$ops[] = array(
			't'    => $type,
			'text' => $text,
		);
	}

	/**
	 * Markup-stripped words: block comments, tags, scripts and styles gone,
	 * entities decoded, whitespace collapsed.
	 *
	 * @param string $markup Raw content.
	 * @return list<string>
	 */
	private function words( string $markup ): array {
		$markup = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $markup );
		$markup = (string) preg_replace( '#</?(p|br|div|h[1-6]|li|ul|ol|tr|td|th|blockquote|figure|figcaption)\b[^>]*>#i', ' ', $markup );
		$text   = html_entity_decode( wp_strip_all_tags( $markup ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$words  = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

		return false === $words ? array() : $words;
	}

	/**
	 * Equal as strings, or as numbers when both are numeric (`10` is `10.00`).
	 *
	 * @param string $a First.
	 * @param string $b Second.
	 */
	private function same( string $a, string $b ): bool {
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return (float) $a === (float) $b;
		}

		return $a === $b;
	}

	/**
	 * Cap a scalar for the card; the full value stays in the arguments below it.
	 *
	 * @param string $value Value.
	 */
	private function clip( string $value ): string {
		return mb_strlen( $value ) > self::MAX_SCALAR ? mb_substr( $value, 0, self::MAX_SCALAR ) . '…' : $value;
	}

	/**
	 * An integer id from the args, 0 when absent or not a positive integer.
	 *
	 * @param array<string,mixed> $args Call args.
	 * @param string              $key  Arg name.
	 */
	private function id( array $args, string $key ): int {
		$value = $args[ $key ] ?? null;

		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : 0;
	}
}
