<?php
/**
 * Module: Settings
 *
 * One settings screen for the whole plugin, at Settings → Studio Olivers.
 * Modules add their own sections and fields with the WordPress Settings API,
 * registering against the page slug that soa_settings_page() returns. Each
 * module keeps its own option, so a module's settings can be reset on their
 * own without disturbing anything else.
 */

defined( 'ABSPATH' ) || exit;

/** The settings page slug, also used as the Settings API option group. */
function soa_settings_page() {
	return 'soa-settings';
}

/** The URL of the settings screen. */
function soa_settings_url() {
	return admin_url( 'options-general.php?page=' . soa_settings_page() );
}

add_action( 'admin_menu', function () {
	add_options_page(
		__( 'Studio Olivers Admin', 'studio-olivers-admin' ),
		__( 'Studio Olivers', 'studio-olivers-admin' ),
		'manage_options',
		soa_settings_page(),
		'soa_render_settings_screen'
	);
} );

/* A Settings link next to Deactivate on the Plugins page. */
add_filter( 'plugin_action_links_studio-olivers-admin/studio-olivers-admin.php', function ( $links ) {
	array_unshift( $links, sprintf(
		'<a href="%s">%s</a>',
		esc_url( soa_settings_url() ),
		esc_html__( 'Settings', 'studio-olivers-admin' )
	) );
	return $links;
} );

function soa_render_settings_screen() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Studio Olivers Admin', 'studio-olivers-admin' ); ?></h1>

		<?php settings_errors(); ?>

		<form method="post" action="options.php">
			<?php
			settings_fields( soa_settings_page() );
			do_settings_sections( soa_settings_page() );
			submit_button();
			?>
		</form>

		<p class="description">
			<?php
			printf(
				/* translators: %s: plugin version number. */
				esc_html__( 'Studio Olivers Admin %s', 'studio-olivers-admin' ),
				esc_html( defined( 'SOA_VERSION' ) ? SOA_VERSION : '' )
			);
			?>
		</p>
	</div>
	<?php
}
