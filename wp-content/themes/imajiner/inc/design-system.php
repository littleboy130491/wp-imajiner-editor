<?php
/** Persistent design styles shared by the front end and block editor. */
defined( 'ABSPATH' ) || exit;

function imajiner_design_token_file() {
	return get_stylesheet_directory() . '/assets/css/design-tokens.css';
}

function imajiner_design_token_sources() {
	return apply_filters(
		'imajiner_design_token_sources',
		array( get_template_directory() . '/assets/css/base.css', get_stylesheet_directory() . '/style.css', imajiner_design_token_file() )
	);
}

function imajiner_design_token_url() {
	$path = imajiner_design_token_file();
	if ( ! is_child_theme() || ! is_file( $path ) ) {
		return '';
	}
	return add_query_arg( 'ver', (string) filemtime( $path ), get_stylesheet_directory_uri() . '/assets/css/design-tokens.css' );
}

function imajiner_enqueue_design_tokens() {
	$url = imajiner_design_token_url();
	if ( $url ) {
		wp_enqueue_style( 'imajiner-design-tokens', $url, array( 'imajiner-base', 'imajiner-child' ), null );
		do_action( 'imajiner_design_tokens_loaded', imajiner_design_token_file(), 'front' );
	}
}

function imajiner_editor_design_tokens( $settings ) {
	$url = imajiner_design_token_url();
	if ( $url ) {
		$settings['styles'][] = array( 'css' => '@import url("' . esc_url_raw( $url ) . '");' );
		do_action( 'imajiner_design_tokens_loaded', imajiner_design_token_file(), 'editor' );
	}
	return $settings;
}
add_filter( 'block_editor_settings_all', 'imajiner_editor_design_tokens', 20 );
