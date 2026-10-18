<?php
/**
 * Action Scheduler and user-switching doubles for the background driver.
 *
 * An in-memory queue behind the as_* functions the plugin calls, with Action
 * Scheduler's own uniqueness rule (a PENDING or RUNNING action with the same
 * hook, args and group blocks a unique insert), plus ActionScheduler_Versions
 * driven by a knob and wp_set_current_user() over the current-user knob.
 *
 * Knobs:
 *   $GLOBALS['specflux_site_agent_test_as']          list of action rows (id, hook, args, group, when, status, unique)
 *   $GLOBALS['specflux_site_agent_test_as_version']  string|false, the "loaded" Action Scheduler version
 *   $GLOBALS['specflux_site_agent_test_user_history'] every id wp_set_current_user() received, in order
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

$GLOBALS['specflux_site_agent_test_as']           = array();
$GLOBALS['specflux_site_agent_test_as_version']   = '4.2.0';
$GLOBALS['specflux_site_agent_test_user_history'] = array();

if ( ! class_exists( 'ActionScheduler_Versions', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, PEAR.NamingConventions.ValidClassName.Invalid
	class ActionScheduler_Versions {

		public static function instance(): self {
			return new self();
		}

		public function latest_version(): string|false {
			return $GLOBALS['specflux_site_agent_test_as_version'];
		}
	}
}

if ( ! function_exists( 'specflux_site_agent_test_as_match' ) ) {
	/** Does a queued row match hook / args / group (null args or empty group = any)? */
	function specflux_site_agent_test_as_match( array $row, string $hook, ?array $args, string $group ): bool {
		return ( '' === $hook || $row['hook'] === $hook )
			&& ( null === $args || $row['args'] === $args )
			&& ( '' === $group || $row['group'] === $group );
	}
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
		unset( $priority );
		if ( $unique ) {
			foreach ( $GLOBALS['specflux_site_agent_test_as'] as $row ) {
				if ( in_array( $row['status'], array( 'pending', 'running' ), true ) && specflux_site_agent_test_as_match( $row, $hook, $args, $group ) ) {
					return 0;
				}
			}
		}
		$id = count( $GLOBALS['specflux_site_agent_test_as'] ) + 1;
		$GLOBALS['specflux_site_agent_test_as'][ $id ] = array(
			'id'        => $id,
			'hook'      => $hook,
			'args'      => $args,
			'group'     => $group,
			'when'      => (int) $timestamp,
			'status'    => 'pending',
			'unique'    => (bool) $unique,
			'recurring' => 0,
		);

		return $id;
	}
}

if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
		$id = as_schedule_single_action( $timestamp, $hook, $args, $group, $unique, $priority );
		if ( $id > 0 ) {
			$GLOBALS['specflux_site_agent_test_as'][ $id ]['recurring'] = (int) $interval;
		}

		return $id;
	}
}

if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
		foreach ( $GLOBALS['specflux_site_agent_test_as'] as $row ) {
			if ( in_array( $row['status'], array( 'pending', 'running' ), true ) && specflux_site_agent_test_as_match( $row, $hook, $args, $group ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
	function as_get_scheduled_actions( $query = array(), $return_format = 'OBJECT' ) {
		unset( $return_format );
		$ids = array();
		foreach ( $GLOBALS['specflux_site_agent_test_as'] as $row ) {
			if (
				( $query['status'] ?? $row['status'] ) === $row['status']
				&& specflux_site_agent_test_as_match( $row, (string) ( $query['hook'] ?? '' ), $query['args'] ?? null, (string) ( $query['group'] ?? '' ) )
			) {
				$ids[] = $row['id'];
			}
		}

		return array_slice( $ids, 0, (int) ( $query['per_page'] ?? 5 ) );
	}
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
		foreach ( $GLOBALS['specflux_site_agent_test_as'] as $id => $row ) {
			if ( specflux_site_agent_test_as_match( $row, (string) $hook, array() === $args ? null : $args, (string) $group ) ) {
				unset( $GLOBALS['specflux_site_agent_test_as'][ $id ] );
			}
		}
	}
}

if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( int $id ) {
		$GLOBALS['specflux_site_agent_test_user_history'][]  = $id;
		$GLOBALS['specflux_site_agent_test_current_user_id'] = $id;

		return null;
	}
}
