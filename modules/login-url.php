<?php
/**
 * Module: Login URL
 *
 * Moves the login page to a custom address and hides wp-login.php and wp-admin
 * from logged-out visitors (they get the site's 404 page).
 *
 * Does nothing until a site sets its slug in wp-config.php:
 *     define( 'SOA_LOGIN_SLUG', 'team-login' );
 *
 * Every link WordPress and plugins build to wp-login.php (logout, lost
 * password, reset emails, Two Factor's code step) is rewritten to the slug.
 * admin-ajax.php and admin-post.php stay open, because front-end forms use them.
 *
 * Locked out? Remove the SOA_LOGIN_SLUG line from wp-config.php, or add
 * 'login-url' to SOA_DISABLED_MODULES. wp-login.php works again at once.
 */

defined( 'ABSPATH' ) || exit;

/** The configured slug, or '' when the module is off. */
function soa_login_slug() {
	$slug = defined( 'SOA_LOGIN_SLUG' ) ? (string) SOA_LOGIN_SLUG : '';
	$slug = trim( (string) apply_filters( 'soa_login_slug', $slug ), '/' );

	// Never let a slug shadow WordPress itself.
	if ( '' === $slug || in_array( strtolower( $slug ), array( 'wp-admin', 'wp-login', 'wp-login.php', 'admin', 'login', 'dashboard' ), true ) ) {
		return '';
	}
	return $slug;
}

if ( '' === soa_login_slug() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

/** The request path relative to the site's home path, without query or trailing slash. */
function soa_login_request_path() {
	$path      = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
	$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	if ( '' !== $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
		$path = '/' . substr( $path, strlen( $home_path ) );
	}
	return untrailingslashit( rawurldecode( $path ) );
}

/** The login page URL, keeping any query string from the original link. */
function soa_login_page_url( $query = '', $scheme = null ) {
	$url = trailingslashit( home_url( soa_login_slug(), $scheme ) );
	return '' !== $query ? $url . '?' . $query : $url;
}

/** Rewrite any URL pointing at wp-login.php to the slug. */
function soa_login_filter_url( $url, $scheme = null ) {
	if ( ! is_string( $url ) || false === strpos( $url, 'wp-login.php' ) ) {
		return $url;
	}
	// Leave other sites' URLs alone (e.g. a link to a different WordPress install).
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( $host && wp_parse_url( home_url(), PHP_URL_HOST ) !== $host ) {
		return $url;
	}
	$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
	return soa_login_page_url( $query, $scheme );
}

add_filter( 'site_url', function ( $url, $path, $scheme ) {
	return soa_login_filter_url( $url, $scheme );
}, 100, 3 );

add_filter( 'network_site_url', function ( $url, $path, $scheme ) {
	return soa_login_filter_url( $url, $scheme );
}, 100, 3 );

add_filter( 'wp_redirect', function ( $location ) {
	return soa_login_filter_url( $location );
}, 100 );

/** Show the site's normal 404 page and stop. */
function soa_login_not_found() {
	global $wp_query;

	status_header( 404 );
	nocache_headers();

	if ( $wp_query instanceof WP_Query ) {
		$wp_query->set_404();
	}

	$template = get_404_template();
	if ( ! $template ) {
		$template = get_index_template();
	}
	$template = apply_filters( 'template_include', $template );

	if ( $template && is_readable( $template ) ) {
		include $template;
	} else {
		wp_die( esc_html__( 'Page not found.', 'studio-olivers-admin' ), '', array( 'response' => 404 ) );
	}
	exit;
}

$soa_login_path = soa_login_request_path();
$soa_login_slug = '/' . soa_login_slug();

if ( strtolower( $soa_login_path ) === strtolower( $soa_login_slug ) ) {
	// 1. The custom address serves the real login page.
	add_action( 'wp_loaded', function () {
		// wp-login.php reads these as globals; declaring them here keeps them global.
		global $pagenow, $error, $interim_login, $action, $user_login, $user, $redirect_to;

		$pagenow = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		require_once ABSPATH . 'wp-login.php';
		exit;
	}, 1 );
} elseif ( preg_match( '#/wp-(login|register|signup|activate)\.php$#i', $soa_login_path ) ) {
	// 2. wp-login.php is never reached directly: every link to it now points at the slug.
	add_action( 'wp_loaded', 'soa_login_not_found', 1 );
} elseif ( preg_match( '#/wp-admin(/|$)#i', $soa_login_path . '/' ) ) {
	// 3. wp-admin for logged-out visitors. Runs before WordPress's own
	//    redirect to the login page, which would reveal the slug.
	add_action( 'wp_loaded', function () {
		$open = array( 'admin-ajax.php', 'admin-post.php' );
		if ( is_user_logged_in() || wp_doing_ajax() || in_array( basename( (string) $GLOBALS['pagenow'] ), $open, true ) ) {
			return;
		}
		soa_login_not_found();
	}, 1 );
}

unset( $soa_login_path, $soa_login_slug );
