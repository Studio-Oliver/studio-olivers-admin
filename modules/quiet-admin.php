<?php
/**
 * Module: Quiet Admin
 *
 * Hides plugin and theme ads, upsells and nag notices in wp-admin.
 * WordPress's own notices and allowlisted plugins still show.
 * Add ?show_notices=1 to any admin URL to see everything (admins only).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin/theme folder names whose notices always show.
 * Extend this per site with the 'soa_notice_allowlist' filter.
 */
function soa_notice_allowlist() {
	return (array) apply_filters( 'soa_notice_allowlist', array(
		// Security and backup plugins: never hide a warning from these.
		'wordfence',
		'updraftplus',
		'security-malware-firewall', // CleanTalk Security & Malware Scan
		'cleantalk-spam-protect',    // CleanTalk Anti-Spam
		'sucuri-scanner',
		'better-wp-security',        // Solid Security (formerly iThemes)
	) );
}

/** Find the PHP file where a hooked callback is defined. */
function soa_callback_file( $callback ) {
	try {
		if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
			$callback = explode( '::', $callback, 2 );
		}
		if ( is_array( $callback ) ) {
			$ref = new ReflectionMethod( $callback[0], $callback[1] );
		} elseif ( is_object( $callback ) && ! $callback instanceof Closure ) {
			$ref = new ReflectionMethod( $callback, '__invoke' );
		} else {
			$ref = new ReflectionFunction( $callback );
		}
		return wp_normalize_path( (string) $ref->getFileName() );
	} catch ( ReflectionException $e ) {
		return '';
	}
}

/** Keep WordPress's own notices and allowlisted plugins. Drop the rest. */
function soa_keep_notice( $callback ) {
	$file = soa_callback_file( $callback );
	if ( '' === $file ) {
		return true; // Unknown source: keep it to be safe.
	}

	$always_keep = array(
		wp_normalize_path( ABSPATH . 'wp-admin/' ),
		wp_normalize_path( ABSPATH . WPINC . '/' ),
		wp_normalize_path( SOA_DIR ), // Our own notices.
	);
	foreach ( $always_keep as $dir ) {
		if ( 0 === strpos( $file, $dir ) ) {
			return true;
		}
	}

	foreach ( soa_notice_allowlist() as $slug ) {
		if ( false !== strpos( $file, "/plugins/{$slug}/" ) || false !== strpos( $file, "/themes/{$slug}/" ) ) {
			return true;
		}
	}

	return false;
}

/* 1. Remove notices from other plugins and themes, just before WordPress shows notices. */
add_action( 'in_admin_header', function () {
	if ( ! empty( $_GET['show_notices'] ) && current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wp_filter;
	$hooks = array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' );

	foreach ( $hooks as $hook ) {
		if ( empty( $wp_filter[ $hook ] ) ) {
			continue;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				if ( ! soa_keep_notice( $cb['function'] ) ) {
					remove_action( $hook, $cb['function'], $priority );
				}
			}
		}
	}
}, PHP_INT_MAX );

/* 2. Dashboard: keep only these core widgets and remove the welcome panel. */
add_action( 'wp_dashboard_setup', function () {
	global $wp_meta_boxes;

	remove_action( 'welcome_panel', 'wp_welcome_panel' );

	$keep = (array) apply_filters( 'soa_dashboard_widgets', array(
		'dashboard_site_health',
		'dashboard_right_now',
		'dashboard_activity',
		'dashboard_quick_press',
	) );

	foreach ( (array) ( $wp_meta_boxes['dashboard'] ?? array() ) as $context => $priorities ) {
		foreach ( (array) $priorities as $boxes ) {
			foreach ( array_keys( (array) $boxes ) as $id ) {
				if ( ! in_array( $id, $keep, true ) ) {
					remove_meta_box( $id, 'dashboard', $context );
				}
			}
		}
	}
}, PHP_INT_MAX );

/* 3. Switches built into specific plugins. They do nothing if the plugin isn't installed. */
add_filter( 'woocommerce_allow_marketplace_suggestions', '__return_false' ); // "Recommended extensions" boxes
add_filter( 'woocommerce_helper_suppress_admin_notices', '__return_true' );  // "Connect to WooCommerce.com" nag
add_filter( 'jetpack_just_in_time_msgs', '__return_false' );                 // Jetpack pop-up promos
add_filter( 'jetpack_show_promotions', '__return_false' );
