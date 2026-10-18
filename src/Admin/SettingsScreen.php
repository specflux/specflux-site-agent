<?php
/**
 * Site Agent → Settings: the site brief (0.3 S20), theme patterns, store platform and
 * the abilities other plugins register (0.4 S6).
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

use Specflux\SiteAgent\Admin\Ui\Kit;
use Specflux\SiteAgent\Admin\Ui\Shell;
use Specflux\SiteAgent\Connector\AppsScreen;
use Specflux\SiteAgent\Connector\Connector;
use Specflux\SiteAgent\Packs\Commerce\Platforms\PlatformSelection;
use Specflux\SiteAgent\Packs\Commerce\Platforms\StorePlatforms;
use Specflux\SiteAgent\Packs\Pages\ThemePatterns;
use Specflux\SiteAgent\Passthrough\AbilityReviewProbe;
use Specflux\SiteAgent\Passthrough\Passthrough;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\GateMode;
use Specflux\SiteAgent\Run\SiteBrief;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Renders + saves the site brief and the theme-patterns switch.
 */
class SettingsScreen {

	/** Page slug (the Settings tab). */
	public const SLUG = Shell::SETTINGS;

	/** Transient holding a REFUSED submission's text, keyed by user id (never truncated, never lost). */
	private const PENDING_TRANSIENT_PREFIX = 'specflux_site_agent_brief_pending_';

	/** Register on admin_menu + admin_post. */
	public function register(): void {
		add_action( 'init', array( $this, 'registerSettings' ) );
		add_action( 'admin_post_specflux_site_agent_save_brief', array( $this, 'handleSave' ) );
		add_action( 'admin_post_specflux_site_agent_save_passthrough', array( $this, 'handleSavePassthrough' ) );
		add_action( 'admin_post_specflux_site_agent_save_store_platform', array( $this, 'handleSaveStorePlatform' ) );
		add_action( 'admin_post_specflux_site_agent_save_connector', array( $this, 'handleSaveConnector' ) );
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
		register_setting(
			'specflux-site-agent',
			StorePlatforms::OPTION,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( StorePlatforms::class, 'sanitizeOption' ),
				'show_in_rest'      => false,
			)
		);
	}

	/** admin-post handler: choose the store platform commerce runs use (C4-0). */
	public function handleSaveStorePlatform(): void {
		check_admin_referer( 'specflux_site_agent_save_store_platform' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}

		$posted = isset( $_POST[ StorePlatforms::OPTION ] ) && is_string( $_POST[ StorePlatforms::OPTION ] )
			? sanitize_key( wp_unslash( $_POST[ StorePlatforms::OPTION ] ) )
			: '';
		$saved  = StorePlatforms::save( $posted );

		$this->redirectPassthrough( is_wp_error( $saved ) ? (string) $saved->get_error_code() : null );
	}

	/** The capability required for this screen (S20: fixed at manage_options). */
	public function capability(): string {
		return 'manage_options';
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

	/** admin-post handler: save the "Abilities from other plugins" switches (0.4 S6). */
	public function handleSavePassthrough(): void {
		check_admin_referer( 'specflux_site_agent_save_passthrough' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}

		$enabled = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every element is sanitize_text_field()'d in the loop below.
		$posted_enabled = isset( $_POST['specflux_site_agent_pt_enabled'] ) ? wp_unslash( $_POST['specflux_site_agent_pt_enabled'] ) : null;
		if ( is_array( $posted_enabled ) ) {
			foreach ( $posted_enabled as $name ) {
				if ( is_string( $name ) ) {
					$enabled[] = sanitize_text_field( $name );
				}
			}
		}

		$read = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every key and value is sanitized in the loop below.
		$posted_mode = isset( $_POST['specflux_site_agent_pt_mode'] ) ? wp_unslash( $_POST['specflux_site_agent_pt_mode'] ) : null;
		if ( is_array( $posted_mode ) ) {
			foreach ( $posted_mode as $name => $mode ) {
				if ( is_string( $name ) && is_string( $mode ) ) {
					$read[ sanitize_text_field( $name ) ] = sanitize_key( $mode );
				}
			}
		}

		$saved = Passthrough::save( $enabled, $read );
		if ( is_wp_error( $saved ) ) {
			$this->redirectPassthrough( (string) $saved->get_error_code() );
			return;
		}

		$this->redirectPassthrough( null );
	}

	/** admin-post handler: save the "Allow AI apps to connect" switch (K1). */
	public function handleSaveConnector(): void {
		check_admin_referer( 'specflux_site_agent_save_connector' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}

		$posted = isset( $_POST[ Connector::OPTION ] ) && is_string( $_POST[ Connector::OPTION ] )
			? sanitize_text_field( wp_unslash( $_POST[ Connector::OPTION ] ) )
			: '0';
		update_option( Connector::OPTION, Connector::sanitize( $posted ) ? '1' : '0' );

		$this->redirectConnector();
	}

	/** Back to the AI apps tab, where the switch now lives (test override seam). */
	protected function redirectConnector(): void {
		wp_safe_redirect( Shell::url( 'apps', array( AppsScreen::SAVED_FLAG => '1' ) ) );
		exit;
	}

	/** Redirect back to the passthrough section (test override seam). */
	protected function redirectPassthrough( ?string $error_code ): void {
		wp_safe_redirect(
			Shell::url(
				'settings',
				null !== $error_code ? array( 'specflux_site_agent_pt_error' => $error_code ) : array( 'specflux_site_agent_pt_saved' => '1' )
			)
		);
		exit;
	}

	/** The one place a redirect-then-stop goes through (test override seam). */
	protected function redirect( ?string $error_code ): void {
		wp_safe_redirect(
			Shell::url(
				'settings',
				null !== $error_code ? array( 'specflux_site_agent_brief_error' => $error_code ) : array( 'specflux_site_agent_brief_saved' => '1' )
			)
		);
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

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Kit::icon() and the sprite are escaped markup; everything else is escaped where it is printed.
		Shell::open( 'settings' );
		echo '<div class="aui-layout aui-layout--single"><div class="aui-stack">';
		$this->renderFlash();
		$this->renderBriefForm();
		$this->renderStorePlatform();
		$this->renderPassthrough();
		echo '</div></div>';
		Shell::close();
		// phpcs:enable
	}

	/** One flash notice, in the kit's markup (the `data-specflux-site-agent-notice` hook is kept for tests and e2e). */
	private function notice( string $tone, string $message ): void {
		$icon = array(
			'success' => 'check',
			'error'   => 'x',
			'warning' => 'warning',
		);
		printf(
			'<div data-specflux-site-agent-notice class="aui-notice aui-notice--%1$s" role="%2$s">%3$s<div>%4$s</div></div>',
			esc_attr( $tone ),
			'error' === $tone ? 'alert' : 'status',
			Kit::icon( $icon[ $tone ] ?? 'info' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
			esc_html( $message )
		);
	}

	/** The save-result notices (brief and abilities). */
	private function renderFlash(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
		$error = isset( $_GET['specflux_site_agent_brief_error'] ) ? sanitize_text_field( wp_unslash( $_GET['specflux_site_agent_brief_error'] ) ) : '';
		if ( SiteBrief::ERROR_TOO_LONG === $error ) {
			$this->notice(
				'error',
				sprintf(
					/* translators: %d is the character cap. */
					__( 'The site brief may be at most %d characters. Nothing was saved — shorten it and try again.', 'specflux-site-agent' ),
					SiteBrief::MAX_CHARS
				)
			);
		} elseif ( '' !== $error ) {
			$this->notice( 'error', __( 'The site brief could not be saved.', 'specflux-site-agent' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
		if ( isset( $_GET['specflux_site_agent_brief_saved'] ) ) {
			$this->notice( 'success', __( 'Settings saved.', 'specflux-site-agent' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
		$error = isset( $_GET['specflux_site_agent_pt_error'] ) ? sanitize_text_field( wp_unslash( $_GET['specflux_site_agent_pt_error'] ) ) : '';
		if ( Passthrough::ERROR_TOO_MANY === $error ) {
			$this->notice(
				'error',
				sprintf(
					/* translators: %d: the cap. */
					__( 'At most %d abilities from other plugins can be enabled. Nothing was saved; untick some and try again.', 'specflux-site-agent' ),
					Passthrough::MAX_ENABLED
				)
			);
		} elseif ( '' !== $error ) {
			$this->notice( 'error', __( 'The abilities could not be saved.', 'specflux-site-agent' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
		if ( isset( $_GET['specflux_site_agent_pt_saved'] ) ) {
			$this->notice( 'success', __( 'Abilities saved.', 'specflux-site-agent' ) );
		}
	}

	/** A switch that mirrors a hidden input, so a plain form post carries its state. */
	private function syncedSwitch( string $label, bool $on, string $input_id ): string {
		return sprintf(
			'<button type="button" class="aui-switch" aria-pressed="%1$s" aria-label="%2$s" data-sync="#%3$s"><span class="aui-switch-track"></span><span class="aui-switch-state" data-on="%4$s" data-off="%5$s">%6$s</span></button>',
			$on ? 'true' : 'false',
			esc_attr( $label ),
			esc_attr( $input_id ),
			esc_attr__( 'On', 'specflux-site-agent' ),
			esc_attr__( 'Off', 'specflux-site-agent' ),
			esc_html( $on ? __( 'On', 'specflux-site-agent' ) : __( 'Off', 'specflux-site-agent' ) )
		);
	}

	/** The save bar every form on this page ends with. */
	private function saveBar( string $note ): void {
		echo '<div class="aui-savebar"><span class="aui-note">' . esc_html( $note ) . '</span>';
		echo '<button type="button" class="aui-btn aui-btn--secondary" data-aui-discard>' . esc_html__( 'Discard', 'specflux-site-agent' ) . '</button>';
		echo '<button type="submit" class="aui-btn aui-btn--primary" data-aui-save>' . esc_html__( 'Save changes', 'specflux-site-agent' ) . '</button></div>';
	}

	/** Site brief and the theme-patterns switch: one form, one handler (`save_brief`). */
	private function renderBriefForm(): void {
		$text     = $this->formText();
		$patterns = ThemePatterns::enabled();
		$length   = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="specflux-site-agent-form" data-aui-form>';
		echo '<input type="hidden" name="action" value="specflux_site_agent_save_brief">';
		wp_nonce_field( 'specflux_site_agent_save_brief' );
		echo '<input type="hidden" name="specflux_site_agent_theme_patterns_present" value="1">';

		echo '<div class="aui-grid-2">';
		echo '<section class="aui-card"><div class="aui-card-h"><div><h2 class="aui-card-t">' . esc_html__( 'Site brief', 'specflux-site-agent' ) . '</h2>';
		echo '<p class="aui-card-d">' . esc_html__( 'The site owner\'s standing notes for Specflux Site Agent: tone and content guidance every run reads. It never changes what needs approval, budgets or the plan.', 'specflux-site-agent' ) . '</p></div></div>';
		echo '<div class="aui-card-b"><div class="aui-field"><label class="aui-label" for="specflux-site-agent-site-brief">' . esc_html__( 'About your site', 'specflux-site-agent' ) . '</label>';
		printf(
			'<textarea class="aui-textarea" id="specflux-site-agent-site-brief" name="specflux_site_agent_site_brief" rows="8" maxlength="%1$d" data-count-for="#specflux-site-agent-brief-count" aria-describedby="specflux-site-agent-brief-count">%2$s</textarea>',
			(int) ( SiteBrief::MAX_CHARS * 4 ), // A generous client-side ceiling; the server refusal is authoritative (multi-byte chars).
			esc_textarea( $text )
		);
		printf(
			'<span class="aui-help" id="specflux-site-agent-brief-count" data-max="%1$d" data-template="%2$s"><span class="aui-mono">%3$s</span></span>',
			(int) SiteBrief::MAX_CHARS,
			/* translators: 1: characters used, 2: character cap. */
			esc_attr__( '%1$d / %2$d characters', 'specflux-site-agent' ),
			esc_html( sprintf( /* translators: 1: characters used, 2: character cap. */ __( '%1$d / %2$d characters', 'specflux-site-agent' ), $length, SiteBrief::MAX_CHARS ) )
		);
		echo '</div></div></section>';

		echo '<div class="aui-stack">';
		echo '<section class="aui-card"><div class="aui-card-b aui-row-flex"><div class="aui-grow"><h2 class="aui-card-t">' . esc_html__( 'Use my theme\'s patterns', 'specflux-site-agent' ) . '</h2>';
		echo '<p class="aui-card-d">' . esc_html__( 'Build pages from the active theme\'s own patterns where they fit. Turn this off to build every page from Specflux Site Agent\'s own layouts.', 'specflux-site-agent' ) . '</p></div>';
		printf( '<input type="hidden" id="specflux-site-agent-use-theme-patterns" name="%s" value="%s">', esc_attr( ThemePatterns::OPTION ), $patterns ? '1' : '0' );
		echo $this->syncedSwitch( __( 'Use my theme\'s patterns', 'specflux-site-agent' ), $patterns, 'specflux-site-agent-use-theme-patterns' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
		echo '</div></section>';
		$this->renderStorePlatformSummary();
		echo '</div></div>';

		$this->saveBar( __( 'New runs read the saved brief.', 'specflux-site-agent' ) );
		echo '</form>';
	}

	/** The store platform, read-only, while there is nothing to choose between. */
	private function renderStorePlatformSummary(): void {
		$selection = StorePlatforms::select();
		if ( count( $selection->candidates ) >= 2 ) {
			return;
		}

		echo '<section class="aui-card"><div class="aui-card-b"><h2 class="aui-card-t">' . esc_html__( 'Store platform', 'specflux-site-agent' ) . '</h2>';
		if ( null !== $selection->platform ) {
			echo '<p class="aui-card-d"><strong>' . esc_html( $selection->platform->label() ) . '</strong> ' . esc_html__( '(found on this site).', 'specflux-site-agent' ) . ' ' . esc_html__( 'Specflux Site Agent uses it to pick the right shop tools.', 'specflux-site-agent' ) . '</p>';
		} else {
			echo '<p class="aui-card-d">' . esc_html__( 'No supported store platform is active on this site.', 'specflux-site-agent' ) . '</p>';
		}
		echo '</div></section>';
	}

	/** The store-platform picker: shown only while several governed platforms are active. */
	private function renderStorePlatform(): void {
		$selection = StorePlatforms::select();
		if ( count( $selection->candidates ) < 2 ) {
			return;
		}

		echo '<section class="aui-card"><div class="aui-card-h"><h2 class="aui-card-t">' . esc_html__( 'Store platform', 'specflux-site-agent' ) . '</h2></div>';
		if ( PlatformSelection::AMBIGUOUS === $selection->status ) {
			echo '<div class="aui-card-b"><div data-specflux-site-agent-notice class="aui-notice aui-notice--warning" role="status">' . Kit::icon( 'warning' ) . '<div>' . esc_html__( 'More than one store platform is active. Commerce runs will not start until you choose one.', 'specflux-site-agent' ) . '</div></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="specflux_site_agent_save_store_platform">';
		wp_nonce_field( 'specflux_site_agent_save_store_platform' );
		echo '<div class="aui-card-b"><div class="aui-field"><label class="aui-label" for="specflux-site-agent-store-platform">' . esc_html__( 'Use this store platform for commerce runs', 'specflux-site-agent' ) . '</label>';
		echo '<select class="aui-select" id="specflux-site-agent-store-platform" name="' . esc_attr( StorePlatforms::OPTION ) . '">';
		echo '<option value="">' . esc_html__( 'Choose one...', 'specflux-site-agent' ) . '</option>';
		foreach ( $selection->candidates as $candidate ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $candidate->id() ),
				selected( StorePlatforms::chosenId(), $candidate->id(), false ),
				esc_html( $candidate->label() )
			);
		}
		echo '</select></div></div>';
		printf( '<div class="aui-card-f"><button type="submit" class="aui-btn aui-btn--primary aui-btn--sm">%s</button></div>', esc_html__( 'Save', 'specflux-site-agent' ) );
		echo '</form></section>';
	}

	/** The "Abilities from other plugins" card: one form, one handler (`save_passthrough`). */
	private function renderPassthrough(): void {
		$candidates = Passthrough::candidates();
		if ( array() === $candidates ) {
			echo '<section class="aui-card"><div class="aui-card-h"><div><h2 class="aui-card-t">' . esc_html__( 'Abilities from other plugins', 'specflux-site-agent' ) . '</h2></div></div>';
			echo '<div class="aui-card-b"><p class="aui-muted">' . esc_html__( 'No abilities from other plugins are registered on this site.', 'specflux-site-agent' ) . '</p></div></section>';
			return;
		}

		$config  = Passthrough::config();
		$review  = GateMode::AgentSafety === Plugin::currentGateMode();
		$grouped = array();
		foreach ( $candidates as $name => $ability ) {
			$grouped[ (string) strstr( $name, '/', true ) ][ $name ] = $ability;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="specflux-site-agent-form" data-aui-form>';
		echo '<input type="hidden" name="action" value="specflux_site_agent_save_passthrough">';
		wp_nonce_field( 'specflux_site_agent_save_passthrough' );

		echo '<section class="aui-card" data-aui-filterbox data-aui-counted data-aui-max="' . esc_attr( (string) Passthrough::MAX_ENABLED ) . '">';
		echo '<div class="aui-card-h"><div><h2 class="aui-card-t">' . esc_html__( 'Abilities from other plugins', 'specflux-site-agent' ) . '</h2><p class="aui-card-d">' . esc_html(
			sprintf(
				/* translators: %d: the cap. */
				__( 'Other plugins register abilities Specflux Site Agent can use. All are off until you turn them on (at most %d). Descriptions come from the plugin that registered each ability and are untrusted text. Each ability\'s own permission check still runs as the person running the task.', 'specflux-site-agent' ),
				Passthrough::MAX_ENABLED
			)
		) . '</p></div>';
		echo '<span class="aui-badge aui-end"><span class="aui-num" data-aui-counter></span>&nbsp;' . esc_html__( 'enabled', 'specflux-site-agent' ) . '</span></div>';

		echo '<div class="aui-toolbar"><div class="aui-search">' . Kit::icon( 'magnifying-glass' ) . '<input class="aui-input" type="search" placeholder="' . esc_attr__( 'Search abilities', 'specflux-site-agent' ) . '" aria-label="' . esc_attr__( 'Search abilities', 'specflux-site-agent' ) . '" data-aui-search></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
		echo '<div class="aui-seg" role="group" aria-label="' . esc_attr__( 'Filter by type', 'specflux-site-agent' ) . '" data-aui-filter><button type="button" aria-pressed="true" data-value="all">' . esc_html__( 'All', 'specflux-site-agent' ) . '</button><button type="button" aria-pressed="false" data-value="read">' . esc_html__( 'Read-only', 'specflux-site-agent' ) . '</button><button type="button" aria-pressed="false" data-value="write">' . esc_html__( 'Write', 'specflux-site-agent' ) . '</button></div>';
		echo '<button type="button" class="aui-switch aui-end" aria-pressed="false" data-aui-ui data-aui-target="#specflux-site-agent-pt-list" data-aui-class="aui-show-desc"><span class="aui-switch-track"></span>' . esc_html__( 'Descriptions', 'specflux-site-agent' ) . '<span class="aui-switch-state" data-on="' . esc_attr__( 'On', 'specflux-site-agent' ) . '" data-off="' . esc_attr__( 'Off', 'specflux-site-agent' ) . '">' . esc_html__( 'Off', 'specflux-site-agent' ) . '</span></button></div>';

		printf(
			'<div class="aui-groups" id="specflux-site-agent-pt-list" data-meta-template="%s">',
			/* translators: 1: enabled, 2: total, 3: read abilities, 4: abilities that change things. */
			esc_attr__( '%1$d / %2$d enabled · %3$d read, %4$d write', 'specflux-site-agent' )
		);
		$index = 0;
		foreach ( $grouped as $plugin_ns => $abilities ) {
			$this->renderPassthroughGroup( $index++, $plugin_ns, $abilities, $config, $review );
		}
		echo '</div>';

		echo '<div class="aui-card-f"><p class="aui-help">' . esc_html__( '"Read" only applies when Specflux Site Agent\'s own approvals are in use; with Belayline Agent Governance, its review decides.', 'specflux-site-agent' ) . '</p></div>';
		echo '</section>';

		$this->saveBar( __( 'Changes apply to every AI client on the next call.', 'specflux-site-agent' ) );
		echo '</form>';
	}

	/**
	 * One plugin's group in the abilities list.
	 *
	 * @param int                                              $index     Group number (ids).
	 * @param string                                           $plugin_ns The ability namespace.
	 * @param array<string,object>                             $abilities Abilities in it, by name.
	 * @param array<string,array{enabled:bool,read:bool,hash:string}> $config    Saved choices.
	 * @param bool                                             $review    Whether Belayline's review hides unreviewed abilities.
	 */
	private function renderPassthroughGroup( int $index, string $plugin_ns, array $abilities, array $config, bool $review ): void {
		$rows    = '';
		$enabled = 0;
		$reads   = 0;
		foreach ( $abilities as $name => $ability ) {
			$entry   = $config[ $name ] ?? array(
				'enabled' => false,
				'read'    => false,
				'hash'    => '',
			);
			$problem = Passthrough::problem( $entry, $ability );
			$on      = null === $problem;
			$label   = method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '';
			$desc    = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
			$meta    = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();
			$flags   = array();
			foreach ( is_array( $meta ) && is_array( $meta['annotations'] ?? null ) ? $meta['annotations'] : array() as $key => $value ) {
				if ( is_string( $key ) && is_bool( $value ) ) {
					$flags[] = $key . ': ' . ( $value ? 'yes' : 'no' );
				}
			}
			$enabled += $on ? 1 : 0;
			$reads   += $entry['read'] ? 1 : 0;
			$id       = 'specflux-site-agent-pt-' . md5( $name );

			$notes = '';
			if ( 'changed' === $problem ) {
				$notes .= '<div class="specflux-site-agent-item-note specflux-site-agent-pt-drift">' . esc_html__( 'Off: this ability\'s inputs changed since you enabled it. Tick it again to re-enable.', 'specflux-site-agent' ) . '</div>';
			}
			if ( $on && Passthrough::isDestructive( $ability ) ) {
				$notes .= '<div class="specflux-site-agent-item-note">' . esc_html__( 'Marked destructive by its plugin: always asks for approval, whatever you choose.', 'specflux-site-agent' ) . '</div>';
			}
			if ( $on && $review && ! AbilityReviewProbe::isReviewed( $name ) ) {
				$notes .= '<div class="specflux-site-agent-item-note">' . esc_html__( 'Hidden from runs until reviewed in Belayline Agent Governance.', 'specflux-site-agent' ) . '</div>';
			}

			$details = '';
			if ( '' !== $desc ) {
				$details .= esc_html( $desc );
			}
			if ( array() !== $flags ) {
				$details .= ( '' !== $details ? '<br>' : '' ) . esc_html__( 'Annotations:', 'specflux-site-agent' ) . ' ' . esc_html( implode( ', ', $flags ) );
			}

			$rows .= sprintf(
				'<div class="aui-item" data-kind="%1$s"><input type="checkbox" name="specflux_site_agent_pt_enabled[]" value="%2$s" id="%3$s" data-ability%4$s><div class="aui-grow"><div class="aui-item-name"><label for="%3$s">%5$s</label> <code>%6$s</code></div>%7$s%8$s</div>'
				. '<select class="aui-select specflux-site-agent-mode" name="specflux_site_agent_pt_mode[%2$s]" aria-label="%9$s" data-kind-select><option value="changes"%10$s>%11$s</option><option value="read"%12$s>%13$s</option></select></div>',
				$entry['read'] ? 'read' : 'write',
				esc_attr( $name ),
				esc_attr( $id ),
				checked( $on, true, false ),
				esc_html( '' !== $label ? $label : $name ),
				esc_html( $name ),
				'' !== $details ? '<div class="aui-item-d">' . $details . '</div>' : '',
				$notes,
				/* translators: %s: ability label. */
				esc_attr( sprintf( __( 'Treat %s as', 'specflux-site-agent' ), '' !== $label ? $label : $name ) ),
				selected( $entry['read'], false, false ),
				esc_html__( 'Changes things', 'specflux-site-agent' ),
				selected( $entry['read'], true, false ),
				esc_html__( 'Read', 'specflux-site-agent' )
			);
		}

		$total = count( $abilities );
		$gid   = 'specflux-site-agent-pt-g' . $index;
		printf(
			'<div class="aui-group"><div class="aui-group-h"><input type="checkbox" aria-label="%1$s" data-group="%2$s"%3$s><button type="button" class="aui-group-toggle" aria-expanded="%4$s" aria-controls="%2$s"><span class="aui-group-name">%5$s</span><span class="aui-leader"></span><span class="aui-group-meta" data-meta>%6$s</span>%7$s</button></div><div class="aui-group-b" id="%2$s"%8$s>%9$s</div></div>',
			/* translators: %s: plugin namespace. */
			esc_attr( sprintf( __( 'Turn on all %s abilities', 'specflux-site-agent' ), $plugin_ns ) ),
			esc_attr( $gid ),
			checked( $enabled === $total, true, false ),
			$enabled > 0 || $total <= 4 ? 'true' : 'false',
			esc_html( $plugin_ns . '/' ),
			esc_html(
				sprintf(
					/* translators: 1: enabled, 2: total, 3: read abilities, 4: abilities that change things. */
					__( '%1$d / %2$d enabled · %3$d read, %4$d write', 'specflux-site-agent' ),
					$enabled,
					$total,
					$reads,
					$total - $reads
				)
			),
			Kit::icon( 'caret-down' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
			$enabled > 0 || $total <= 4 ? '' : ' hidden',
			$rows // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every fragment above is escaped where built.
		);
	}
}
