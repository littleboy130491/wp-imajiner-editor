<?php
/**
 * Removes the plugin's settings, including encrypted API keys, when the plugin is deleted.
 *
 * Cache and revisions are kept unless the administrator explicitly opted in.
 *
 * @package Imajiner_Editor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-imajiner-filesystem.php';
require_once __DIR__ . '/includes/class-imajiner-secrets.php';
require_once __DIR__ . '/includes/class-imajiner-filesystem-credentials.php';

/** The cache is flat; unknown files and links are deliberately retained. */
function imajiner_editor_remove_preview_cache() {
	$uploads = wp_upload_dir( null, false );
	$dir = trailingslashit( $uploads['basedir'] ) . 'imajiner/preview';
	if ( ! Imajiner_Filesystem::exists( $dir ) ) {
		return;
	}
	$list = Imajiner_Filesystem::list_files( $dir );
	if ( is_wp_error( $list ) ) {
		return;
	}
	foreach ( array_slice( $list, 0, 10000, true ) as $name => $info ) {
		if ( ! isset( $info['type'] ) || 'f' !== $info['type'] || ! empty( $info['islink'] ) || ! preg_match( '/^[a-f0-9]{8}-[a-z0-9_-]+-[a-f0-9]{32}\.php$/i', $name ) ) {
			continue;
		}
		Imajiner_Filesystem::delete( $dir . '/' . $name );
	}
}

if ( get_option( 'imajiner_editor_remove_data', false ) ) {
	imajiner_editor_remove_preview_cache();
	for ( $batch = 0; $batch < 100; ++$batch ) {
		$ids = get_posts( array( 'post_type' => array( 'imajiner_revision', 'imajiner_design_rev', 'imajiner_ai_usage' ), 'post_status' => 'any', 'posts_per_page' => 100, 'fields' => 'ids' ) );
		if ( ! $ids ) {
			break;
		}
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
}

for ( $batch = 0; $batch < 100; ++$batch ) {
	$ids = get_posts( array( 'post_type' => 'imajiner_ai_job', 'post_status' => 'any', 'posts_per_page' => 100, 'fields' => 'ids' ) );
	if ( ! $ids ) {
		break;
	}
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
}
global $wpdb;
foreach ( array( '_transient_imajiner_ai_', '_transient_timeout_imajiner_ai_', '_transient_imajiner_design_', '_transient_timeout_imajiner_design_', '_transient_imajiner_stage_', '_transient_timeout_imajiner_stage_', 'imajiner_lock_', 'imajiner_design_lock_', '_imajiner_store_lock_', 'imajiner_ai_worker_' ) as $prefix ) {
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 10000", $wpdb->esc_like( $prefix ) . '%' ) );
	foreach ( $names as $name ) {
		delete_option( $name );
	}
}
wp_unschedule_hook( 'imajiner_ai_run_job' );
wp_unschedule_hook( 'imajiner_ai_expire_job' );

delete_metadata( 'user', 0, '_imajiner_fs_credentials', '', true );
wp_unschedule_hook( 'imajiner_fs_expire' );
delete_option( 'imajiner_editor_ai' );
delete_option( 'imajiner_editor_remove_data' );
