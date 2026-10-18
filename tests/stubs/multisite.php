<?php
/**
 * Multisite doubles: a fake network whose sites each own an options store, a
 * cron table, an Action Scheduler queue and a table prefix, swapped in and out
 * by switch_to_blog() / restore_current_blog().
 *
 * Knobs (all optional; single-site tests never touch them):
 *   $GLOBALS['specflux_site_agent_test_multisite']           bool, is_multisite()
 *   $GLOBALS['specflux_site_agent_test_subdomain_install']   bool, default true
 *   $GLOBALS['specflux_site_agent_test_site_options']        network (site) options
 *   $GLOBALS['specflux_site_agent_test_blog_members']        blog id => list of user ids (unset = everyone)
 *   $GLOBALS['specflux_site_agent_test_get_sites_calls']     every get_sites() argument array
 *   $GLOBALS['specflux_site_agent_test_network']             internals; build it with specflux_site_agent_test_network()
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

if ( ! function_exists( 'specflux_site_agent_test_network' ) ) {
	/**
	 * Build a network with the given site ids; site 1 is the main site and current.
	 *
	 * @param list<int> $ids Site ids.
	 */
	function specflux_site_agent_test_network( array $ids ): void {
		$sites = array();
		foreach ( $ids as $id ) {
			$sites[ $id ] = array(
				'options' => array(),
				'cron'    => array(),
				'as'      => array(),
				'prefix'  => 1 === $id ? 'wp_' : 'wp_' . $id . '_',
			);
		}
		$first = $ids[0];
		// The live globals ARE the current site's state.
		$GLOBALS['specflux_site_agent_test_options']           = array();
		$GLOBALS['specflux_site_agent_test_cron']              = array();
		$GLOBALS['specflux_site_agent_test_as']                = array();
		$GLOBALS['specflux_site_agent_test_network']           = array(
			'current' => $first,
			'stack'   => array(),
			'sites'   => $sites,
			'main'    => 1,
			'log'     => array(),
		);
		$GLOBALS['specflux_site_agent_test_multisite']         = true;
		$GLOBALS['specflux_site_agent_test_subdomain_install'] = true;
		$GLOBALS['specflux_site_agent_test_site_options']      = array();
		$GLOBALS['specflux_site_agent_test_get_sites_calls']   = array();
		if ( isset( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb']->prefix = $sites[ $first ]['prefix'];
		}
	}

	function specflux_site_agent_test_network_reset(): void {
		unset(
			$GLOBALS['specflux_site_agent_test_network'],
			$GLOBALS['specflux_site_agent_test_subdomain_install'],
			$GLOBALS['specflux_site_agent_test_site_options'],
			$GLOBALS['specflux_site_agent_test_blog_members'],
			$GLOBALS['specflux_site_agent_test_get_sites_calls']
		);
		$GLOBALS['specflux_site_agent_test_multisite'] = false;
	}

	/** Park the live per-site globals into the current site's slot, then load another. */
	function specflux_site_agent_test_network_load( int $to ): void {
		$net                              = &$GLOBALS['specflux_site_agent_test_network'];
		$from                             = $net['current'];
		$net['sites'][ $from ]['options'] = $GLOBALS['specflux_site_agent_test_options'];
		$net['sites'][ $from ]['cron']    = $GLOBALS['specflux_site_agent_test_cron'];
		$net['sites'][ $from ]['as']      = $GLOBALS['specflux_site_agent_test_as'];

		$GLOBALS['specflux_site_agent_test_options'] = $net['sites'][ $to ]['options'];
		$GLOBALS['specflux_site_agent_test_cron']    = $net['sites'][ $to ]['cron'];
		$GLOBALS['specflux_site_agent_test_as']      = $net['sites'][ $to ]['as'];
		if ( isset( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb']->prefix = $net['sites'][ $to ]['prefix'];
		}
		$net['current'] = $to;
	}

	/** A site's stored state, whether or not it is current. */
	function specflux_site_agent_test_site_state( int $id ): array {
		$net = $GLOBALS['specflux_site_agent_test_network'];
		if ( $net['current'] === $id ) {
			return array(
				'options' => $GLOBALS['specflux_site_agent_test_options'],
				'cron'    => $GLOBALS['specflux_site_agent_test_cron'],
				'as'      => $GLOBALS['specflux_site_agent_test_as'],
			);
		}

		return $net['sites'][ $id ];
	}
}

if ( ! function_exists( 'is_subdomain_install' ) ) {
	function is_subdomain_install(): bool {
		return ! isset( $GLOBALS['specflux_site_agent_test_subdomain_install'] ) || ! empty( $GLOBALS['specflux_site_agent_test_subdomain_install'] );
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return (int) ( $GLOBALS['specflux_site_agent_test_network']['current'] ?? 1 );
	}
}

if ( ! function_exists( 'is_main_site' ) ) {
	function is_main_site( ?int $site_id = null ): bool {
		return ( $site_id ?? get_current_blog_id() ) === (int) ( $GLOBALS['specflux_site_agent_test_network']['main'] ?? 1 );
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( int $new_blog_id ): bool {
		$net = &$GLOBALS['specflux_site_agent_test_network'];
		if ( ! isset( $net['sites'][ $new_blog_id ] ) ) {
			return false;
		}
		$net['stack'][] = $net['current'];
		$net['log'][]   = $new_blog_id;
		specflux_site_agent_test_network_load( $new_blog_id );

		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog(): bool {
		$net = &$GLOBALS['specflux_site_agent_test_network'];
		if ( array() === $net['stack'] ) {
			return false;
		}
		specflux_site_agent_test_network_load( (int) array_pop( $net['stack'] ) );

		return true;
	}
}

if ( ! function_exists( 'get_sites' ) ) {
	/** @return list<int> Only `fields => ids` is supported. */
	function get_sites( array $args = array() ): array {
		$GLOBALS['specflux_site_agent_test_get_sites_calls'][] = $args;
		$ids = array_keys( $GLOBALS['specflux_site_agent_test_network']['sites'] ?? array() );
		sort( $ids );

		return array_slice( $ids, (int) ( $args['offset'] ?? 0 ), (int) ( $args['number'] ?? 100 ) );
	}
}

if ( ! function_exists( 'is_user_member_of_blog' ) ) {
	function is_user_member_of_blog( int $user_id = 0, int $blog_id = 0 ): bool {
		$blog_id = 0 === $blog_id ? get_current_blog_id() : $blog_id;
		$members = $GLOBALS['specflux_site_agent_test_blog_members'] ?? null;

		return null === $members || in_array( $user_id, $members[ $blog_id ] ?? array(), true );
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $option, mixed $fallback = false ): mixed {
		return $GLOBALS['specflux_site_agent_test_site_options'][ $option ] ?? $fallback;
	}
}
