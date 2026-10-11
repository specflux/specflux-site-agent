<?php
/**
 * Suggested privacy-policy text for Settings → Privacy.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Admin;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the text a site owner can copy into their privacy policy. No
 * exporter or eraser: runs are an operational log, not account data.
 */
class PrivacyPolicy {

	/** Register on admin_init, the hook core documents for this call. */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add' ) );
	}

	/** Hand the suggested text to core's privacy guide. */
	public function add(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content( 'Specflux Site Agent', wp_kses_post( wpautop( $this->content(), false ) ) );
	}

	/**
	 * The suggested policy text, as plain paragraphs.
	 */
	public function content(): string {
		$paragraphs = array(
			__( 'What Specflux Site Agent stores. When someone on this site starts a run, Specflux Site Agent saves it in two custom database tables: the ID of the user who started it, the goal they typed, the conversation with the AI model (including your site brief), the tool calls the run made and their results, and timestamps. Runs are kept until you remove the rows yourself or delete the plugin with the "delete data on uninstall" option switched on. They are not shared with anyone by Specflux Site Agent itself.', 'specflux-site-agent' ),
			__( 'What is sent to your AI provider. To do its work, Specflux Site Agent sends the goal, the site brief, the conversation so far and tool results (which can include page, post and, with WooCommerce, store content) to the AI provider connected on this site. Which provider that is, and how it handles the data, is set by the provider you chose; link its privacy policy here.', 'specflux-site-agent' ),
			__( 'What is sent to Openverse. When a run looks for a stock photo, the search terms the AI model chose are sent to Openverse (api.openverse.org), operated by WordPress.org. No user or site details are sent. Specflux Site Agent then downloads the chosen image from the address Openverse returns, which is hosted by a third-party site.', 'specflux-site-agent' ),
		);

		return implode( "\n\n", $paragraphs );
	}
}
