<?php
/** Plugin-independent design-system assets and source discovery. */
defined( 'ABSPATH' ) || exit;

/** @return string Persistent token path in the active theme. */
function imajiner_design_tokens_file() {
	return get_stylesheet_directory() . '/assets/css/design-tokens.css';
}

/** @return string[] CSS sources in cascade order, for token readers. */
function imajiner_design_token_sources() {
	$paths = array( get_template_directory() . '/assets/css/base.css' );
	if ( is_child_theme() ) {
		$paths[] = get_stylesheet_directory() . '/style.css';
	}
	$paths[] = imajiner_design_tokens_file();
	return apply_filters( 'imajiner_design_token_sources', $paths );
}

/** Loads accepted child tokens even when the editor plugin is inactive. */
function imajiner_enqueue_design_tokens() {
	$path = imajiner_design_tokens_file();
	if ( is_file( $path ) ) {
		$dependencies = is_child_theme() ? array( 'imajiner-child' ) : array( 'imajiner-base' );
		wp_enqueue_style( 'imajiner-design-tokens', get_stylesheet_directory_uri() . '/assets/css/design-tokens.css', $dependencies, (string) filemtime( $path ) );
		do_action( 'imajiner_design_tokens_enqueued', $path, 'imajiner-design-tokens' );
	}
}

/** Block-editor content receives the same cascade, including accepted tokens. */
function imajiner_enqueue_editor_design_system() {
	if ( ! is_admin() ) {
		return;
	}
	wp_enqueue_style( 'imajiner-base', get_template_directory_uri() . '/assets/css/base.css', array(), IMAJINER_VERSION );
	if ( is_child_theme() ) {
		wp_enqueue_style( 'imajiner-child', get_stylesheet_uri(), array( 'imajiner-base' ), wp_get_theme()->get( 'Version' ) );
	}
	imajiner_enqueue_design_tokens();
	$dependency = is_file( imajiner_design_tokens_file() ) ? 'imajiner-design-tokens' : ( is_child_theme() ? 'imajiner-child' : 'imajiner-base' );
	wp_enqueue_style( 'imajiner-editor-content', get_template_directory_uri() . '/assets/css/editor.css', array( $dependency ), IMAJINER_VERSION );
	$child_editor = get_stylesheet_directory() . '/editor.css';
	if ( is_child_theme() && is_file( $child_editor ) ) {
		wp_enqueue_style( 'imajiner-child-editor', get_stylesheet_directory_uri() . '/editor.css', array( 'imajiner-editor-content' ), (string) filemtime( $child_editor ) );
	}
}
add_action( 'enqueue_block_assets', 'imajiner_enqueue_editor_design_system' );
