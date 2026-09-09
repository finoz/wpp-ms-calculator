<?php
/**
 * Plugin Name:  MS Calculator
 * Description:  Configurable scoring calculator (parameters in, points out) driven by a JSON config.
 * Version:      0.1.0
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Author:       Finoz
 * License:      MIT
 * Text Domain:  ms-calculator
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ─────────────────────────────────────────────────────────────────

define( 'MSC_VERSION', '0.1.0' );
define( 'MSC_DIR',     plugin_dir_path( __FILE__ ) );
define( 'MSC_URL',     plugin_dir_url( __FILE__ ) );

/**
 * GitHub repo for auto-updates (owner/repo).
 * Set this once before publishing. Can be overridden per-site via
 * the 'msc_github_repo' filter.
 */
define( 'MSC_GITHUB_REPO', 'finoz/wpp-ms-calculator' );

/**
 * Default config path: wp-content/ms-calculator-config.json
 * Stored OUTSIDE the plugin folder so it survives updates.
 * Override with the filter 'msc_config_path'.
 */
define( 'MSC_CONFIG', WP_CONTENT_DIR . '/ms-calculator-config.json' );

// ── Frontend assets ───────────────────────────────────────────────────────────
//
// TODO: assets/ms-calculator.css is a hand-written placeholder for now.
// It will be replaced by a Vite + SCSS build (assets/scss → assets/dist),
// same pipeline as wpt-lomais, once the visual design is defined.

add_action( 'wp_enqueue_scripts', static function (): void {
	wp_enqueue_style(
		'ms-calculator',
		MSC_URL . 'assets/ms-calculator.css',
		[],
		MSC_VERSION
	);
} );

// ── Includes ──────────────────────────────────────────────────────────────────

require_once MSC_DIR . 'includes/renderer.php';
require_once MSC_DIR . 'includes/updater.php';

if ( is_admin() ) {
	require_once MSC_DIR . 'includes/admin.php';
}

// ── Config loader ─────────────────────────────────────────────────────────────

/**
 * Load and return the full config array (static-cached).
 *
 * Priority: 1) wp_options (edited via admin UI)
 *           2) file at MSC_CONFIG (or filter override)
 *           3) bundled config-default.json
 */
function msc_config(): array {
	static $cfg = null;

	if ( null !== $cfg ) {
		return $cfg;
	}

	// 1. Admin UI / wp_options takes priority.
	$from_db = get_option( 'msc_config' );
	if ( $from_db ) {
		$decoded = json_decode( $from_db, true );
		if ( is_array( $decoded ) ) {
			$cfg = $decoded;
			return $cfg;
		}
	}

	// 2. File on disk (default or filter override).
	$path = apply_filters( 'msc_config_path', MSC_CONFIG );
	if ( ! is_readable( $path ) ) {
		// 3. Bundled example as last resort.
		$path = MSC_DIR . 'config-default.json';
	}

	$decoded = json_decode( file_get_contents( $path ), true ); // phpcs:ignore
	$cfg     = is_array( $decoded ) ? $decoded : [];

	return $cfg;
}

/**
 * Return a single calculator config by ID, or null if not found.
 */
function msc_get_calculator( string $id ): ?array {
	return msc_config()['calculators'][ $id ] ?? null;
}

// ── Shortcode: [ms_calculator id="milan_score"] ──────────────────────────────

add_shortcode( 'ms_calculator', static function ( array $atts ): string {

	$atts = shortcode_atts( [ 'id' => '' ], $atts, 'ms_calculator' );

	if ( '' === $atts['id'] ) {
		return '<!-- [ms_calculator] missing id attribute -->';
	}

	$calculator = msc_get_calculator( $atts['id'] );

	if ( ! $calculator ) {
		return sprintf(
			'<!-- [ms_calculator] calculator "%s" not found in config -->',
			esc_html( $atts['id'] )
		);
	}

	wp_enqueue_script(
		'ms-calculator',
		MSC_URL . 'assets/ms-calculator.js',
		[],
		MSC_VERSION,
		true
	);

	return msc_render_calculator( $atts['id'], $calculator );
} );
