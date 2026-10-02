<?php
/**
 * Imajiner theme setup.
 *
 * Base theme for the Imajiner Editor plugin. Site templates live in the child
 * theme under imajiner/ (e.g. imajiner/page-home.php), each with an optional
 * stylesheet at imajiner/css/page-home.css.
 *
 * @package Imajiner
 */

defined( 'ABSPATH' ) || exit;

define( 'IMAJINER_VERSION', '0.1.0' );

/**
 * Folder, relative to the active theme, that holds Imajiner templates.
 */
define( 'IMAJINER_TEMPLATE_DIR', 'imajiner' );

/**
 * Registers theme supports and menu locations.
 */
function imajiner_setup() {
	load_theme_textdomain( 'imajiner', get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' )
	);

	register_nav_menus(
		array(
			'primary' => __( 'Primary Menu', 'imajiner' ),
			'footer'  => __( 'Footer Menu', 'imajiner' ),
		)
	);
}
add_action( 'after_setup_theme', 'imajiner_setup' );

/**
 * Returns the slug of the Imajiner template used by the current request.
 *
 * For a page using imajiner/page-home.php this returns 'page-home'.
 *
 * @return string Template slug, or '' when the request doesn't use an Imajiner template.
 */
function imajiner_current_template_slug() {
	if ( ! is_singular() ) {
		return '';
	}

	$template = get_page_template_slug();
	if ( ! $template || ! str_starts_with( $template, IMAJINER_TEMPLATE_DIR . '/' ) ) {
		return '';
	}

	return basename( $template, '.php' );
}

/**
 * Enqueues the base styles, the child theme stylesheet and the current template's CSS.
 */
function imajiner_enqueue_assets() {
	wp_enqueue_style( 'imajiner-base', get_template_directory_uri() . '/assets/css/base.css', array(), IMAJINER_VERSION );

	if ( is_child_theme() ) {
		wp_enqueue_style( 'imajiner-child', get_stylesheet_uri(), array( 'imajiner-base' ), wp_get_theme()->get( 'Version' ) );
	}

	$slug = imajiner_current_template_slug();
	if ( ! $slug ) {
		return;
	}

	$relative = IMAJINER_TEMPLATE_DIR . '/css/' . $slug . '.css';
	$path     = get_stylesheet_directory() . '/' . $relative;
	if ( file_exists( $path ) ) {
		// Version by mtime so edits saved from the editor bust the browser cache.
		wp_enqueue_style( 'imajiner-template-' . $slug, get_stylesheet_directory_uri() . '/' . $relative, array( 'imajiner-base' ), (string) filemtime( $path ) );
	}
}
add_action( 'wp_enqueue_scripts', 'imajiner_enqueue_assets' );

/**
 * Adds an imj-{slug} body class so template CSS can be scoped to its template.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function imajiner_body_class( $classes ) {
	$slug = imajiner_current_template_slug();
	if ( $slug ) {
		$classes[] = 'imj-' . $slug;
	}
	return $classes;
}
add_filter( 'body_class', 'imajiner_body_class' );
