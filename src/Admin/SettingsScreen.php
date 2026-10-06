<?php
/**
 * SenroFlux → Settings: the site brief (0.3 S20).
 *
 * TARGET REPO PATH: src/Admin/SettingsScreen.php
 *
 * A submenu page under the existing top-level "SenroFlux" menu
 * ({@see RunsScreen}), capability `manage_options`. `[assumed]` (S20): a
 * plain admin-post form with a nonce, matching every other mutation on the
 * Runs screen, rather than the full `register_setting()`/`options.php`
 * machinery — the load-bearing behaviour S20 asks for (submenu placement,
 * the capability, a nonce, the length cap refusing rather than truncating,
 * and clearing on empty) is identical either way, and this keeps one human
 * mutation pattern across the plugin. _Overturn: move to `register_setting()`
 * if a stranger walkthrough expects the native Settings UI chrome._
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Admin;

use Specflux\SenroFlux\Packs\Commerce\Platforms\PlatformSelection;
use Specflux\SenroFlux\Packs\Commerce\Platforms\StorePlatforms;
use Specflux\SenroFlux\Packs\Pages\ThemePatterns;
use Specflux\SenroFlux\Passthrough\AbilityReviewProbe;
use Specflux\SenroFlux\Passthrough\ExternalExposure;
use Specflux\SenroFlux\Passthrough\Passthrough;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Run\SiteBrief;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Renders + saves the site brief and the theme-patterns switch.
 */
class SettingsScreen {

	private const SLUG = 'senroflux-settings';

	/** Transient holding a REFUSED submission's text, keyed by user id (never truncated, never lost). */
	private const PENDING_TRANSIENT_PREFIX = 'senroflux_brief_pending_';

	/** Register on admin_menu + admin_post. */
	public function register(): void {
		add_action( 'init', array( $this, 'registerSettings' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_senroflux_save_brief', array( $this, 'handleSave' ) );
		add_action( 'admin_post_senroflux_save_passthrough', array( $this, 'handleSavePassthrough' ) );
		add_action( 'admin_post_senroflux_save_external', array( $this, 'handleSaveExternal' ) );
		add_action( 'admin_post_senroflux_save_store_platform', array( $this, 'handleSaveStorePlatform' ) );
	}

	/**
	 * D2: the theme-patterns switch as a registered boolean setting (default
	 * true, sanitised to a bool). It is saved by {@see handleSave()} with the
	 * brief, never through `options.php`.
	 */
	public function registerSettings(): void {
		register_setting(
			'senroflux',
			ThemePatterns::OPTION,
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => array( ThemePatterns::class, 'sanitizeOption' ),
				'show_in_rest'      => false,
			)
		);
		register_setting(
			'senroflux',
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
		check_admin_referer( 'senroflux_save_store_platform' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'senroflux' ) );
		}

		$posted = isset( $_POST[ StorePlatforms::OPTION ] ) && is_string( $_POST[ StorePlatforms::OPTION ] )
			? sanitize_key( wp_unslash( $_POST[ StorePlatforms::OPTION ] ) )
			: '';
		$saved  = StorePlatforms::save( $posted );

		$this->redirectPassthrough( is_wp_error( $saved ) ? (string) $saved->get_error_code() : null );
	}

	/** The store-platform picker: shown only while several governed platforms are active. */
	private function renderStorePlatform(): void {
		$selection = StorePlatforms::select();
		if ( count( $selection->candidates ) < 2 ) {
			return;
		}

		echo '<hr><h2>' . esc_html__( 'Store platform', 'senroflux' ) . '</h2>';
		if ( PlatformSelection::AMBIGUOUS === $selection->status ) {
			echo '<div data-senroflux-notice class="notice notice-warning"><p>' . esc_html__( 'More than one store platform is active. Commerce runs will not start until you choose one.', 'senroflux' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="senroflux_save_store_platform">';
		wp_nonce_field( 'senroflux_save_store_platform' );
		echo '<p><label for="senroflux-store-platform">' . esc_html__( 'Use this store platform for commerce runs', 'senroflux' ) . '</label> ';
		echo '<select id="senroflux-store-platform" name="' . esc_attr( StorePlatforms::OPTION ) . '">';
		echo '<option value="">' . esc_html__( 'Choose one...', 'senroflux' ) . '</option>';
		foreach ( $selection->candidates as $candidate ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $candidate->id() ),
				selected( StorePlatforms::chosenId(), $candidate->id(), false ),
				esc_html( $candidate->label() )
			);
		}
		echo '</select></p>';
		printf( '<p><button type="submit" class="button button-primary">%s</button></p>', esc_html__( 'Save', 'senroflux' ) );
		echo '</form>';
	}

	/** The capability required for this screen (S20: fixed at manage_options). */
	public function capability(): string {
		return 'manage_options';
	}

	/** The "Settings" submenu under the existing top-level "SenroFlux" menu. */
	public function menu(): void {
		add_submenu_page(
			'senroflux-runs',
			__( 'SenroFlux Settings', 'senroflux' ),
			__( 'Settings', 'senroflux' ),
			$this->capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** admin-post handler: save (or clear) the site brief. */
	public function handleSave(): void {
		check_admin_referer( 'senroflux_save_brief' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'senroflux' ) );
		}

		// Plain text (S20): stripped of markup, but newlines kept — a
		// textarea field, not a single-line input.
		$text = sanitize_textarea_field( wp_unslash( $_POST['senroflux_site_brief'] ?? '' ) );

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
		if ( isset( $_POST['senroflux_theme_patterns_present'] ) ) {
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
		check_admin_referer( 'senroflux_save_passthrough' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'senroflux' ) );
		}

		$enabled = array();
		if ( isset( $_POST['senroflux_pt_enabled'] ) && is_array( $_POST['senroflux_pt_enabled'] ) ) {
			foreach ( wp_unslash( $_POST['senroflux_pt_enabled'] ) as $name ) {
				if ( is_string( $name ) ) {
					$enabled[] = sanitize_text_field( $name );
				}
			}
		}

		$read = array();
		if ( isset( $_POST['senroflux_pt_mode'] ) && is_array( $_POST['senroflux_pt_mode'] ) ) {
			foreach ( wp_unslash( $_POST['senroflux_pt_mode'] ) as $name => $mode ) {
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

	/** admin-post handler: save the "external AI apps" switch (0.4 S8). */
	public function handleSaveExternal(): void {
		check_admin_referer( 'senroflux_save_external' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'senroflux' ) );
		}

		$posted = isset( $_POST[ ExternalExposure::OPTION ] ) && is_string( $_POST[ ExternalExposure::OPTION ] )
			? sanitize_text_field( wp_unslash( $_POST[ ExternalExposure::OPTION ] ) )
			: '0';
		update_option( ExternalExposure::OPTION, ExternalExposure::sanitize( $posted ) ? '1' : '0' );

		$this->redirectPassthrough( null );
	}

	/** Redirect back to the passthrough section (test override seam). */
	protected function redirectPassthrough( ?string $error_code ): void {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		$url = null !== $error_code
			? add_query_arg( array( 'senroflux_pt_error' => $error_code ), $url )
			: add_query_arg( array( 'senroflux_pt_saved' => '1' ), $url );
		wp_safe_redirect( $url );
		exit;
	}

	/** The one place a redirect-then-stop goes through (test override seam). */
	protected function redirect( ?string $error_code ): void {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		if ( null !== $error_code ) {
			$url = add_query_arg( array( 'senroflux_brief_error' => $error_code ), $url );
		} else {
			$url = add_query_arg( array( 'senroflux_brief_saved' => '1' ), $url );
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
			wp_die( esc_html__( 'Insufficient permissions.', 'senroflux' ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'SenroFlux Settings', 'senroflux' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
		$error = isset( $_GET['senroflux_brief_error'] ) ? sanitize_text_field( wp_unslash( $_GET['senroflux_brief_error'] ) ) : '';
		if ( SiteBrief::ERROR_TOO_LONG === $error ) {
			printf(
				'<div data-senroflux-notice class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d is the character cap. */
						__( 'The site brief may be at most %d characters. Nothing was saved — shorten it and try again.', 'senroflux' ),
						SiteBrief::MAX_CHARS
					)
				)
			);
		} elseif ( '' !== $error ) {
			echo '<div data-senroflux-notice class="notice notice-error"><p>' . esc_html__( 'The site brief could not be saved.', 'senroflux' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
		if ( isset( $_GET['senroflux_brief_saved'] ) ) {
			echo '<div data-senroflux-notice class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'senroflux' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="senroflux_save_brief">';
		wp_nonce_field( 'senroflux_save_brief' );

		echo '<p>' . esc_html__( 'The site owner\'s standing notes for SenroFlux: tone and content guidance every run reads. It never changes what needs approval, budgets or the plan.', 'senroflux' ) . '</p>';
		echo '<p><label for="senroflux-site-brief" class="screen-reader-text">' . esc_html__( 'Site brief', 'senroflux' ) . '</label>';
		printf(
			'<textarea id="senroflux-site-brief" name="senroflux_site_brief" rows="10" class="large-text code" maxlength="%d">%s</textarea></p>',
			esc_attr( (string) ( SiteBrief::MAX_CHARS * 4 ) ), // A generous client-side ceiling; the server refusal is authoritative (multi-byte chars).
			esc_textarea( $this->formText() )
		);

		echo '<input type="hidden" name="senroflux_theme_patterns_present" value="1">';
		printf(
			'<p><label for="senroflux-use-theme-patterns"><input type="checkbox" id="senroflux-use-theme-patterns" name="%s" value="1"%s> %s</label><br><span class="description">%s</span></p>',
			esc_attr( ThemePatterns::OPTION ),
			checked( ThemePatterns::enabled(), true, false ),
			esc_html__( 'Use my theme\'s block patterns', 'senroflux' ),
			esc_html__( 'Build pages from the active theme\'s own patterns where they fit. Untick to build every page from SenroFlux\'s own layouts.', 'senroflux' )
		);

		printf(
			'<p><button type="submit" class="button button-primary">%s</button></p>',
			esc_html__( 'Save settings', 'senroflux' )
		);

		echo '</form>';

		$this->renderStorePlatform();
		$this->renderExternal();
		$this->renderPassthrough();

		echo '</div>';
	}

	/** The "external AI apps" switch (0.4 S8). */
	private function renderExternal(): void {
		$present = ExternalExposure::senrogatePresent();

		echo '<hr><h2>' . esc_html__( 'External AI apps', 'senroflux' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="senroflux_save_external">';
		wp_nonce_field( 'senroflux_save_external' );
		printf(
			'<p><label><input type="checkbox" name="%1$s" value="1"%2$s%3$s> %4$s</label></p>',
			esc_attr( ExternalExposure::OPTION ),
			checked( ExternalExposure::effective(), true, false ),
			disabled( $present, false, false ),
			esc_html__( 'Let external AI apps use SenroFlux\'s abilities', 'senroflux' )
		);
		echo '<p class="description">' . esc_html__( 'Calls from external apps get SenroGate\'s tiers and approvals, not SenroFlux\'s plan, budgets or report.', 'senroflux' ) . '</p>';
		if ( ! $present ) {
			echo '<p class="description">' . esc_html__( 'This needs SenroGate. Install and activate it to turn this on; until then SenroFlux\'s abilities stay unavailable to external apps.', 'senroflux' ) . '</p>';
		}
		printf(
			'<p><button type="submit" class="button button-primary"%2$s>%1$s</button></p>',
			esc_html__( 'Save', 'senroflux' ),
			disabled( $present, false, false )
		);
		echo '</form>';
	}

	/** The "Abilities from other plugins" section (0.4 S6). */
	private function renderPassthrough(): void {
		echo '<hr><h2>' . esc_html__( 'Abilities from other plugins', 'senroflux' ) . '</h2>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
		$error = isset( $_GET['senroflux_pt_error'] ) ? sanitize_text_field( wp_unslash( $_GET['senroflux_pt_error'] ) ) : '';
		if ( Passthrough::ERROR_TOO_MANY === $error ) {
			printf(
				'<div data-senroflux-notice class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d is the cap. */
						__( 'At most %d abilities from other plugins can be enabled. Nothing was saved; untick some and try again.', 'senroflux' ),
						Passthrough::MAX_ENABLED
					)
				)
			);
		} elseif ( '' !== $error ) {
			echo '<div data-senroflux-notice class="notice notice-error"><p>' . esc_html__( 'The abilities could not be saved.', 'senroflux' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
		if ( isset( $_GET['senroflux_pt_saved'] ) ) {
			echo '<div data-senroflux-notice class="notice notice-success"><p>' . esc_html__( 'Abilities saved.', 'senroflux' ) . '</p></div>';
		}

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d is the cap. */
				__( 'Other plugins register abilities SenroFlux can use. All are off until you turn them on (at most %d). Descriptions come from the plugin that registered each ability and are untrusted text. Each ability\'s own permission check still runs as the person running the task.', 'senroflux' ),
				Passthrough::MAX_ENABLED
			)
		) . '</p>';

		$candidates = Passthrough::candidates();
		if ( array() === $candidates ) {
			echo '<p>' . esc_html__( 'No abilities from other plugins are registered on this site.', 'senroflux' ) . '</p>';
			return;
		}

		$config = Passthrough::config();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="senroflux_save_passthrough">';
		wp_nonce_field( 'senroflux_save_passthrough' );

		$current = '';
		foreach ( $candidates as $name => $ability ) {
			$namespace = (string) strstr( $name, '/', true );
			if ( $namespace !== $current ) {
				if ( '' !== $current ) {
					echo '</tbody></table>';
				}
				$current = $namespace;
				printf( '<h3>%s</h3>', esc_html( $namespace . '/' ) );
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'On', 'senroflux' ) . '</th><th>' . esc_html__( 'Ability', 'senroflux' ) . '</th><th>' . esc_html__( 'Treat as', 'senroflux' ) . '</th></tr></thead><tbody>';
			}

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

			echo '<tr><td>';
			printf(
				'<input type="checkbox" name="senroflux_pt_enabled[]" value="%1$s" id="senroflux-pt-%2$s"%3$s>',
				esc_attr( $name ),
				esc_attr( md5( $name ) ),
				checked( $on, true, false )
			);
			echo '</td><td>';
			printf(
				'<label for="senroflux-pt-%s"><strong>%s</strong></label> <code>%s</code>',
				esc_attr( md5( $name ) ),
				esc_html( '' !== $label ? $label : $name ),
				esc_html( $name )
			);
			if ( '' !== $desc ) {
				echo '<br><span class="description">' . esc_html( $desc ) . '</span>';
			}
			if ( array() !== $flags ) {
				echo '<br><span class="description">' . esc_html__( 'Annotations:', 'senroflux' ) . ' ' . esc_html( implode( ', ', $flags ) ) . '</span>';
			}
			if ( 'changed' === $problem ) {
				echo '<br><strong class="senroflux-pt-drift">' . esc_html__( 'Off: this ability\'s inputs changed since you enabled it. Tick it again to re-enable.', 'senroflux' ) . '</strong>';
			}
			if ( $on && Passthrough::isDestructive( $ability ) ) {
				echo '<br><span class="description">' . esc_html__( 'Marked destructive by its plugin: always asks for approval, whatever you choose.', 'senroflux' ) . '</span>';
			}
			if ( $on && GateMode::AgentSafety === Plugin::currentGateMode() && ! AbilityReviewProbe::isReviewed( $name ) ) {
				echo '<br><span class="description">' . esc_html__( 'Hidden from runs until reviewed in SenroGate.', 'senroflux' ) . '</span>';
			}
			echo '</td><td>';
			printf(
				'<select name="senroflux_pt_mode[%1$s]"><option value="changes"%2$s>%3$s</option><option value="read"%4$s>%5$s</option></select>',
				esc_attr( $name ),
				selected( $entry['read'], false, false ),
				esc_html__( 'Changes things', 'senroflux' ),
				selected( $entry['read'], true, false ),
				esc_html__( 'Read', 'senroflux' )
			);
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<p class="description">' . esc_html__( '"Read" only applies when SenroFlux\'s own approvals are in use; with SenroGate, its review decides.', 'senroflux' ) . '</p>';
		printf(
			'<p><button type="submit" class="button button-primary">%s</button></p>',
			esc_html__( 'Save abilities', 'senroflux' )
		);
		echo '</form>';
	}
}
