<?php

require_once WPCF7_PLUGIN_DIR . '/includes/functions.php';
require_once WPCF7_PLUGIN_DIR . '/includes/deprecated.php';
require_once WPCF7_PLUGIN_DIR . '/includes/formatting.php';
require_once WPCF7_PLUGIN_DIR . '/includes/pipe.php';
require_once WPCF7_PLUGIN_DIR . '/includes/shortcodes.php';
require_once WPCF7_PLUGIN_DIR . '/includes/capabilities.php';
require_once WPCF7_PLUGIN_DIR . '/includes/classes.php';

if ( is_admin() )
	require_once WPCF7_PLUGIN_DIR . '/admin/admin.php';
else
	require_once WPCF7_PLUGIN_DIR . '/includes/controller.php';

add_action( 'plugins_loaded', 'wpcf7', 1 );

function wpcf7() {
	global $wpcf7, $wpcf7_shortcode_manager;

	if ( ! is_object( $wpcf7 ) ) {
		$wpcf7 = (object) array(
			'processing_within' => '',
			'widget_count' => 0,
			'unit_count' => 0,
			'global_unit_count' => 0,
			'result' => array(),
			'request_uri' => null );
	}

	$wpcf7_shortcode_manager = new WPCF7_ShortcodeManager();

	wpcf7_load_textdomain();
	wpcf7_load_modules();
}

add_action( 'init', 'wpcf7_init' );

function wpcf7_init() {
	global $wpcf7;

	$wpcf7->request_uri = add_query_arg( array() );

	// Custom Post Type
	wpcf7_register_post_types();

	do_action( 'wpcf7_init' );
}

/* Upgrading */

add_action( 'admin_init', 'wpcf7_upgrade' );

function wpcf7_upgrade() {
	$opt = get_option( 'wpcf7' );

	if ( ! is_array( $opt ) )
		$opt = array();

	$old_ver = isset( $opt['version'] ) ? (string) $opt['version'] : '0';
	$new_ver = WPCF7_VERSION;

	if ( $old_ver == $new_ver )
		return;

	do_action( 'wpcf7_upgrade', $new_ver, $old_ver );

	$opt['version'] = $new_ver;

	update_option( 'wpcf7', $opt );
}

add_action( 'wpcf7_upgrade', 'wpcf7_convert_to_cpt', 10, 2 );

function wpcf7_convert_to_cpt( $new_ver, $old_ver ) {
	global $wpdb;

	if ( ! version_compare( $old_ver, '3.0-dev', '<' ) )
		return;

	$old_rows = array();

	$table_name = $wpdb->prefix . "contact_form_7";

	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) ) {
		$old_rows = $wpdb->get_results( "SELECT * FROM $table_name" );
	} elseif ( ( $opt = get_option( 'wpcf7' ) ) && ! empty( $opt['contact_forms'] ) ) {
		foreach ( (array) $opt['contact_forms'] as $key => $value ) {
			$old_rows[] = (object) array_merge( $value, array( 'cf7_unit_id' => $key ) );
		}
	}

	foreach ( (array) $old_rows as $row ) {
		$q = "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_old_cf7_unit_id'"
			. $wpdb->prepare( " AND meta_value = %d", $row->cf7_unit_id );

		if ( $wpdb->get_var( $q ) )
			continue;

		$postarr = array(
			'post_type' => 'wpcf7_contact_form',
			'post_status' => 'publish',
			'post_title' => maybe_unserialize( $row->title ) );

		$post_id = wp_insert_post( $postarr );

		if ( $post_id ) {
			update_post_meta( $post_id, '_old_cf7_unit_id', $row->cf7_unit_id );

			$metas = array( 'form', 'mail', 'mail_2', 'messages', 'additional_settings' );

			foreach ( $metas as $meta ) {
				update_post_meta( $post_id, '_' . $meta,
					wpcf7_normalize_newline_deep( maybe_unserialize( $row->{$meta} ) ) );
			}
		}
	}
}

add_action( 'wpcf7_upgrade', 'wpcf7_prepend_underscore', 10, 2 );

function wpcf7_prepend_underscore( $new_ver, $old_ver ) {
	if ( version_compare( $old_ver, '3.0-dev', '<' ) )
		return;

	if ( ! version_compare( $old_ver, '3.3-dev', '<' ) )
		return;

	$posts = WPCF7_ContactForm::find( array(
		'post_status' => 'any',
		'posts_per_page' => -1 ) );

	foreach ( $posts as $post ) {
		$props = $post->get_properties();

		foreach ( $props as $prop => $value ) {
			if ( metadata_exists( 'post', $post->id, '_' . $prop ) )
				continue;

			update_post_meta( $post->id, '_' . $prop, $value );
			delete_post_meta( $post->id, $prop );
		}
	}
}

/* Install and default settings */

add_action( 'activate_' . WPCF7_PLUGIN_BASENAME, 'wpcf7_install' );

function wpcf7_install() {
	if ( $opt = get_option( 'wpcf7' ) )
		return;

	wpcf7_load_textdomain();
	wpcf7_register_post_types();
	wpcf7_upgrade();

	if ( get_posts( array( 'post_type' => 'wpcf7_contact_form' ) ) )
		return;

	$contact_form = wpcf7_get_contact_form_default_pack(
		array( 'title' => sprintf( __( 'Contact form %d', 'contact-form-7' ), 1 ) ) );

	$contact_form->save();
}

?>