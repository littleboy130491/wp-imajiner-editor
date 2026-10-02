<?php
/**
 * Removes the plugin's settings, including encrypted API keys, when the plugin is deleted.
 *
 * History is retained unless the administrator explicitly opted into cleanup.
 *
 * @package Imajiner_Editor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$remove_history = get_option( 'imajiner_editor_remove_history', false );
delete_option( 'imajiner_editor_ai' );
delete_option( 'imajiner_editor_remove_history' );

global $wpdb;
$credential_options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_imajiner_fs_' ) . '%' ) );
foreach ( $credential_options as $name ) {
	delete_transient( substr( $name, strlen( '_transient_' ) ) );
}
if ( class_exists( 'Imajiner_Filesystem_Settings' ) ) {
	Imajiner_Filesystem_Settings::forget();
}

if ( ! $remove_history ) {
	return;
}

do {
	$ids = get_posts( array( 'post_type' => 'imajiner_revision', 'post_status' => 'any', 'posts_per_page' => 100, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
	$deleted = 0;
	foreach ( $ids as $id ) {
		if ( wp_delete_post( $id, true ) ) {
			++$deleted;
		}
	}
} while ( $deleted && count( $ids ) === 100 );

$uploads = wp_upload_dir( null, false );
$base = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
$cache = $base . '/imajiner/preview';
foreach ( array( $base, $base . '/imajiner', $cache ) as $ancestor ) {
	if ( is_link( $ancestor ) ) {
		return;
	}
}
$real_base = realpath( $base );
$real_cache = realpath( $cache );
if ( ! $real_base || ! $real_cache || wp_normalize_path( $real_cache ) !== wp_normalize_path( $real_base ) . '/imajiner/preview' ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/file.php';
if ( ! WP_Filesystem( false, $cache ) ) {
	return;
}
global $wp_filesystem;
$remote = $wp_filesystem->find_folder( $cache );
if ( ! $remote || '/' === $remote ) {
	return;
}
if ( 'direct' !== $wp_filesystem->method ) {
	if ( '/' !== substr( $remote, 0, 1 ) || preg_match( '#(^|/)\.{1,2}(/|$)|[\\\\\x00-\x1f]#', $remote ) ) {
		return;
	}
	$cursor = '';
	foreach ( explode( '/', trim( $remote, '/' ) ) as $segment ) {
		$list = $wp_filesystem->dirlist( $cursor ?: '/', true, false );
		if ( ! is_array( $list ) || ! isset( $list[ $segment ]['type'] ) || 'd' !== $list[ $segment ]['type'] || ! empty( $list[ $segment ]['islink'] ) || ( isset( $list[ $segment ]['perms'] ) && 'l' === substr( $list[ $segment ]['perms'], 0, 1 ) ) ) {
			return;
		}
		$cursor .= '/' . $segment;
		if ( $wp_filesystem instanceof WP_Filesystem_SSH2 ) {
			$stat = ssh2_sftp_lstat( $wp_filesystem->sftp_link, $cursor );
			if ( ! $stat || ( $stat['mode'] & 0170000 ) !== 0040000 ) {
				return;
			}
		}
	}
}
$entries = $wp_filesystem->dirlist( $remote, true, false );
if ( ! is_array( $entries ) ) {
	return;
}
foreach ( $entries as $name => $entry ) {
	if ( ! preg_match( '/^[a-f0-9]{8}-[a-z0-9_-]+(?:-stage-[0-9]+)?-[a-f0-9]{32}\.php$/D', $name ) || ! isset( $entry['type'] ) || 'f' !== $entry['type'] || ! empty( $entry['islink'] ) || ( isset( $entry['perms'] ) && 'l' === substr( $entry['perms'], 0, 1 ) ) || is_link( $cache . '/' . $name ) ) {
		continue;
	}
	if ( $wp_filesystem instanceof WP_Filesystem_SSH2 ) {
		$stat = ssh2_sftp_lstat( $wp_filesystem->sftp_link, trailingslashit( $remote ) . $name );
		if ( ! $stat || ( $stat['mode'] & 0170000 ) !== 0100000 ) {
			continue;
		}
	}
	$wp_filesystem->delete( trailingslashit( $remote ) . $name, false, 'f' );
}
