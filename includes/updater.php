<?php
/**
 * MS Calculator – GitHub auto-updater
 *
 * Hooks into WordPress's native update mechanism so the plugin shows up in
 * Dashboard → Updates and can be updated with one click — just like plugins
 * from wordpress.org.
 *
 * ── Setup ─────────────────────────────────────────────────────────────────────
 * The repo slug is set once in ms-calculator.php as MSC_GITHUB_REPO.
 * No server configuration needed — just set it before publishing the plugin.
 *
 * To point a fork to a different repo, override via filter in functions.php:
 *
 *   add_filter( 'msc_github_repo', fn() => 'other-user/my-fork' );
 *
 * For private repos, add a personal access token (contents:read scope) in
 * wp-config.php:
 *
 *   define( 'MSC_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx' );
 *
 * ── How it works ──────────────────────────────────────────────────────────────
 * 1. WP checks for plugin updates periodically (every 12 h) via a transient.
 * 2. Our filter intercepts that check, calls the GitHub Releases API, and
 *    injects an update entry if the latest release tag is newer than the
 *    installed version.
 * 3. WP downloads the zip from GitHub and extracts it.
 * 4. A post-install hook renames the extracted folder to 'ms-calculator/' because
 *    GitHub auto-generates zips with the folder named 'repo-tagname/'.
 * ─────────────────────────────────────────────────────────────────────────────
 */

defined( 'ABSPATH' ) || exit;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Return the GitHub repo slug to use for update checks.
 *
 * Priority:
 *   1. 'msc_github_repo' filter  (runtime override, e.g. for forks)
 *   2. MSC_GITHUB_REPO constant  (set in ms-calculator.php — the normal case)
 */
function msc_updater_repo(): string {
	$default = defined( 'MSC_GITHUB_REPO' ) ? MSC_GITHUB_REPO : '';
	return (string) apply_filters( 'msc_github_repo', $default );
}

/**
 * Fetch the latest release from the GitHub API.
 * Result is cached for 6 hours via a WP transient.
 *
 * @return array|null  Decoded release object, or null on failure.
 */
function msc_updater_get_release(): ?array {

	$repo = msc_updater_repo();
	if ( ! $repo ) return null;

	$cache_key = 'msc_gh_release_' . md5( $repo );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) return $cached ?: null;

	$args = [
		'timeout' => 10,
		'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) ],
	];

	if ( defined( 'MSC_GITHUB_TOKEN' ) ) {
		$args['headers']['Authorization'] = 'Bearer ' . MSC_GITHUB_TOKEN;
	}

	$response = wp_remote_get(
		"https://api.github.com/repos/{$repo}/releases/latest",
		$args
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		set_transient( $cache_key, [], HOUR_IN_SECONDS ); // negative cache to avoid hammering the API
		return null;
	}

	$release = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $release['tag_name'] ) ) return null;

	set_transient( $cache_key, $release, 6 * HOUR_IN_SECONDS );
	return $release;
}

/**
 * Return the download URL for the release zip.
 * Prefers an attached .zip asset; falls back to GitHub's auto-generated source zip.
 */
function msc_updater_zip_url( array $release ): string {

	foreach ( $release['assets'] ?? [] as $asset ) {
		if ( str_ends_with( $asset['name'], '.zip' ) ) {
			return $asset['browser_download_url'];
		}
	}

	// GitHub auto-generates a source archive for every tag.
	$repo = msc_updater_repo();
	return "https://github.com/{$repo}/archive/refs/tags/{$release['tag_name']}.zip";
}

// ── Hook 1: inject update into WP's update transient ─────────────────────────

add_filter( 'pre_set_site_transient_update_plugins', static function ( $transient ) {

	if ( empty( $transient->checked ) ) return $transient;

	$release = msc_updater_get_release();
	if ( ! $release ) return $transient;

	$latest  = ltrim( $release['tag_name'], 'v' );
	$file    = plugin_basename( MSC_DIR . 'ms-calculator.php' ); // e.g. ms-calculator/ms-calculator.php
	$current = $transient->checked[ $file ] ?? MSC_VERSION;

	if ( version_compare( $latest, $current, '>' ) ) {
		$transient->response[ $file ] = (object) [
			'slug'        => 'ms-calculator',
			'plugin'      => $file,
			'new_version' => $latest,
			'url'         => 'https://github.com/' . msc_updater_repo(),
			'package'     => msc_updater_zip_url( $release ),
		];
	}

	return $transient;
} );

// ── Hook 2: populate the plugin info popup (View version X.X details) ─────────

add_filter( 'plugins_api', static function ( $result, string $action, object $args ) {

	if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== 'ms-calculator' ) {
		return $result;
	}

	$release = msc_updater_get_release();
	if ( ! $release ) return $result;

	$repo = msc_updater_repo();
	return (object) [
		'name'          => 'MS Calculator',
		'slug'          => 'ms-calculator',
		'version'       => ltrim( $release['tag_name'], 'v' ),
		'author'        => 'Finoz',
		'homepage'      => "https://github.com/{$repo}",
		'download_link' => msc_updater_zip_url( $release ),
		'last_updated'  => $release['published_at'] ?? '',
		'sections'      => [
			'description' => 'Configurable scoring calculator (parameters in, points out).',
			'changelog'   => nl2br( esc_html( $release['body'] ?? '' ) ),
		],
	];

}, 10, 3 );

// ── Hook 3: rename extracted folder after install ─────────────────────────────
//
// GitHub's auto-generated zip contains a folder named "ms-calculator-v1.2.3/"
// (or "ms-calculator-1.2.3/"). WP would install it under that name, breaking the
// plugin. We rename it to "ms-calculator/" right after extraction.

add_filter( 'upgrader_post_install', static function ( $response, array $hook_extra, array $result ) {

	if ( ( $hook_extra['plugin'] ?? '' ) !== plugin_basename( MSC_DIR . 'ms-calculator.php' ) ) {
		return $response;
	}

	global $wp_filesystem;

	$dest = WP_PLUGIN_DIR . '/ms-calculator';

	// Only rename if the destination isn't already correct.
	if ( trailingslashit( $result['destination'] ) !== trailingslashit( $dest ) ) {
		$wp_filesystem->move( $result['destination'], $dest, true );
		$result['destination'] = $dest;
	}

	// Re-activate the plugin (WP deactivates it during updates).
	activate_plugin( plugin_basename( $dest . '/ms-calculator.php' ) );

	return $result;

}, 10, 3 );
