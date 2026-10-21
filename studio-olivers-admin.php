<?php
/**
 * Plugin Name:       Studio Olivers Admin
 * Description:       Studio Olivers admin tweaks for client sites. Each feature lives in its own file in /modules.
 * Version:           1.2.0
 * Author:            Studio Olivers
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       studio-olivers-admin
 * Update URI:        https://github.com/Studio-Oliver/studio-olivers-admin
 */

defined( 'ABSPATH' ) || exit;

define( 'SOA_VERSION', '1.2.0' );
define( 'SOA_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOA_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load every module in /modules. Each module is a single PHP file, and its
 * filename (without .php) is its ID.
 *
 * Turn a module off on one site by adding this to its wp-config.php:
 *     define( 'SOA_DISABLED_MODULES', array( 'quiet-admin' ) );
 * or from code:
 *     add_filter( 'soa_disabled_modules', fn( $m ) => array_merge( $m, array( 'quiet-admin' ) ) );
 */
function soa_load_modules() {
	$disabled = defined( 'SOA_DISABLED_MODULES' ) ? (array) SOA_DISABLED_MODULES : array();
	$disabled = (array) apply_filters( 'soa_disabled_modules', $disabled );

	foreach ( glob( SOA_DIR . 'modules/*.php' ) ?: array() as $file ) {
		if ( ! in_array( basename( $file, '.php' ), $disabled, true ) ) {
			require_once $file;
		}
	}
}
add_action( 'plugins_loaded', 'soa_load_modules' );

/**
 * Is this a Studio Olivers staff account? Staff are known by email domain,
 * not role, because some clients are given full administrator access.
 *
 * Add a domain on one site in its wp-config.php:
 *     define( 'SOA_STUDIO_EMAIL_DOMAINS', array( 'example.co.uk' ) );
 * or from code with the 'soa_studio_email_domains' filter.
 *
 * @param WP_User|int|null $user Defaults to the current user.
 */
function soa_is_studio_user( $user = null ) {
	if ( null === $user ) {
		$user = wp_get_current_user();
	} elseif ( is_numeric( $user ) ) {
		$user = get_userdata( (int) $user );
	}
	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return false;
	}

	$domains = array( 'studio-olivers.co.uk', 'mrolivers.co.uk' );
	if ( defined( 'SOA_STUDIO_EMAIL_DOMAINS' ) ) {
		$domains = array_merge( $domains, (array) SOA_STUDIO_EMAIL_DOMAINS );
	}
	$domains = array_map( 'strtolower', (array) apply_filters( 'soa_studio_email_domains', $domains ) );

	// Match the whole domain after the last "@", so "evilmrolivers.co.uk" doesn't count.
	$at = strrpos( (string) $user->user_email, '@' );
	if ( false === $at ) {
		return false;
	}
	$domain = strtolower( substr( $user->user_email, $at + 1 ) );

	return in_array( $domain, $domains, true );
}

/**
 * Updates from GitHub. Publishing a GitHub release with a higher version
 * number makes "Update available" appear on every site.
 *
 * Sites on the same server share GitHub's limit of 60 anonymous API requests
 * per hour per IP. If a server hits that limit, add a read-only token to that
 * site's wp-config.php:
 *     define( 'SOA_GITHUB_TOKEN', 'github_pat_...' );
 */
function soa_update_checker() {
	static $checker = null;

	if ( null === $checker ) {
		require_once SOA_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

		$checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/Studio-Oliver/studio-olivers-admin/',
			__FILE__,
			'studio-olivers-admin'
		);
		$checker->setBranch( 'main' );

		if ( defined( 'SOA_GITHUB_TOKEN' ) && SOA_GITHUB_TOKEN ) {
			$checker->setAuthentication( SOA_GITHUB_TOKEN );
		}
	}

	return $checker;
}
soa_update_checker();
