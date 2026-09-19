<?php
/**
 * Module: Support
 *
 * Adds a "Support" page to the admin menu, a dashboard widget and an admin
 * footer link so clients always know how to reach Studio Olivers.
 *
 * Override any detail on one site (e.g. a different contact person):
 *     add_filter( 'soa_contact_details', function ( $d ) {
 *         $d['phone'] = '01234 567890';
 *         return $d;
 *     } );
 */

defined( 'ABSPATH' ) || exit;

/** Studio contact details. Empty values are hidden. Everything here is public. */
function soa_contact_details() {
	return (array) apply_filters( 'soa_contact_details', array(
		'company'  => 'Studio Olivers',
		'email'    => 'support@studio-olivers.co.uk',
		'phone'    => '01606 241021',
		'whatsapp' => '+44 7360 518121',
		'website'  => 'https://studio-olivers.co.uk',
		'hours'    => 'Mon–Fri, 8:30am–6:30pm',
		'intro'    => 'Need a hand with your website? Get in touch and we\'ll help.',
	) );
}

/** A mailto link with the site's name and address already in the email. */
function soa_support_mailto( $email ) {
	$subject = sprintf( 'Website support: %s', get_bloginfo( 'name' ) );
	$body    = sprintf(
		"Site: %s\nLogged in as: %s\n\nWhat do you need help with?\n\n",
		home_url(),
		wp_get_current_user()->user_login
	);
	return 'mailto:' . $email . '?subject=' . rawurlencode( $subject ) . '&body=' . rawurlencode( $body );
}

/** The contact list shared by the page and the dashboard widget. */
function soa_render_contact_list() {
	$d = soa_contact_details();

	echo '<ul class="soa-contact">';
	if ( $d['email'] ) {
		printf(
			'<li><span class="dashicons dashicons-email"></span><a href="%s">%s</a></li>',
			esc_attr( soa_support_mailto( $d['email'] ) ),
			esc_html( $d['email'] )
		);
	}
	if ( $d['phone'] ) {
		printf(
			'<li><span class="dashicons dashicons-phone"></span><a href="tel:%s">%s</a></li>',
			esc_attr( preg_replace( '/[^0-9+]/', '', $d['phone'] ) ),
			esc_html( $d['phone'] )
		);
	}
	if ( ! empty( $d['whatsapp'] ) ) {
		printf(
			'<li><span class="dashicons dashicons-whatsapp"></span><a href="https://wa.me/%s" target="_blank" rel="noopener">%s</a> (WhatsApp)</li>',
			esc_attr( preg_replace( '/[^0-9]/', '', $d['whatsapp'] ) ),
			esc_html( $d['whatsapp'] )
		);
	}
	if ( $d['website'] ) {
		printf(
			'<li><span class="dashicons dashicons-admin-site-alt3"></span><a href="%s" target="_blank" rel="noopener">%s</a></li>',
			esc_url( $d['website'] ),
			esc_html( preg_replace( '#^https?://(www\.)?|/$#', '', $d['website'] ) )
		);
	}
	if ( $d['hours'] ) {
		printf( '<li><span class="dashicons dashicons-clock"></span>%s</li>', esc_html( $d['hours'] ) );
	}
	echo '</ul>';
}

/* Styles for the page and widget. */
function soa_support_styles() {
	echo '<style>
		.soa-contact { margin: 12px 0 0; }
		.soa-contact li { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; font-size: 14px; }
		.soa-contact .dashicons { color: #646970; }
		.soa-support .card { max-width: 560px; padding: 8px 24px 16px; }
		.soa-support .soa-site-info td { padding: 4px 16px 4px 0; }
	</style>';
}

/* 1. Admin menu page. Editors and above can see it. */
add_action( 'admin_menu', function () {
	add_menu_page(
		__( 'Support', 'studio-olivers-admin' ),
		__( 'Support', 'studio-olivers-admin' ),
		'edit_posts',
		'soa-support',
		'soa_render_support_page',
		'dashicons-sos',
		99
	);
} );

function soa_render_support_page() {
	$d = soa_contact_details();
	soa_support_styles();
	?>
	<div class="wrap soa-support">
		<h1><?php echo esc_html( $d['company'] ); ?> support</h1>

		<div class="card">
			<h2><?php esc_html_e( 'Get in touch', 'studio-olivers-admin' ); ?></h2>
			<?php if ( $d['intro'] ) : ?>
				<p><?php echo esc_html( $d['intro'] ); ?></p>
			<?php endif; ?>
			<?php soa_render_contact_list(); ?>
		</div>

		<div class="card">
			<h2><?php esc_html_e( 'About this website', 'studio-olivers-admin' ); ?></h2>
			<p><?php esc_html_e( 'If you contact us, these details help us find your site quickly.', 'studio-olivers-admin' ); ?></p>
			<table class="soa-site-info">
				<tr><td><strong>Site</strong></td><td><?php echo esc_html( home_url() ); ?></td></tr>
				<tr><td><strong>WordPress</strong></td><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
				<tr><td><strong>PHP</strong></td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
				<tr><td><strong>Theme</strong></td><td><?php echo esc_html( wp_get_theme()->get( 'Name' ) ); ?></td></tr>
			</table>
		</div>
	</div>
	<?php
}

/* 2. Dashboard widget, kept even when Quiet Admin removes other widgets. */
add_action( 'wp_dashboard_setup', function () {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$d = soa_contact_details();
	wp_add_dashboard_widget( 'soa_support', sprintf( '%s support', $d['company'] ), function () use ( $d ) {
		soa_support_styles();
		if ( $d['intro'] ) {
			echo '<p>' . esc_html( $d['intro'] ) . '</p>';
		}
		soa_render_contact_list();
	}, null, null, 'normal', 'high' );
} );

add_filter( 'soa_dashboard_widgets', function ( $keep ) {
	$keep[] = 'soa_support';
	return $keep;
} );

/* 3. Admin footer: "Website by Studio Olivers · Get support". */
add_filter( 'admin_footer_text', function () {
	$d = soa_contact_details();
	return sprintf(
		'Website by %s &middot; <a href="%s">Get support</a>',
		esc_html( $d['company'] ),
		esc_url( admin_url( 'admin.php?page=soa-support' ) )
	);
} );
