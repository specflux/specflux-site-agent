<?php
/**
 * Turns a tool result into the `display` payload the Runs screen renders.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Present;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

use Specflux\SenroFlux\Present\Presenters\CouponPresenter;
use Specflux\SenroFlux\Present\Presenters\ListPresenter;
use Specflux\SenroFlux\Present\Presenters\MediaPresenter;
use Specflux\SenroFlux\Present\Presenters\NavigationPresenter;
use Specflux\SenroFlux\Present\Presenters\PostPresenter;
use Specflux\SenroFlux\Present\Presenters\PreviewLinkPresenter;
use Specflux\SenroFlux\Present\Presenters\ProductPresenter;
use Specflux\SenroFlux\Present\Presenters\ReportPresenter;
use Throwable;

/**
 * 0.4 S3.1 part 1. Computed at READ time (when the run is fetched), not
 * stored: the payload is a pure function of the stored response, so it needs
 * no schema change, a better presenter improves old runs too, and a presenter
 * bug can never corrupt a persisted step.
 *
 * Presenters are tried in order; the first whose `supports()` is true wins.
 * Nothing is ever shown as raw JSON: an unknown shape becomes a key/value
 * table of scalars, nesting flattened to two levels, and an error becomes
 * readable text.
 */
final class ResultPresenter {

	private const FALLBACK_MAX_ROWS = 25;

	private const LIST_JOIN_CAP = 5;

	/** @var list<Presenter> */
	private array $presenters;

	/**
	 * @param list<Presenter>|null $presenters Presenters in priority order; the built-ins, through the filter, when null.
	 * @param Links|null           $links      Link builder; WordPress's when null.
	 */
	public function __construct( ?array $presenters = null, private ?Links $links = null ) {
		$this->presenters = $presenters ?? self::resolvePresenters();
		$this->links      = $links ?? new WpLinks();
	}

	/**
	 * The built-in presenters, passed through `senroflux_result_presenters`.
	 *
	 * @return list<Presenter>
	 */
	private static function resolvePresenters(): array {
		$builtins = array(
			new PreviewLinkPresenter(),
			new PostPresenter(),
			new MediaPresenter(),
			new ProductPresenter(),
			new CouponPresenter(),
			new NavigationPresenter(),
			new ReportPresenter(),
			new ListPresenter(),
		);

		/**
		 * Filters the result presenters, tried in order (first match wins).
		 * `@api` (0.4). Append to handle shapes the built-ins do not know;
		 * prepend to override one. Entries that are not {@see Presenter}s
		 * are ignored.
		 *
		 * @param list<Presenter> $presenters The built-in presenters.
		 */
		$filtered = apply_filters( 'senroflux_result_presenters', $builtins );

		return is_array( $filtered )
			? array_values( array_filter( $filtered, static fn ( $p ): bool => $p instanceof Presenter ) )
			: $builtins;
	}

	/**
	 * The display payload for one tool_result step.
	 *
	 * @param string              $tool     The step's tool name (function name or ability name).
	 * @param array<string,mixed> $response The FunctionResponse's response.
	 * @param string              $status   The step's status (`ok` or `error`).
	 * @return array<string,mixed>
	 */
	public function present( string $tool, array $response, string $status = 'ok' ): array {
		$error = $this->errorMessage( $response, $status );
		if ( null !== $error ) {
			return array(
				'type'    => 'error',
				'title'   => __( 'That did not work', 'senroflux' ),
				'message' => $error,
			);
		}

		$ability = self::abilityName( $tool );
		foreach ( $this->presenters as $presenter ) {
			try {
				if ( ! $presenter->supports( $ability, $response ) ) {
					continue;
				}
				$payload = $presenter->present( $ability, $response, $this->links ?? new WpLinks() );
			} catch ( Throwable ) {
				// A faulty presenter must not take the run screen down; the
				// fallback still shows the data.
				continue;
			}
			if ( is_string( $payload['type'] ?? null ) && is_string( $payload['title'] ?? null ) ) {
				return $payload;
			}
		}

		return $this->fallback( $ability, $response );
	}

	/**
	 * The readable error text, or null when the result is not an error.
	 *
	 * @param array<string,mixed> $response Response.
	 * @param string              $status   Step status.
	 */
	private function errorMessage( array $response, string $status ): ?string {
		$has_error = array_key_exists( 'error', $response );
		if ( ! $has_error && 'error' !== $status ) {
			return null;
		}

		$message = Fmt::text( $response['error'] ?? $response['message'] ?? '' );

		return '' !== $message ? $message : __( 'The tool returned an error with no message.', 'senroflux' );
	}

	/**
	 * Key/value table for a shape no presenter knows: scalars only, nesting
	 * flattened to two levels (`parent.child`); anything deeper, and any
	 * list of structures, collapses to a count.
	 *
	 * @param string              $ability  Ability name.
	 * @param array<string,mixed> $response Response.
	 * @return array<string,mixed>
	 */
	private function fallback( string $ability, array $response ): array {
		$rows = array();
		foreach ( $response as $key => $value ) {
			$label = Fmt::text( $key );
			if ( is_array( $value ) && ! array_is_list( $value ) ) {
				foreach ( $value as $child_key => $child ) {
					$rows[] = array( $label . '.' . Fmt::text( $child_key ), $this->cell( $child ) );
				}
				continue;
			}
			$rows[] = array( $label, $this->cell( $value ) );
		}

		$more = max( 0, count( $rows ) - self::FALLBACK_MAX_ROWS );

		return array(
			'type'    => 'table',
			'title'   => '' !== $ability ? $ability : __( 'Result', 'senroflux' ),
			'columns' => array( __( 'Field', 'senroflux' ), __( 'Value', 'senroflux' ) ),
			'rows'    => array_slice( $rows, 0, self::FALLBACK_MAX_ROWS ),
			'more'    => $more,
		);
	}

	/**
	 * One table cell: a scalar as text, a list of scalars joined, anything
	 * structured as a count.
	 *
	 * @param mixed $value Any value.
	 */
	private function cell( mixed $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( ! is_array( $value ) ) {
			return Fmt::text( $value );
		}
		if ( array() === $value ) {
			return '';
		}

		$scalars = array_is_list( $value ) && array() === array_filter( $value, static fn ( $v ): bool => ! is_scalar( $v ) );
		if ( $scalars ) {
			$shown = array_map( array( Fmt::class, 'text' ), array_slice( $value, 0, self::LIST_JOIN_CAP ) );
			$extra = count( $value ) - count( $shown );

			return implode( ', ', $shown ) . ( $extra > 0 ? sprintf( /* translators: %d: number of further items. */ __( ' (+%d more)', 'senroflux' ), $extra ) : '' );
		}

		return sprintf( /* translators: %d: number of items. */ _n( '%d item', '%d items', count( $value ), 'senroflux' ), count( $value ) );
	}

	/**
	 * Ability name from a tool name (`wpab__ns__name` or `ns/name`).
	 *
	 * @param string $tool Tool name.
	 */
	private static function abilityName( string $tool ): string {
		if ( str_starts_with( $tool, 'wpab__' ) ) {
			$tool = substr( $tool, strlen( 'wpab__' ) );
		}

		return str_replace( '__', '/', $tool );
	}
}
