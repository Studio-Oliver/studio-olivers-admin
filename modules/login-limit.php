<?php
/**
 * Module: Login attempt limit
 *
 * Slows brute-force attacks: after a number of failed logins from one IP
 * address within a window, that address is refused for a while. Counts live in
 * transients, so nothing is added to the database permanently.
 *
 * Set it at Settings → Studio Olivers. Off until switched on there.
 *
 * LOCKED OUT? Over SSH, either of these clears it:
 *     wp option delete soa_login_limit   # turns the limiter off entirely
 *     wp cache flush                     # drops the counters (Redis sites)
 * Or add the address to the allowlist in wp-config.php:
 *     define( 'SOA_LOGIN_ALLOWED_IPS', array( '203.0.113.4', '203.0.113.0/24' ) );
 *
 * Fail-open by design. If the real client address can't be established, the
 * limiter does nothing rather than risk putting every visitor in one bucket,
 * where one attacker would lock out everybody. On Cloudways (nginx → Apache →
 * PHP-FPM) REMOTE_ADDR is the real client address, so this is the normal path.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

function soa_login_limit_defaults() {
	return array(
		'enabled'         => false,
		'max_attempts'    => 5,
		'window_minutes'  => 15,
		'lockout_minutes' => 15,
		'allowlist'       => array(),
	);
}

function soa_login_limit_settings() {
	$stored = get_option( 'soa_login_limit', array() );
	$stored = is_array( $stored ) ? $stored : array();
	return array_merge( soa_login_limit_defaults(), $stored );
}

function soa_login_limit_enabled() {
	$settings = soa_login_limit_settings();
	return ! empty( $settings['enabled'] );
}

/** Allowlisted addresses: the setting, plus anything wp-config.php adds. */
function soa_login_limit_allowlist() {
	$settings = soa_login_limit_settings();
	$list     = is_array( $settings['allowlist'] ) ? $settings['allowlist'] : array();

	if ( defined( 'SOA_LOGIN_ALLOWED_IPS' ) ) {
		$list = array_merge( $list, (array) SOA_LOGIN_ALLOWED_IPS );
	}
	return array_values( array_unique( (array) apply_filters( 'soa_login_limit_allowlist', $list ) ) );
}

/* -------------------------------------------------------------------------
 * Addresses
 * ---------------------------------------------------------------------- */

/** A routable address we're willing to count against. */
function soa_login_limit_is_usable_ip( $ip ) {
	return (bool) filter_var(
		$ip,
		FILTER_VALIDATE_IP,
		FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
	);
}

/**
 * The client's address, or '' when it can't be trusted.
 *
 * REMOTE_ADDR comes first and is never overridden by a header, because a
 * forwarded-for header the client controls would let an attacker both dodge
 * the limit and lock out whoever they chose. The header is only consulted when
 * REMOTE_ADDR is itself local, which means a proxy really is in front.
 */
function soa_login_limit_client_ip() {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';

	if ( soa_login_limit_is_usable_ip( $remote ) ) {
		return $remote;
	}

	$header = defined( 'SOA_LOGIN_IP_HEADER' ) ? (string) SOA_LOGIN_IP_HEADER : 'HTTP_X_FORWARDED_FOR';
	if ( ! empty( $_SERVER[ $header ] ) ) {
		foreach ( explode( ',', (string) $_SERVER[ $header ] ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( soa_login_limit_is_usable_ip( $candidate ) ) {
				return $candidate;
			}
		}
	}

	return '';
}

/** Does $ip fall inside $range (a single address or CIDR block)? */
function soa_login_limit_ip_matches( $ip, $range ) {
	$range = trim( (string) $range );
	if ( '' === $range ) {
		return false;
	}

	if ( false === strpos( $range, '/' ) ) {
		$a = @inet_pton( $ip );
		$b = @inet_pton( $range );
		return false !== $a && false !== $b && $a === $b;
	}

	list( $subnet, $bits ) = explode( '/', $range, 2 );
	$bits   = (int) $bits;
	$binary = @inet_pton( $ip );
	$net    = @inet_pton( trim( $subnet ) );

	if ( false === $binary || false === $net || strlen( $binary ) !== strlen( $net ) ) {
		return false;
	}
	if ( $bits < 0 || $bits > strlen( $binary ) * 8 ) {
		return false;
	}

	$whole     = intdiv( $bits, 8 );
	$remainder = $bits % 8;

	if ( $whole > 0 && 0 !== strncmp( $binary, $net, $whole ) ) {
		return false;
	}
	if ( 0 === $remainder ) {
		return true;
	}

	$mask = chr( ( 0xFF << ( 8 - $remainder ) ) & 0xFF );
	return ( $binary[ $whole ] & $mask ) === ( $net[ $whole ] & $mask );
}

function soa_login_limit_is_allowed( $ip ) {
	foreach ( soa_login_limit_allowlist() as $range ) {
		if ( soa_login_limit_ip_matches( $ip, $range ) ) {
			return true;
		}
	}
	return false;
}

/* -------------------------------------------------------------------------
 * Counters
 *
 * Transient names hold a salted hash, not the address itself, so the address
 * isn't sitting in the options table or in Redis keys in the clear.
 * ---------------------------------------------------------------------- */

function soa_login_limit_key( $ip, $kind ) {
	return 'soa_ll_' . $kind . '_' . substr( md5( $ip . wp_salt( 'nonce' ) ), 0, 20 );
}

/** Seconds left on a lockout, or 0 when the address isn't locked out. */
function soa_login_limit_remaining( $ip ) {
	$until = get_transient( soa_login_limit_key( $ip, 'block' ) );
	if ( ! $until ) {
		return 0;
	}
	return max( 0, (int) $until - time() );
}

/**
 * Record a failed attempt and lock the address out once it hits the limit.
 *
 * The window is a fixed one starting at the first failure. set_transient always
 * resets its own expiry, so the remaining window is recalculated each time
 * rather than letting a steady trickle of attempts extend it indefinitely.
 */
function soa_login_limit_record_failure() {
	$ip = soa_login_limit_client_ip();
	if ( '' === $ip || soa_login_limit_is_allowed( $ip ) ) {
		return;
	}

	$settings = soa_login_limit_settings();
	$window   = max( 1, (int) $settings['window_minutes'] ) * MINUTE_IN_SECONDS;
	$lockout  = max( 1, (int) $settings['lockout_minutes'] ) * MINUTE_IN_SECONDS;
	$max      = max( 1, (int) $settings['max_attempts'] );

	$key   = soa_login_limit_key( $ip, 'fail' );
	$state = get_transient( $key );
	if ( ! is_array( $state ) || empty( $state['start'] ) ) {
		$state = array( 'count' => 0, 'start' => time() );
	}
	$state['count']++;

	if ( $state['count'] >= $max ) {
		delete_transient( $key );
		set_transient( soa_login_limit_key( $ip, 'block' ), time() + $lockout, $lockout );

		/**
		 * Fires when an address is locked out. Useful for alerting.
		 *
		 * @param string $ip      The address, for logging.
		 * @param int    $lockout Seconds the lockout lasts.
		 */
		do_action( 'soa_login_limit_locked_out', $ip, $lockout );
		return;
	}

	$left = max( 1, $window - ( time() - (int) $state['start'] ) );
	set_transient( $key, $state, $left );
}

function soa_login_limit_clear( $ip = null ) {
	$ip = null === $ip ? soa_login_limit_client_ip() : $ip;
	if ( '' === $ip ) {
		return;
	}
	delete_transient( soa_login_limit_key( $ip, 'fail' ) );
	delete_transient( soa_login_limit_key( $ip, 'block' ) );
}

/* -------------------------------------------------------------------------
 * Enforcement
 * ---------------------------------------------------------------------- */

/**
 * Refuse the attempt while an address is locked out.
 *
 * Runs before WordPress checks the password, so a locked-out attacker learns
 * nothing about whether the credentials were right.
 */
function soa_login_limit_check( $user, $username = '', $password = '' ) {
	// Not an actual credential check (WordPress calls this on empty forms too).
	if ( '' === (string) $username && '' === (string) $password ) {
		return $user;
	}

	$ip = soa_login_limit_client_ip();
	if ( '' === $ip || soa_login_limit_is_allowed( $ip ) ) {
		return $user;
	}

	$remaining = soa_login_limit_remaining( $ip );
	if ( $remaining <= 0 ) {
		return $user;
	}

	return new WP_Error(
		'soa_login_locked_out',
		sprintf(
			/* translators: %d: whole minutes remaining. */
			esc_html__( 'Too many failed login attempts. Try again in %d minutes.', 'studio-olivers-admin' ),
			max( 1, (int) ceil( $remaining / MINUTE_IN_SECONDS ) )
		)
	);
}

if ( soa_login_limit_enabled() ) {
	add_filter( 'authenticate', 'soa_login_limit_check', 1, 3 );
	add_action( 'wp_login_failed', 'soa_login_limit_record_failure', 10, 0 );
	add_action( 'wp_login', function () {
		soa_login_limit_clear();
	}, 10, 0 );
}

/* -------------------------------------------------------------------------
 * Settings screen
 * ---------------------------------------------------------------------- */

add_action( 'admin_init', function () {
	if ( ! function_exists( 'soa_settings_page' ) ) {
		return;
	}

	register_setting( soa_settings_page(), 'soa_login_limit', array(
		'type'              => 'array',
		'sanitize_callback' => 'soa_login_limit_sanitize',
		'default'           => soa_login_limit_defaults(),
	) );

	add_settings_section(
		'soa_login_limit',
		__( 'Failed login limit', 'studio-olivers-admin' ),
		'soa_login_limit_intro',
		soa_settings_page()
	);

	add_settings_field(
		'soa_login_limit_enabled',
		__( 'Limit attempts', 'studio-olivers-admin' ),
		'soa_login_limit_field_enabled',
		soa_settings_page(),
		'soa_login_limit'
	);

	add_settings_field(
		'soa_login_limit_numbers',
		__( 'Thresholds', 'studio-olivers-admin' ),
		'soa_login_limit_field_numbers',
		soa_settings_page(),
		'soa_login_limit'
	);

	add_settings_field(
		'soa_login_limit_allowlist',
		__( 'Never limit these addresses', 'studio-olivers-admin' ),
		'soa_login_limit_field_allowlist',
		soa_settings_page(),
		'soa_login_limit'
	);
} );

function soa_login_limit_intro() {
	$ip = soa_login_limit_client_ip();

	printf(
		'<p>%s</p>',
		esc_html__( 'Refuses further logins from one address after too many failures. Counts are kept in transients, not in the database.', 'studio-olivers-admin' )
	);

	if ( '' === $ip ) {
		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html__( 'This site cannot establish the real visitor address, so the limiter will stay inactive even if switched on. That is deliberate: without a per-visitor address, one attacker would lock out everyone.', 'studio-olivers-admin' )
		);
	} else {
		printf(
			'<p class="description">%s <code>%s</code>%s</p>',
			esc_html__( 'Your address right now is', 'studio-olivers-admin' ),
			esc_html( $ip ),
			soa_login_limit_is_allowed( $ip )
				? ' — ' . esc_html__( 'already on the allowlist.', 'studio-olivers-admin' )
				: ' — ' . esc_html__( 'add it below if this is the office.', 'studio-olivers-admin' )
		);
	}

	printf(
		'<p class="description">%s<br><code>wp option delete soa_login_limit</code></p>',
		esc_html__( 'Locked out of a site? Either of these clears it over SSH:', 'studio-olivers-admin' )
	);
}

function soa_login_limit_field_enabled() {
	$settings = soa_login_limit_settings();
	printf(
		'<label><input type="checkbox" name="soa_login_limit[enabled]" value="1" %s> %s</label>',
		checked( ! empty( $settings['enabled'] ), true, false ),
		esc_html__( 'Lock out an address after repeated failed logins', 'studio-olivers-admin' )
	);
}

function soa_login_limit_field_numbers() {
	$settings = soa_login_limit_settings();
	printf(
		'<input type="number" min="1" max="50" name="soa_login_limit[max_attempts]" value="%s" style="width:5em"> %s<br>',
		esc_attr( $settings['max_attempts'] ),
		esc_html__( 'failed attempts', 'studio-olivers-admin' )
	);
	printf(
		'<input type="number" min="1" max="1440" name="soa_login_limit[window_minutes]" value="%s" style="width:5em"> %s<br>',
		esc_attr( $settings['window_minutes'] ),
		esc_html__( 'minutes to count them within', 'studio-olivers-admin' )
	);
	printf(
		'<input type="number" min="1" max="1440" name="soa_login_limit[lockout_minutes]" value="%s" style="width:5em"> %s',
		esc_attr( $settings['lockout_minutes'] ),
		esc_html__( 'minutes locked out', 'studio-olivers-admin' )
	);
}

function soa_login_limit_field_allowlist() {
	$settings = soa_login_limit_settings();
	printf(
		'<textarea name="soa_login_limit[allowlist]" rows="4" class="large-text code" placeholder="203.0.113.4&#10;203.0.113.0/24">%s</textarea>',
		esc_textarea( implode( "\n", (array) $settings['allowlist'] ) )
	);
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'One address or CIDR range per line. IPv4 and IPv6.', 'studio-olivers-admin' )
	);
}

function soa_login_limit_sanitize( $input ) {
	$settings = soa_login_limit_settings();
	$input    = is_array( $input ) ? $input : array();

	$settings['enabled']         = ! empty( $input['enabled'] );
	$settings['max_attempts']    = min( 50, max( 1, (int) ( $input['max_attempts'] ?? 5 ) ) );
	$settings['window_minutes']  = min( 1440, max( 1, (int) ( $input['window_minutes'] ?? 15 ) ) );
	$settings['lockout_minutes'] = min( 1440, max( 1, (int) ( $input['lockout_minutes'] ?? 15 ) ) );

	$clean   = array();
	$refused = array();
	foreach ( preg_split( '/[\r\n,]+/', (string) ( $input['allowlist'] ?? '' ) ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		// Valid if it parses as an address, or as address/prefix.
		$candidate = false === strpos( $line, '/' ) ? $line : explode( '/', $line, 2 )[0];
		if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			$clean[] = $line;
		} else {
			$refused[] = $line;
		}
	}
	$settings['allowlist'] = array_values( array_unique( $clean ) );

	if ( $refused ) {
		add_settings_error(
			'soa_login_limit',
			'soa_login_limit_allowlist',
			sprintf(
				/* translators: %s: the entries that were not valid addresses. */
				__( 'Ignored these allowlist entries, which are not valid addresses: %s', 'studio-olivers-admin' ),
				implode( ', ', array_map( 'esc_html', $refused ) )
			)
		);
	}

	return $settings;
}
