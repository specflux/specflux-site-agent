<?php
/**
 * Test-only mail and site-name shims: wp_mail() records instead of sending.
 *
 *   $GLOBALS['specflux_site_agent_test_mail'] — list of {to,subject,body,headers}.
 *   $GLOBALS['specflux_site_agent_test_blogname'] — get_bloginfo( 'name' ).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

$GLOBALS['specflux_site_agent_test_mail'] = $GLOBALS['specflux_site_agent_test_mail'] ?? array();

if ( ! function_exists( 'wp_mail' ) ) {
	/**
	 * Record the message.
	 *
	 * @param string|string[] $to      Recipient.
	 * @param string          $subject Subject.
	 * @param string          $message Body.
	 * @param string|string[] $headers Headers.
	 */
	function wp_mail( $to, string $subject, string $message, $headers = '' ): bool {
		$GLOBALS['specflux_site_agent_test_mail'][] = array(
			'to'      => $to,
			'subject' => $subject,
			'body'    => $message,
			'headers' => $headers,
		);

		return true;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		return 'name' === $show ? (string) ( $GLOBALS['specflux_site_agent_test_blogname'] ?? 'Example Site' ) : '';
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( string $text, int $flags = ENT_NOQUOTES ): string {
		return htmlspecialchars_decode( $text, $flags );
	}
}
