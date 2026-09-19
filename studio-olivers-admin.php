<?php
/**
 * Plugin Name:       Studio Olivers Admin
 * Description:       Studio Olivers admin tweaks for client sites. Each feature lives in its own file in /modules.
 * Version:           1.1.0
 * Author:            Studio Olivers
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       studio-olivers-admin
 * Update URI:        https://github.com/Studio-Oliver/studio-olivers-admin
 */

defined( 'ABSPATH' ) || exit;

define( 'SOA_VERSION', '1.1.0' );
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
 * Updates from GitHub. Publishing a GitHub release with a higher version
 * number makes "Update available" appear on every site.
 *
 * Sites on the same server share GitHub's limit of 60 anonymous API requests
 * per hour per IP. If a server hits that limit, add a read-only token to that
 * site's wp-config.php:
 *     define( 'SOA_GITHUB_TOKEN', 'github_pat_...' );
 */
require_once SOA_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

$soa_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/Studio-Oliver/studio-olivers-admin/',
	__FILE__,
	'studio-olivers-admin'
);
$soa_update_checker->setBranch( 'main' );

if ( defined( 'SOA_GITHUB_TOKEN' ) && SOA_GITHUB_TOKEN ) {
	$soa_update_checker->setAuthentication( SOA_GITHUB_TOKEN );
}
