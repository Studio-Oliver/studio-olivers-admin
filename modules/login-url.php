<?php
/**
 * Module: Login URL
 *
 * Moves the login page to a custom address and hides wp-login.php and wp-admin
 * from logged-out visitors (they get the site's own 404 page).
 *
 * Set it at Settings → Studio Olivers. It is off until switched on there.
 * On the first admin page load it imports CleanTalk's renamed login slug
 * (spbc_settings → login_page_rename__name) so existing bookmarks survive
 * CleanTalk being removed, but it never switches itself on.
 *
 * LOCKED OUT? Over SSH:
 *     wp option delete soa_login_url
 * wp-login.php works again immediately. Or in wp-config.php:
 *     define( 'SOA_LOGIN_DISABLE', true );
 *
 * Fail-safe by design: anything unexpected (no slug, a reserved slug, a slug
 * that collides with a real page) leaves the feature inert and wp-login.php
 * working, rather than hiding the login with nothing serving it.
 *
 * This file is also loaded by a small mu-plugin (see soa_login_mu_path), so
 * deactivating the main plugin cannot leave the login unreachable. It must
 * therefore not depend on anything the main plugin file defines.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SOA_LOGIN_URL_VERSION' ) ) {
	define( 'SOA_LOGIN_URL_VERSION', '2' );
}

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

/** This module's stored settings. */
function soa_login_option() {
	$option = get_option( 'soa_login_url', array() );
	return is_array( $option ) ? $option : array();
}

/** Addresses that must never become the login slug. */
function soa_login_reserved_slugs() {
	return array(
		'wp-admin', 'wp-login', 'wp-login.php', 'wp-content', 'wp-includes',
		'wp-json', 'wp-cron.php', 'xmlrpc.php', 'admin', 'login', 'dashboard',
		'feed', 'robots.txt', 'sitemap.xml',
	);
}

/** Normalise a slug: one path segment, lowercase, no slashes. */
function soa_login_clean_slug( $slug ) {
	$slug = strtolower( trim( (string) $slug ) );
	$slug = trim( $slug, "/ \t\n\r\0\x0B" );
	$slug = preg_replace( '#[^a-z0-9/_-]#', '', $slug );
	$slug = str_replace( '/', '', (string) $slug );
	return substr( (string) $slug, 0, 60 );
}

/**
 * Why a slug can't be used, or '' when it's fine.
 * Used by the settings screen to explain itself instead of failing silently.
 */
function soa_login_slug_problem( $slug ) {
	if ( '' === $slug ) {
		return __( 'Enter a login address.', 'studio-olivers-admin' );
	}
	if ( in_array( $slug, soa_login_reserved_slugs(), true ) ) {
		return __( 'That address is reserved by WordPress. Pick another.', 'studio-olivers-admin' );
	}
	if ( get_page_by_path( $slug ) ) {
		return __( 'A page or post already uses that address. Pick another.', 'studio-olivers-admin' );
	}
	return '';
}

/** The login slug, or '' when the module should stay inert. */
function soa_login_slug() {
	$option = soa_login_option();
	$slug   = defined( 'SOA_LOGIN_SLUG' ) ? (string) SOA_LOGIN_SLUG : (string) ( $option['slug'] ?? '' );
	$slug   = soa_login_clean_slug( apply_filters( 'soa_login_slug', $slug ) );

	// Reserved slugs are refused here too, not just on save: a bad value that
	// reached the database some other way must not hide the login.
	if ( in_array( $slug, soa_login_reserved_slugs(), true ) ) {
		return '';
	}
	return $slug;
}

/** Is the custom login address live on this request? */
function soa_login_enabled() {
	if ( defined( 'SOA_LOGIN_DISABLE' ) && SOA_LOGIN_DISABLE ) {
		return false;
	}

	// Sites set up before there was a settings screen define the slug in
	// wp-config.php. That still means "on", so an update doesn't quietly put
	// their login back at wp-login.php.
	if ( defined( 'SOA_LOGIN_SLUG' ) && '' !== soa_login_slug() ) {
		return true;
	}

	$option = soa_login_option();
	return ! empty( $option['enabled'] ) && '' !== soa_login_slug();
}

/** The full login URL to show people. */
function soa_login_full_url() {
	return '' === soa_login_slug() ? '' : trailingslashit( home_url( soa_login_slug() ) );
}

/* -------------------------------------------------------------------------
 * One-off import from CleanTalk
 * ---------------------------------------------------------------------- */

/**
 * Copy CleanTalk's renamed login slug across, once, so the address people
 * already bookmarked keeps working after CleanTalk is removed.
 *
 * Only its stored option is read, never its code. The feature is left switched
 * off: while CleanTalk is still active it is already serving that address, and
 * two things answering for one URL is how lockouts happen.
 */
function soa_login_import_from_cleantalk() {
	if ( get_option( 'soa_login_url_imported' ) ) {
		return;
	}
	update_option( 'soa_login_url_imported', time(), false );

	$option = soa_login_option();
	if ( ! empty( $option['slug'] ) ) {
		return; // Already configured here; leave it alone.
	}

	$cleantalk = get_option( 'spbc_settings' );
	if ( ! is_array( $cleantalk ) || empty( $cleantalk['login_page_rename__enabled'] ) ) {
		return;
	}

	$slug = soa_login_clean_slug( $cleantalk['login_page_rename__name'] ?? '' );
	if ( '' === $slug || '' !== soa_login_slug_problem( $slug ) ) {
		return;
	}

	update_option( 'soa_login_url', array(
		'enabled'  => false,
		'slug'     => $slug,
		'imported' => 'cleantalk',
	) );
}

/* -------------------------------------------------------------------------
 * The mu-plugin loader
 *
 * mu-plugins load before regular plugins and cannot be deactivated from the
 * dashboard, so the login address survives the main plugin being switched off.
 * If the plugin is deleted the loader finds no file and does nothing, which
 * puts wp-login.php back rather than breaking the site.
 * ---------------------------------------------------------------------- */

function soa_login_mu_path() {
	return WPMU_PLUGIN_DIR . '/0-soa-login-url.php';
}

/** Marker used to spot a loader written by an older version. */
function soa_login_mu_marker() {
	return 'SOA_LOGIN_LOADER v' . SOA_LOGIN_URL_VERSION;
}

function soa_login_mu_contents() {
	return "<?php\n"
		. "/**\n"
		. " * Plugin Name: Studio Olivers Login URL loader\n"
		. " * Description: Keeps the custom login address working even if Studio Olivers Admin is deactivated. Written automatically; do not edit.\n"
		. " */\n"
		. "\n"
		. "// " . soa_login_mu_marker() . "\n"
		. "\$soa_login_module = WP_PLUGIN_DIR . '/studio-olivers-admin/modules/login-url.php';\n"
		. "\n"
		. "if ( is_readable( \$soa_login_module ) ) {\n"
		. "\trequire_once \$soa_login_module;\n"
		. "}\n"
		. "\n"
		. "unset( \$soa_login_module );\n";
}

/** Write the loader. Returns true, or a WP_Error explaining why not. */
function soa_login_write_mu_loader() {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	global $wp_filesystem;
	if ( ! WP_Filesystem() || ! $wp_filesystem ) {
		return new WP_Error( 'soa_login_fs', __( 'WordPress could not get write access to wp-content.', 'studio-olivers-admin' ) );
	}

	if ( ! $wp_filesystem->is_dir( WPMU_PLUGIN_DIR ) && ! $wp_filesystem->mkdir( WPMU_PLUGIN_DIR, FS_CHMOD_DIR ) ) {
		return new WP_Error( 'soa_login_mkdir', __( 'The mu-plugins folder could not be created.', 'studio-olivers-admin' ) );
	}

	if ( ! $wp_filesystem->put_contents( soa_login_mu_path(), soa_login_mu_contents(), FS_CHMOD_FILE ) ) {
		return new WP_Error( 'soa_login_write', __( 'The loader file could not be written to mu-plugins.', 'studio-olivers-admin' ) );
	}
	return true;
}

function soa_login_remove_mu_loader() {
	if ( ! file_exists( soa_login_mu_path() ) ) {
		return true;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';

	global $wp_filesystem;
	if ( ! WP_Filesystem() || ! $wp_filesystem ) {
		return false;
	}
	return (bool) $wp_filesystem->delete( soa_login_mu_path() );
}

function soa_login_mu_loader_installed() {
	return file_exists( soa_login_mu_path() )
		&& false !== strpos( (string) file_get_contents( soa_login_mu_path() ), 'SOA_LOGIN_LOADER' );
}

/** Keep the loader in step with the setting. */
function soa_login_sync_mu_loader() {
	if ( ! soa_login_enabled() ) {
		soa_login_remove_mu_loader();
		delete_transient( 'soa_login_mu_error' );
		return;
	}

	$written = soa_login_write_mu_loader();
	if ( is_wp_error( $written ) ) {
		set_transient( 'soa_login_mu_error', $written->get_error_message(), DAY_IN_SECONDS );
	} else {
		delete_transient( 'soa_login_mu_error' );
	}
}
add_action( 'add_option_soa_login_url', 'soa_login_sync_mu_loader', 10, 0 );
add_action( 'update_option_soa_login_url', 'soa_login_sync_mu_loader', 10, 0 );

/* -------------------------------------------------------------------------
 * Settings screen
 * ---------------------------------------------------------------------- */

add_action( 'admin_init', function () {
	soa_login_import_from_cleantalk();

	// After a plugin update, refresh a loader written by an older version.
	if ( soa_login_enabled() && ! soa_login_loader_current() ) {
		soa_login_sync_mu_loader();
	}

	if ( ! function_exists( 'soa_settings_page' ) ) {
		return; // Settings module disabled on this site.
	}

	register_setting( soa_settings_page(), 'soa_login_url', array(
		'type'              => 'array',
		'sanitize_callback' => 'soa_login_sanitize_settings',
		'default'           => array( 'enabled' => false, 'slug' => 'access' ),
	) );

	add_settings_section(
		'soa_login_url',
		__( 'Login address', 'studio-olivers-admin' ),
		'soa_login_settings_intro',
		soa_settings_page()
	);

	add_settings_field(
		'soa_login_url_slug',
		__( 'Login address', 'studio-olivers-admin' ),
		'soa_login_field_slug',
		soa_settings_page(),
		'soa_login_url'
	);

	add_settings_field(
		'soa_login_url_enabled',
		__( 'Hide wp-login.php', 'studio-olivers-admin' ),
		'soa_login_field_enabled',
		soa_settings_page(),
		'soa_login_url'
	);
} );

function soa_login_loader_current() {
	return file_exists( soa_login_mu_path() )
		&& false !== strpos( (string) file_get_contents( soa_login_mu_path() ), soa_login_mu_marker() );
}

function soa_login_settings_intro() {
	$option = soa_login_option();
	$url    = soa_login_full_url();
	$error  = get_transient( 'soa_login_mu_error' );

	if ( soa_login_enabled() ) {
		printf(
			'<p><strong>%s</strong><br><code style="font-size:14px;padding:4px 8px;display:inline-block;margin-top:4px;">%s</code></p>',
			esc_html__( 'Log in to this site at:', 'studio-olivers-admin' ),
			esc_html( $url )
		);
		printf(
			'<p>%s</p>',
			esc_html__( 'wp-login.php and wp-admin show the site\'s 404 page to anyone not logged in.', 'studio-olivers-admin' )
		);
	} else {
		printf( '<p>%s</p>', esc_html__( 'The login page is at the normal wp-login.php. Switch the setting below on to move it.', 'studio-olivers-admin' ) );
	}

	if ( ! empty( $option['imported'] ) && 'cleantalk' === $option['imported'] && ! soa_login_enabled() ) {
		printf(
			'<div class="notice notice-info inline"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Imported from CleanTalk.', 'studio-olivers-admin' ),
			sprintf(
				/* translators: %s: the login slug, e.g. access. */
				esc_html__( 'This site\'s CleanTalk login address (%s) has been copied across but not switched on, because CleanTalk is probably still serving it. Switch this on, check the address works, then turn CleanTalk\'s login rename off.', 'studio-olivers-admin' ),
				'<code>' . esc_html( soa_login_slug() ) . '</code>'
			)
		);
	}

	if ( soa_login_enabled() && ! soa_login_mu_loader_installed() ) {
		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html__( 'The protective loader in mu-plugins is missing, so deactivating this plugin would put the login back at wp-login.php. Saving this page again will try to write it.', 'studio-olivers-admin' )
		);
	}

	if ( $error ) {
		printf(
			'<div class="notice notice-warning inline"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Could not write the mu-plugins loader:', 'studio-olivers-admin' ),
			esc_html( $error )
		);
	}

	printf(
		'<p class="description">%s<br><code>wp option delete soa_login_url</code></p>',
		esc_html__( 'Locked out? Run this over SSH and wp-login.php works again straight away:', 'studio-olivers-admin' )
	);
}

function soa_login_field_slug() {
	$option = soa_login_option();
	printf(
		'<code>%s</code> <input type="text" name="soa_login_url[slug]" value="%s" class="regular-text" style="width:14em" autocomplete="off"> ',
		esc_html( trailingslashit( home_url() ) ),
		esc_attr( (string) ( $option['slug'] ?? '' ) )
	);
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Letters, numbers and hyphens. Avoid anything guessable like "login".', 'studio-olivers-admin' )
	);
}

function soa_login_field_enabled() {
	$option = soa_login_option();
	printf(
		'<label><input type="checkbox" name="soa_login_url[enabled]" value="1" %s> %s</label>',
		checked( ! empty( $option['enabled'] ), true, false ),
		esc_html__( 'Serve the login page at the address above and show a 404 at wp-login.php', 'studio-olivers-admin' )
	);
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Password-protected pages, logging out, password-reset links, admin-ajax and WP-CLI keep working.', 'studio-olivers-admin' )
	);
}

/**
 * Validate before saving. A bad slug never switches the feature on: it is
 * refused with an explanation and the login stays where it is.
 */
function soa_login_sanitize_settings( $input ) {
	$option = soa_login_option();
	$input  = is_array( $input ) ? $input : array();

	$slug    = soa_login_clean_slug( $input['slug'] ?? '' );
	$wanted  = ! empty( $input['enabled'] );
	$problem = soa_login_slug_problem( $slug );

	if ( $wanted && '' !== $problem ) {
		add_settings_error( 'soa_login_url', 'soa_login_slug', $problem );
		$wanted = false;
	}

	$option['slug']    = $slug;
	$option['enabled'] = $wanted;

	if ( $wanted ) {
		add_settings_error(
			'soa_login_url',
			'soa_login_live',
			sprintf(
				/* translators: %s: the full login URL. */
				__( 'The login page is now at %s. Bookmark it before logging out.', 'studio-olivers-admin' ),
				trailingslashit( home_url( $slug ) )
			),
			'success'
		);
	}

	return $option;
}

/* -------------------------------------------------------------------------
 * Serving the login page
 * ---------------------------------------------------------------------- */

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

/**
 * Requests that must keep working at wp-login.php even while it is hidden.
 *
 * postpass is how WordPress accepts the password for a protected page, logout
 * must never be blocked, and rp/resetpass with a key covers reset links from
 * emails sent before this feature was switched on.
 */
function soa_login_action_must_work() {
	$action = isset( $_REQUEST['action'] ) ? strtolower( sanitize_key( $_REQUEST['action'] ) ) : '';

	if ( in_array( $action, array( 'postpass', 'logout' ), true ) ) {
		return true;
	}
	if ( in_array( $action, array( 'rp', 'resetpass' ), true ) && ! empty( $_REQUEST['key'] ) && ! empty( $_REQUEST['login'] ) ) {
		return true;
	}
	return false;
}

/**
 * Show the site's normal 404 page and stop.
 *
 * Page builders (Bricks etc.) only apply their templates on a normal front-end
 * request, and wp-admin isn't one. So the real 404 page is fetched once, kept
 * for 6 hours, and served from that copy. The fetch identifies itself as a bot
 * so SEO plugins don't log it as a 404.
 */
function soa_login_not_found() {
	$html = get_transient( 'soa_login_404_html' );

	if ( false === $html ) {
		$html     = '';
		$response = wp_remote_get( home_url( '/soa-page-not-found/' ), array(
			'timeout'     => 5,
			'redirection' => 0,
			'user-agent'  => 'StudioOliversAdmin 404 bot',
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
		) );
		if ( 404 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$html = (string) wp_remote_retrieve_body( $response );
		}
		set_transient( 'soa_login_404_html', $html, $html ? 6 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	status_header( 404 );
	nocache_headers();

	if ( '' !== $html ) {
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the site's own 404 page.
		exit;
	}

	wp_die( esc_html__( 'Page not found.', 'studio-olivers-admin' ), '', array( 'response' => 404 ) );
}

/** Serve wp-login.php at the custom address. */
function soa_login_serve() {
	// wp-login.php reads these as globals; declaring them here keeps them global.
	global $pagenow, $error, $interim_login, $action, $user_login, $user, $redirect_to;

	// Page caches (Breeze, WP Rocket, W3TC…) skip wp-login.php by name, not this address.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();

	$pagenow = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	require_once ABSPATH . 'wp-login.php';
	exit;
}

function soa_login_guard_wp_login() {
	if ( is_user_logged_in() || soa_login_action_must_work() ) {
		return;
	}
	soa_login_not_found();
}

function soa_login_guard_wp_admin() {
	// Front-end forms and other plugins post to these; they are not login pages.
	$open    = array( 'admin-ajax.php', 'admin-post.php' );
	$pagenow = isset( $GLOBALS['pagenow'] ) ? basename( (string) $GLOBALS['pagenow'] ) : '';

	if ( is_user_logged_in() || wp_doing_ajax() || in_array( $pagenow, $open, true ) ) {
		return;
	}
	soa_login_not_found();
}

/* -------------------------------------------------------------------------
 * Wiring, only when the feature is on
 *
 * WP-CLI, cron, the REST API and XML-RPC are left exactly as they were: WP-CLI
 * and cron bail out here, and the other two never match the paths below.
 * ---------------------------------------------------------------------- */

if ( ! soa_login_enabled() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
	return;
}

add_filter( 'site_url', function ( $url, $path, $scheme ) {
	return soa_login_filter_url( $url, $scheme );
}, 100, 3 );

add_filter( 'network_site_url', function ( $url, $path, $scheme ) {
	return soa_login_filter_url( $url, $scheme );
}, 100, 3 );

add_filter( 'wp_redirect', function ( $location ) {
	return soa_login_filter_url( $location );
}, 100, 1 );

// site_url already covers these, but plugins build them directly too.
foreach ( array( 'login_url', 'logout_url', 'lostpassword_url', 'register_url' ) as $soa_login_hook ) {
	add_filter( $soa_login_hook, function ( $url ) {
		return soa_login_filter_url( $url );
	}, 100, 1 );
}
unset( $soa_login_hook );

$soa_login_path = soa_login_request_path();

if ( 0 === strcasecmp( $soa_login_path, '/' . soa_login_slug() ) ) {
	add_action( 'wp_loaded', 'soa_login_serve', 1 );
} elseif ( preg_match( '#/wp-(login|register|signup|activate)\.php$#i', $soa_login_path ) ) {
	add_action( 'wp_loaded', 'soa_login_guard_wp_login', 1 );
} elseif ( preg_match( '#/wp-admin(/|$)#i', $soa_login_path . '/' ) ) {
	// Runs before WordPress's own redirect to the login page, which would
	// otherwise reveal the slug in the redirect_to parameter.
	add_action( 'wp_loaded', 'soa_login_guard_wp_admin', 1 );
}

unset( $soa_login_path );
