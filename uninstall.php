<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
wp_clear_scheduled_hook( 'of_purge' );

$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'open-forms-private';
if ( is_dir( $dir ) ) {
	foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $f ) {
		if ( is_file( $f ) ) {
			wp_delete_file( $f );
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

foreach ( get_posts( array( 'post_type' => 'open_form', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}of_entries" );
delete_option( 'of_settings' );
delete_option( 'of_db_ver' );
