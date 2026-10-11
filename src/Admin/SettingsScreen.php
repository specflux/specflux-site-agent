<?php
/**
 * Specflux Site Agent → Settings: the site brief (0.3 S20).
 *
 * TARGET REPO PATH: src/Admin/SettingsScreen.php
 *
 * A submenu page under the existing top-level "Specflux Site Agent" menu
 * ({@see RunsScreen}), capability `manage_options`. `[assumed]` (S20): a
 * plain admin-post form with a nonce, matching every other mutation on the
 * Runs screen, rather than the full `register_setting()`/`options.php`
 * machinery — the load-bearing behaviour S20 asks for (submenu placement,
 * the capability, a nonce, the length cap refusing rather than truncating,
 * and clearing on empty) is identical either way, and this keeps one human
 * mutation pattern across the plugin. _Overturn: move to `register_setting()`
 * if a stranger walkthrough expects the native Settings UI chrome._
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Admin;

use Specflux\SiteAgent\Packs\Pages\ThemePatterns;
use Specflux\SiteAgent\Run\SiteBrief;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Renders + saves the site brief and the theme-patterns switch.
 */
class SettingsScreen {

	private const SLUG = 'specflux-site-agent-settings';

	/** Transient holding a REFUSED submission's text, keyed by user id (never truncated, never lost). */
	private const PENDING_TRANSIENT_PREFIX = 'specflux_site_agent_brief_pending_';

	/** Register on admin_menu + admin_post. */
	public function register(): void {
		add_action( 'init', array( $this, 'registerSettings' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_specflux_site_agent_save_brief', array( $this, 'handleSave' ) );
	}

	/**
	 * D2: the theme-patterns switch as a registered boolean setting (default
	 * true, sanitised to a bool). It is saved by {@see handleSave()} with the
	 * brief, never through `options.php`.
	 */
	public function registerSettings(): void {
		register_setting(
			'specflux-site-agent',
			ThemePatterns::OPTION,
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => array( ThemePatterns::class, 'sanitizeOption' ),
				'show_in_rest'      => false,
			)
		);
	}

	/** The capability required for this screen (S20: fixed at manage_options). */
	public function capability(): string {
		return 'manage_options';
	}

	/** The "Settings" submenu under the existing top-level "Specflux Site Agent" menu. */
	public function menu(): void {
		add_submenu_page(
			'specflux-site-agent-runs',
			__( 'Specflux Site Agent Settings', 'specflux-site-agent' ),
			__( 'Settings', 'specflux-site-agent' ),
			$this->capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** admin-post handler: save (or clear) the site brief. */
	public function handleSave(): void {
		check_admin_referer( 'specflux_site_agent_save_brief' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}

		// Plain text (S20): stripped of markup, but newlines kept — a
		// textarea field, not a single-line input.
		$text = sanitize_textarea_field( wp_unslash( $_POST['specflux_site_agent_site_brief'] ?? '' ) );

		$saved = SiteBrief::set( $text );
		$user  = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( is_wp_error( $saved ) ) {
			// Refused: the text is kept in the form, never truncated, never
			// silently reverted to the last-saved value.
			set_transient( self::PENDING_TRANSIENT_PREFIX . $user, $text, MINUTE_IN_SECONDS );
			$this->redirect( (string) $saved->get_error_code() );
			return;
		}

		// D2: an unticked checkbox is absent from the POST, so the form carries
		// a marker saying the switch was on the page. A refused brief saves
		// nothing, switch included.
		if ( isset( $_POST['specflux_site_agent_theme_patterns_present'] ) ) {
			$use_theme_patterns = isset( $_POST[ ThemePatterns::OPTION ] ) && is_string( $_POST[ ThemePatterns::OPTION ] )
				? sanitize_text_field( wp_unslash( $_POST[ ThemePatterns::OPTION ] ) )
				: false;
			update_option( ThemePatterns::OPTION, ThemePatterns::sanitizeOption( $use_theme_patterns ) );
		}

		delete_transient( self::PENDING_TRANSIENT_PREFIX . $user );
		$this->redirect( null );
	}

	/** The one place a redirect-then-stop goes through (test override seam). */
	protected function redirect( ?string $error_code ): void {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		if ( null !== $error_code ) {
			$url = add_query_arg( array( 'specflux_site_agent_brief_error' => $error_code ), $url );
		} else {
			$url = add_query_arg( array( 'specflux_site_agent_brief_saved' => '1' ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/** The text this form should show: a pending refused submission, else the saved brief. */
	private function formText(): string {
		$user    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$pending = get_transient( self::PENDING_TRANSIENT_PREFIX . $user );

		return is_string( $pending ) ? $pending : SiteBrief::get();
	}

	/** Render the settings page. */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Specflux Site Agent Settings', 'specflux-site-agent' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
		$error = isset( $_GET['specflux_site_agent_brief_error'] ) ? sanitize_text_field( wp_unslash( $_GET['specflux_site_agent_brief_error'] ) ) : '';
		if ( SiteBrief::ERROR_TOO_LONG === $error ) {
			printf(
				'<div data-specflux-site-agent-notice class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d is the character cap. */
						__( 'The site brief may be at most %d characters. Nothing was saved — shorten it and try again.', 'specflux-site-agent' ),
						SiteBrief::MAX_CHARS
					)
				)
			);
		} elseif ( '' !== $error ) {
			echo '<div data-specflux-site-agent-notice class="notice notice-error"><p>' . esc_html__( 'The site brief could not be saved.', 'specflux-site-agent' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
		if ( isset( $_GET['specflux_site_agent_brief_saved'] ) ) {
			echo '<div data-specflux-site-agent-notice class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'specflux-site-agent' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="specflux_site_agent_save_brief">';
		wp_nonce_field( 'specflux_site_agent_save_brief' );

		echo '<p>' . esc_html__( 'The site owner\'s standing notes for Specflux Site Agent: tone and content guidance every run reads. It never changes what needs approval, budgets or the plan.', 'specflux-site-agent' ) . '</p>';
		echo '<p><label for="specflux-site-agent-site-brief" class="screen-reader-text">' . esc_html__( 'Site brief', 'specflux-site-agent' ) . '</label>';
		printf(
			'<textarea id="specflux-site-agent-site-brief" name="specflux_site_agent_site_brief" rows="10" class="large-text code" maxlength="%d">%s</textarea></p>',
			esc_attr( (string) ( SiteBrief::MAX_CHARS * 4 ) ), // A generous client-side ceiling; the server refusal is authoritative (multi-byte chars).
			esc_textarea( $this->formText() )
		);

		echo '<input type="hidden" name="specflux_site_agent_theme_patterns_present" value="1">';
		printf(
			'<p><label for="specflux-site-agent-use-theme-patterns"><input type="checkbox" id="specflux-site-agent-use-theme-patterns" name="%s" value="1"%s> %s</label><br><span class="description">%s</span></p>',
			esc_attr( ThemePatterns::OPTION ),
			checked( ThemePatterns::enabled(), true, false ),
			esc_html__( 'Use my theme\'s block patterns', 'specflux-site-agent' ),
			esc_html__( 'Build pages from the active theme\'s own patterns where they fit. Untick to build every page from Specflux Site Agent\'s own layouts.', 'specflux-site-agent' )
		);

		printf(
			'<p><button type="submit" class="button button-primary">%s</button></p>',
			esc_html__( 'Save settings', 'specflux-site-agent' )
		);

		echo '</form></div>';
	}
}
