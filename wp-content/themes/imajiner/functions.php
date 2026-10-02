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

require_once get_template_directory() . '/inc/parts.php';
require_once get_template_directory() . '/inc/locations.php';

/**
 * Registers theme supports and menu locations.
 */
function imajiner_setup() {
	load_theme_textdomain( 'imajiner', get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo' );
	add_theme_support( 'responsive-embeds' );

	// Theme Hook Alliance hooks (tha_header_before, tha_footer_before, …), fired from the templates.
	add_theme_support( 'tha_hooks', array( 'all' ) );
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
 * Returns the slug of the Imajiner template rendering the current request:
 * a page template chosen for the page, or a single/archive template assigned to a location.
 *
 * For imajiner/page-home.php this returns 'page-home'. Known once template_include has run.
 *
 * @return string Template slug, or '' when the request doesn't use an Imajiner template.
 */
function imajiner_current_template_slug() {
	return isset( $GLOBALS['imajiner_current_template'] ) ? $GLOBALS['imajiner_current_template'] : '';
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

	$templates = imajiner_get_templates();
	$path      = imajiner_css_file( $templates[ $slug ]['file'] );
	if ( file_exists( $path ) ) {
		// Version by mtime so edits saved from the editor bust the browser cache.
		wp_enqueue_style( 'imajiner-template-' . $slug, imajiner_file_uri( $path ), array( 'imajiner-base' ), (string) filemtime( $path ) );
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

/**
 * Hides Appearance → Patterns. WordPress adds it for classic themes; pages here
 * are built in the Imajiner Editor, so block patterns only add confusion.
 * Patterns stay available inside the block editor for regular posts.
 */
function imajiner_hide_patterns_menu() {
	remove_submenu_page( 'themes.php', 'site-editor.php?p=/pattern' );
}
add_action( 'admin_menu', 'imajiner_hide_patterns_menu', 999 );
