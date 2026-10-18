<?php
/**
 * The Site Agent app shell: one top-level menu, three tabs, one header.
 *
 * Runs · AI apps · Settings. The submenus match the tabs, every screen prints
 * the same header (mark, name, tabs, status pill, version chip) and the kit
 * is enqueued only on these three screens. Old admin URLs redirect here with
 * every query argument kept.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Admin\Ui;

use Specflux\SiteAgent\Admin\RunsScreen;
use Specflux\SiteAgent\Admin\ScreenCapability;
use Specflux\SiteAgent\Background\Availability;
use Specflux\SiteAgent\Connector\Approvals;
use Specflux\SiteAgent\Connector\Connections;
use Specflux\SiteAgent\Connector\Connector;
use Specflux\SiteAgent\Connector\OAuth\Store;
use Specflux\SiteAgent\MultisiteGuard;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/** Menu, tabs, header, kit enqueue and legacy-URL redirects. */
class Shell {

	public const RUNS     = 'specflux-site-agent-runs';
	public const APPS     = 'specflux-site-agent-apps';
	public const SETTINGS = 'specflux-site-agent-settings';

	/** The AI apps tab's old homes. */
	public const LEGACY_CONNECT   = 'specflux-site-agent-connect';
	public const LEGACY_SIGNED_IN = 'specflux-site-agent-signed-in-apps';

	/** The Runs screen's old "From AI apps" tab: `?page=specflux-site-agent-runs&tab=ai-apps`. */
	public const LEGACY_APPROVALS_TAB = 'ai-apps';

	/** Section anchors on the AI apps tab. */
	public const SECTION_WAITING   = 'specflux-site-agent-waiting';
	public const SECTION_SIGNED_IN = 'specflux-site-agent-signed-in';
	public const SECTION_ACTIVITY  = 'specflux-site-agent-activity';

	/** Hook the menu, the kit enqueue and the legacy redirects. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'redirectLegacy' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/** Page slug of a tab key (`runs`, `apps`, `settings`). */
	public static function slug( string $tab ): string {
		return match ( $tab ) {
			'apps'     => self::APPS,
			'settings' => self::SETTINGS,
			default    => self::RUNS,
		};
	}

	/**
	 * A tab's URL.
	 *
	 * @param string                       $tab      `runs`, `apps` or `settings`.
	 * @param array<string,string|int|bool> $args     Extra query arguments.
	 * @param string                       $fragment Section anchor without the `#`.
	 */
	public static function url( string $tab, array $args = array(), string $fragment = '' ): string {
		$url = add_query_arg( array( 'page' => self::slug( $tab ) ) + $args, admin_url( 'admin.php' ) );

		return '' === $fragment ? $url : $url . '#' . $fragment;
	}

	/**
	 * The menu: a top-level "Site Agent" entry and one submenu per tab.
	 *
	 * Each capability is computed fresh: a Subscriber sees no menu at all, an
	 * editor who can run one pack sees Runs, only an administrator sees Settings.
	 */
	public function menu(): void {
		$title = __( 'Site Agent', 'specflux-site-agent' );

		add_menu_page( $title, $title, ScreenCapability::current(), self::RUNS, array( new RunsScreen(), 'render' ), self::menuIcon() );
		// The first submenu shares the parent's slug: it renames the auto-added entry.
		add_submenu_page( self::RUNS, __( 'Site Agent: Runs', 'specflux-site-agent' ), __( 'Runs', 'specflux-site-agent' ), ScreenCapability::current(), self::RUNS, array( new RunsScreen(), 'render' ) );
		add_submenu_page( self::RUNS, __( 'Site Agent: AI apps', 'specflux-site-agent' ), __( 'AI apps', 'specflux-site-agent' ), Connector::capability(), self::APPS, array( \Specflux\SiteAgent\Connector\AppsScreen::class, 'render' ) );
		add_submenu_page( self::RUNS, __( 'Site Agent: Settings', 'specflux-site-agent' ), __( 'Settings', 'specflux-site-agent' ), 'manage_options', self::SETTINGS, array( new \Specflux\SiteAgent\Admin\SettingsScreen(), 'render' ) );
	}

	/** The bundled gear icon as a data URI (WordPress repaints a `fill` on the root element to match the admin colour scheme). */
	public static function menuIcon(): string {
		$path = SPECFLUX_SITE_AGENT_PATH . 'assets/admin/menu-icon.svg';
		$svg  = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local plugin file.

		return is_string( $svg ) && '' !== $svg
			? 'data:image/svg+xml;base64,' . base64_encode( $svg ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the documented way to pass an inline SVG as a menu icon.
			: 'dashicons-admin-generic';
	}

	/** Is this `admin_enqueue_scripts` hook one of the three Site Agent screens? */
	public static function isOwnScreen( string $hook ): bool {
		foreach ( array( self::RUNS, self::APPS, self::SETTINGS ) as $slug ) {
			if ( str_ends_with( $hook, '_page_' . $slug ) ) {
				return true;
			}
		}

		return false;
	}

	/** Enqueue the kit on this plugin's own screens only. */
	public function enqueue( string $hook ): void {
		if ( self::isOwnScreen( $hook ) ) {
			Kit::enqueue();
		}
	}

	/**
	 * Old URLs, redirected with every scalar query argument kept.
	 *
	 * - `tools.php?page=specflux-site-agent-runs` (before 0.3) -> Runs;
	 * - `admin.php?page=specflux-site-agent-connect` -> AI apps;
	 * - `admin.php?page=specflux-site-agent-signed-in-apps` -> AI apps, signed-in section;
	 * - `admin.php?page=specflux-site-agent-runs&tab=ai-apps` -> AI apps, waiting-calls section.
	 */
	public function redirectLegacy(): void {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect, no state change.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $page ) {
			return;
		}

		$target = null;
		if ( 'tools.php' === $pagenow && self::RUNS === $page ) {
			$target = array( 'runs', '' );
		} elseif ( self::LEGACY_CONNECT === $page ) {
			$target = array( 'apps', '' );
		} elseif ( self::LEGACY_SIGNED_IN === $page ) {
			$target = array( 'apps', self::SECTION_SIGNED_IN );
		} elseif ( self::RUNS === $page && self::LEGACY_APPROVALS_TAB === self::param( 'tab' ) ) {
			$target = array( 'apps', self::SECTION_WAITING );
		}

		if ( null === $target ) {
			return;
		}

		$args = array();
		foreach ( array_keys( $_GET ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect.
			if ( ! is_string( $key ) || 'page' === $key || ( 'tab' === $key && 'apps' === $target[0] && self::RUNS === $page ) ) {
				continue;
			}
			$value = self::param( $key );
			if ( null !== $value ) {
				$args[ $key ] = $value;
			}
		}

		$this->redirectAndExit( self::url( $target[0], $args, $target[1] ) );
	}

	/** A scalar GET value, unslashed, or null for a missing or non-scalar one. */
	private static function param( string $key ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect.
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : null;
	}

	/** The one place a redirect-then-stop goes through (test override seam). */
	protected function redirectAndExit( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * The tabs the current user may open, in order.
	 *
	 * @return array<string,array{label:string,cap:string}>
	 */
	public static function tabs(): array {
		return array(
			'runs'     => array(
				'label' => __( 'Runs', 'specflux-site-agent' ),
				'cap'   => ScreenCapability::current(),
			),
			'apps'     => array(
				'label' => __( 'AI apps', 'specflux-site-agent' ),
				'cap'   => Connector::capability(),
			),
			'settings' => array(
				'label' => __( 'Settings', 'specflux-site-agent' ),
				'cap'   => 'manage_options',
			),
		);
	}

	/** Connections an AI app can use right now: signed-in apps plus the viewer's application-password connections. */
	public static function connectedApps(): int {
		return count( Store::grantIndex() ) + count( Connections::forUser( get_current_user_id() ) );
	}

	/**
	 * The status pill: tone and words.
	 *
	 * @return array{tone:string,text:string}
	 */
	public static function status(): array {
		if ( MultisiteGuard::refused() || ! Connector::stored() ) {
			return array(
				'tone' => '',
				'text' => __( 'AI apps off', 'specflux-site-agent' ),
			);
		}
		if ( ! Connector::adapterReady() ) {
			return array(
				'tone' => 'warn',
				'text' => __( 'MCP Adapter needed', 'specflux-site-agent' ),
			);
		}
		$count = self::connectedApps();
		if ( 0 === $count ) {
			return array(
				'tone' => '',
				'text' => __( 'No apps connected', 'specflux-site-agent' ),
			);
		}

		return array(
			'tone' => 'ok',
			/* translators: %d: number of connected AI apps. */
			'text' => sprintf( _n( '%d app connected', '%d apps connected', $count, 'specflux-site-agent' ), $count ),
		);
	}

	/**
	 * Print the opening of a screen: the sprite, the `.aui` root, the header and the body wrapper.
	 * Pair with {@see self::close()}.
	 *
	 * @param string $current `runs`, `apps` or `settings`.
	 */
	public static function open( string $current ): void {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- the sprite is a vendored file; every other fragment is escaped where built.
		echo Kit::sprite();
		echo '<div class="aui" data-brand="specflux">';
		echo self::header( $current );
		echo '<hr class="wp-header-end">';
		// phpcs:enable
		echo '<div class="aui-body">';
	}

	/** Close what {@see self::open()} opened. */
	public static function close(): void {
		echo '</div></div>';
	}

	/** The app header for a screen. */
	public static function header( string $current ): string {
		$html  = '<header class="aui-header">';
		$html .= '<div class="aui-brand"><span class="aui-mark">' . Kit::icon( 'gear', 'aui-i--lg' ) . '</span><h1>' . esc_html__( 'Site Agent', 'specflux-site-agent' ) . '</h1></div>';
		$html .= '<nav class="aui-nav" aria-label="' . esc_attr__( 'Site Agent', 'specflux-site-agent' ) . '">';
		foreach ( self::tabs() as $key => $tab ) {
			if ( ! current_user_can( $tab['cap'] ) ) {
				continue;
			}
			$html .= '<a href="' . esc_url( self::url( $key ) ) . '"' . ( $key === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $tab['label'] );
			if ( 'apps' === $key ) {
				$waiting = count( Approvals::pending() );
				if ( $waiting > 0 ) {
					$label = sprintf(
						/* translators: %d: number of AI app calls waiting for approval. */
						_n( '%d call waiting for approval', '%d calls waiting for approval', $waiting, 'specflux-site-agent' ),
						$waiting
					);
					$html .= ' <span class="aui-count aui-count--warn">' . esc_html( (string) $waiting ) . '<span class="aui-sr"> ' . esc_html( $label ) . '</span></span>';
				}
			}
			$html .= '</a>';
		}
		$html .= '</nav><div class="aui-header-end">';

		if ( 'runs' === $current ) {
			$background = Availability::status();
			$html      .= '<span class="aui-pill' . ( $background['available'] ? ' aui-pill--ok' : '' ) . '" data-header-background="' . esc_attr( $background['available'] ? 'available' : ( '' !== $background['reason'] ? $background['reason'] : 'unavailable' ) ) . '">'
				. esc_html( $background['available'] ? __( 'Runs continue in the background', 'specflux-site-agent' ) : __( 'Runs need this page open', 'specflux-site-agent' ) ) . '</span>';
		}

		$status = self::status();
		$html  .= '<span class="aui-pill' . ( '' === $status['tone'] ? '' : ' aui-pill--' . $status['tone'] ) . '">' . esc_html( $status['text'] ) . '</span>';
		$html  .= '<span class="aui-chip">v' . esc_html( Kit::pluginVersion() ) . '</span>';
		$html  .= '</div></header>';

		return $html;
	}
}
