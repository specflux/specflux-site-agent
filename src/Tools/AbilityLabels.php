<?php
/**
 * Human labels for ability ids ("content/publish-page" -> "Publish page").
 *
 * The label is the one the registering plugin gave the ability
 * (`wp_get_ability()->get_label()`); the Runs screen shows it with the id as
 * secondary text. Display only: nothing here decides what may run.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tools;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/** Looks up registered ability labels. */
final class AbilityLabels {

	/**
	 * The registered label for an ability id or function name, or '' when none is registered.
	 *
	 * @param string $verb `ns/name`, `ns__name` or `wpab__ns__name`.
	 */
	public static function label( string $verb ): string {
		if ( '' === $verb || ! function_exists( 'wp_get_ability' ) ) {
			return '';
		}
		$name = str_contains( $verb, '/' ) ? $verb : str_replace( '__', '/', (string) preg_replace( '/^wpab__/', '', $verb ) );
		if ( ! str_contains( $name, '/' ) ) {
			return '';
		}
		// Virtual tools (specflux/propose-plan, ...) are not registered abilities; asking core for
		// one raises a _doing_it_wrong notice, so probe silently first.
		if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( $name ) ) {
			return '';
		}
		$ability = wp_get_ability( $name );

		return null !== $ability ? trim( $ability->get_label() ) : '';
	}

	/** The registered label, else the ability slug as a sentence ("publish-page" -> "Publish page"). */
	public static function display( string $verb ): string {
		$label = self::label( $verb );
		if ( '' !== $label ) {
			return $label;
		}
		$parts = preg_split( '#/|__#', $verb );
		$slug  = is_array( $parts ) ? (string) end( $parts ) : $verb;
		$words = trim( (string) preg_replace( '/[-_]+/', ' ', $slug ) );

		return '' === $words ? $verb : ucfirst( $words );
	}

	/**
	 * Labels for every verb a set of steps (and the card facts) mentions, keyed by the verb as written.
	 *
	 * @param array<int,mixed>          $steps Steps as arrays (`message`, `tool_name`).
	 * @param array<string,mixed>|null  $ui    The `ui` facts (`approval.verb`).
	 * @return array<string,string>
	 */
	public static function forSteps( array $steps, ?array $ui = null ): array {
		$verbs = array();
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$message = is_array( $step['message'] ?? null ) ? $step['message'] : array();
			foreach ( array( $message['verb'] ?? null, $message['plan_verb'] ?? null, $step['tool_name'] ?? null ) as $verb ) {
				if ( is_string( $verb ) && '' !== $verb ) {
					$verbs[ $verb ] = true;
				}
			}
			foreach ( is_array( $message['steps'] ?? null ) ? $message['steps'] : array() as $plan_step ) {
				foreach ( is_array( $plan_step ) && is_array( $plan_step['verbs'] ?? null ) ? $plan_step['verbs'] : array() as $verb ) {
					if ( is_string( $verb ) && '' !== $verb ) {
						$verbs[ $verb ] = true;
					}
				}
			}
		}
		$approval = is_array( $ui['approval'] ?? null ) ? $ui['approval'] : array();
		if ( is_string( $approval['verb'] ?? null ) && '' !== $approval['verb'] ) {
			$verbs[ $approval['verb'] ] = true;
		}

		$labels = array();
		foreach ( array_keys( $verbs ) as $verb ) {
			$label = self::label( (string) $verb );
			if ( '' !== $label ) {
				$labels[ (string) $verb ] = $label;
			}
		}

		return $labels;
	}
}
