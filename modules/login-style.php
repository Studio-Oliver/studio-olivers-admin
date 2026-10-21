<?php
/**
 * Module: Login Style
 *
 * Brands the WordPress login page (log in, lost password, reset password and
 * two-factor screens) with the site's logo, colours and font. Flat: borders,
 * no shadows.
 *
 * Does nothing until a site sets its brand in wp-config.php, e.g.:
 *     define( 'SOA_LOGIN_BRAND', array(
 *         'logo'        => 'https://example.co.uk/wp-content/uploads/logo.svg',
 *         'logo_width'  => 136,  // px
 *         'logo_height' => 89,   // px
 *         'brand'       => '#151A4D', // buttons, links, headings, focus ring
 *         'brand_hover' => '#0E1236',
 *         'background'  => '#FADBD8', // page
 *         'border'      => '#F0CBC8', // card and input borders
 *         'text'        => '#1B1B1B',
 *         'font_name'   => 'Plus Jakarta Sans',
 *         'font_files'  => array( 400 => 'https://…/Plus-Jakarta-Sans-400.woff2', 600 => 'https://…/Plus-Jakarta-Sans-600.woff2' ),
 *         'radius'      => '12px', // card and inputs; the button is always a pill
 *     ) );
 * Any key can be left out. Override on one site with the 'soa_login_brand' filter.
 */

defined( 'ABSPATH' ) || exit;

/** The configured brand, or an empty array when the module is off. */
function soa_login_brand() {
	$brand = defined( 'SOA_LOGIN_BRAND' ) ? (array) SOA_LOGIN_BRAND : array();
	$brand = (array) apply_filters( 'soa_login_brand', $brand );
	if ( ! $brand ) {
		return array();
	}
	return array_merge( array(
		'logo'        => '',
		'logo_width'  => 120,
		'logo_height' => 80,
		'brand'       => '#1d2327',
		'brand_hover' => '#000000',
		'background'  => '#f6f7f7',
		'border'      => '#dcdcde',
		'text'        => '#1d2327',
		'font_name'   => '',
		'font_files'  => array(),
		'radius'      => '8px',
	), $brand );
}

if ( ! soa_login_brand() ) {
	return;
}

/** A CSS colour or length from config, with anything unsafe stripped out. */
function soa_login_css_value( $value ) {
	return preg_replace( '/[^#a-zA-Z0-9.%(), -]/', '', (string) $value );
}

/* Logo links to the site, not wordpress.org. */
add_filter( 'login_headerurl', function () {
	return home_url( '/' );
} );
add_filter( 'login_headertext', function () {
	return get_bloginfo( 'name' );
} );

/* No language switcher. */
add_filter( 'login_display_language_dropdown', '__return_false' );

add_action( 'login_enqueue_scripts', function () {
	$b = soa_login_brand();
	$v = 'soa_login_css_value';

	$font_faces = '';
	$font_stack = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
	if ( $b['font_name'] ) {
		$family = str_replace( array( '"', "'", ';', '{', '}' ), '', (string) $b['font_name'] );
		foreach ( (array) $b['font_files'] as $weight => $url ) {
			$font_faces .= sprintf(
				'@font-face{font-family:"%1$s";src:url("%2$s") format("woff2");font-weight:%3$d;font-style:normal;font-display:swap;}',
				esc_attr( $family ),
				esc_url( $url ),
				(int) $weight
			);
		}
		$font_stack = '"' . esc_attr( $family ) . '", ' . $font_stack;
	}

	$logo = $b['logo']
		? sprintf(
			'background-image:url("%1$s");background-size:contain;background-position:center;width:%2$dpx;height:%3$dpx;',
			esc_url( $b['logo'] ),
			(int) $b['logo_width'],
			(int) $b['logo_height']
		)
		: '';

	$brand  = $v( $b['brand'] );
	$hover  = $v( $b['brand_hover'] );
	$bg     = $v( $b['background'] );
	$border = $v( $b['border'] );
	$text   = $v( $b['text'] );
	$radius = $v( $b['radius'] );
	?>
	<style id="soa-login-style">
		<?php echo $font_faces; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>
		body.login { background: <?php echo $bg; ?>; color: <?php echo $text; ?>; font-family: <?php echo $font_stack; ?>; }
		body.login #login { width: 360px; max-width: calc(100% - 32px); padding: 8vh 0 32px; }

		/* Logo */
		body.login h1 a { <?php echo $logo; ?> margin: 0 auto 28px; }

		/* Card: flat, 1px border, no shadow */
		body.login form,
		body.login .two-factor-prompt,
		body.login #login form#loginform,
		body.login #login form#lostpasswordform,
		body.login #login form#resetpassform {
			background: #fff; border: 1px solid <?php echo $border; ?>; border-radius: <?php echo $radius; ?>;
			box-shadow: none; padding: 28px 28px 24px; margin-top: 0;
		}

		/* Labels and text */
		body.login label { color: <?php echo $brand; ?>; font-size: 14px; font-weight: 600; }
		body.login form p, body.login .description { color: <?php echo $text; ?>; }

		/* Inputs */
		body.login input[type="text"],
		body.login input[type="email"],
		body.login input[type="password"],
		body.login input[type="tel"],
		body.login input[type="number"] {
			background: #fff; border: 1px solid <?php echo $border; ?>; border-radius: <?php echo $radius; ?>;
			box-shadow: none; color: <?php echo $text; ?>; font-family: inherit; font-size: 16px;
			padding: 8px 12px; min-height: 44px; margin-top: 4px;
		}
		body.login input:focus,
		body.login .button:focus,
		body.login a:focus { outline: 2px solid <?php echo $brand; ?>; outline-offset: 2px; box-shadow: none; border-color: <?php echo $brand; ?>; }
		body.login input[type="checkbox"] { border-color: <?php echo $border; ?>; box-shadow: none; }

		/* Show-password button */
		body.login .wp-pwd .button.wp-hide-pw { color: <?php echo $brand; ?>; min-height: 44px; }
		body.login .wp-pwd .button.wp-hide-pw:focus { outline-offset: -2px; }

		/* Primary button: a pill in the brand colour */
		body.login .button-primary,
		body.login .button.button-primary {
			background: <?php echo $brand; ?>; border: 1px solid <?php echo $brand; ?>; border-radius: 999rem;
			box-shadow: none; text-shadow: none; color: #fff; font-family: inherit; font-weight: 600;
			min-height: 44px; padding: 0 24px; float: right;
		}
		body.login .button-primary:hover,
		body.login .button-primary:active { background: <?php echo $hover; ?>; border-color: <?php echo $hover; ?>; color: #fff; }
		body.login .button:not(.button-primary):not(.wp-hide-pw) { color: <?php echo $brand; ?>; border-color: <?php echo $border; ?>; border-radius: 999rem; background: #fff; box-shadow: none; }

		/* Messages */
		body.login .message,
		body.login .notice,
		body.login #login_error,
		body.login .success {
			background: #fff; border: 1px solid <?php echo $border; ?>; border-left: 4px solid <?php echo $brand; ?>;
			border-radius: <?php echo $radius; ?>; box-shadow: none; color: <?php echo $text; ?>;
		}
		body.login #login_error, body.login .notice-error { border-left-color: #b32d2e; }

		/* Links under the card */
		body.login #nav, body.login #backtoblog { padding: 0 4px; margin-top: 16px; }
		body.login #nav a, body.login #backtoblog a, body.login .privacy-policy-page-link a, body.login form a {
			color: <?php echo $brand; ?>; text-decoration: underline; text-underline-offset: 3px;
		}
		body.login #nav a:hover, body.login #backtoblog a:hover, body.login form a:hover { color: <?php echo $hover; ?>; }
		body.login .privacy-policy-page-link { margin: 24px 0 0; }
	</style>
	<?php
} );
