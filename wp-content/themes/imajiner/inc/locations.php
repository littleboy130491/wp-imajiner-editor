<?php
/**
 * Single and archive templates, assigned to locations in their file header.
 *
 * A template in imajiner/<slug>.php can declare where it applies:
 *
 *     Imajiner Location: single:product, taxonomy:product_cat
 *
 * Locations are detected from the site's public post types and taxonomies (see
 * imajiner_template_locations()). For each request the most specific location
 * wins: single:<type> before single, archive:<type> / taxonomy:<tax> / author /
 * date before archive. A template chosen for a single post or page in the
 * editor's Template dropdown always wins over a location.
 *
 * Keeping the assignment in the file means it travels with the child theme in git.
 *
 * @package Imajiner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Locations a template can be assigned to, from the registered post types and taxonomies.
 *
 * @return array Location => label.
 */
function imajiner_template_locations() {
	$post_types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $post_types['attachment'] );

	$locations = array(
		'front'  => __( 'Front page', 'imajiner-editor' ),
		'home'   => __( 'Blog posts page', 'imajiner-editor' ),
		'single' => __( 'All single posts and pages', 'imajiner' ),
	);
	foreach ( $post_types as $type ) {
		/* translators: %s: post type name, e.g. "Post". */
		$locations[ 'single:' . $type->name ] = sprintf( __( 'Single %s', 'imajiner' ), $type->labels->singular_name );
	}

	$locations['archive'] = __( 'All archives', 'imajiner' );
	foreach ( $post_types as $type ) {
		if ( 'post' === $type->name || $type->has_archive ) {
			/* translators: %s: post type plural name, e.g. "Posts". */
			$locations[ 'archive:' . $type->name ] = sprintf( __( '%s archive', 'imajiner' ), $type->labels->name );
		}
	}

	$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
	unset( $taxonomies['post_format'] );
	foreach ( $taxonomies as $taxonomy ) {
		/* translators: %s: taxonomy name, e.g. "Category". */
		$locations[ 'taxonomy:' . $taxonomy->name ] = sprintf( __( '%s archive', 'imajiner' ), $taxonomy->labels->singular_name );
		$terms = get_terms( array( 'taxonomy' => $taxonomy->name, 'hide_empty' => false ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				/* translators: 1: taxonomy label, 2: term name. */
				$locations[ 'taxonomy:' . $taxonomy->name . ':' . $term->slug ] = sprintf( __( '%1$s: %2$s', 'imajiner-editor' ), $taxonomy->labels->singular_name, $term->name );
			}
		}
	}

	$locations['author'] = __( 'Author archive', 'imajiner' );
	$locations['date']   = __( 'Date archive', 'imajiner' );
	$locations['search'] = __( 'Search results', 'imajiner' );
	$locations['404']    = __( 'Page not found (404)', 'imajiner' );

	/**
	 * Filters the template locations.
	 *
	 * @param array $locations Location => label.
	 */
	return apply_filters( 'imajiner_template_locations', $locations );
}

/**
 * Imajiner templates (imajiner/*.php, not parts), child theme overriding parent files with the same name.
 *
 * @return array Key (file name without .php) => key, name, file, post_types, locations.
 */
function imajiner_get_templates() {
	$templates = array();
	foreach ( array_unique( array( get_template_directory(), get_stylesheet_directory() ) ) as $root ) {
		foreach ( (array) glob( $root . '/' . IMAJINER_TEMPLATE_DIR . '/*.php' ) as $file ) {
			$key = basename( $file, '.php' );
			if ( ! preg_match( '/^[a-z0-9_-]+$/', $key ) ) {
				continue;
			}

			$headers = get_file_data(
				$file,
				array(
					'name'       => 'Template Name',
					'post_types' => 'Template Post Type',
					'locations'  => 'Imajiner Location',
				)
			);

			$templates[ $key ] = array(
				'key'        => $key,
				'name'       => $headers['name'] ? $headers['name'] : $key,
				'file'       => $file,
				'post_types' => array_filter( array_map( 'trim', explode( ',', $headers['post_types'] ) ) ),
				'locations'  => array_filter( array_map( 'trim', explode( ',', $headers['locations'] ) ) ),
				// A page template only shows in the Template dropdown; it has a name but no location.
				'is_page'    => '' !== $headers['name'] && '' === $headers['locations'],
			);
		}
	}

	ksort( $templates );
	return $templates;
}

/**
 * Template assigned to each location. If two templates claim one location, the first in file order wins.
 *
 * @return array Location => template key.
 */
function imajiner_location_assignments() {
	$assignments = array();
	foreach ( imajiner_get_templates() as $template ) {
		foreach ( $template['locations'] as $location ) {
			if ( ! isset( $assignments[ $location ] ) ) {
				$assignments[ $location ] = $template['key'];
			}
		}
	}
	return $assignments;
}

/**
 * Locations that match the current request, most specific first.
 *
 * @return string[]
 */
function imajiner_request_locations() {
	$prefix = is_front_page() ? array( 'front' ) : array();
	if ( is_home() ) {
		return array_merge( $prefix, array( 'home', 'archive:post', 'archive' ) );
	}
	if ( is_404() ) {
		return array( '404' );
	}
	if ( is_singular() ) {
		return array_merge( $prefix, array( 'single:' . get_post_type( get_queried_object_id() ), 'single' ) );
	}
	if ( is_search() ) {
		return array( 'search', 'archive' );
	}
	if ( is_post_type_archive() ) {
		$type = get_query_var( 'post_type' );
		$type = is_array( $type ) ? reset( $type ) : $type;
		return array( 'archive:' . $type, 'archive' );
	}
	if ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		return array( 'taxonomy:' . $term->taxonomy . ':' . $term->slug, 'taxonomy:' . $term->taxonomy, 'archive' );
	}
	if ( is_author() ) {
		return array( 'author', 'archive' );
	}
	if ( is_date() ) {
		return array( 'date', 'archive' );
	}
	if ( is_archive() ) {
		return array( 'archive' );
	}
	return array();
}

/**
 * Uses the template assigned to the request's location, unless a single post
 * or page has its own template chosen in the editor.
 *
 * @param string $template Template WordPress picked.
 * @return string
 */
function imajiner_template_include( $template ) {
	if ( is_singular() && get_page_template_slug( get_queried_object_id() ) ) {
		return $template;
	}

	$assignments = imajiner_location_assignments();
	$templates   = imajiner_get_templates();
	foreach ( imajiner_request_locations() as $location ) {
		if ( isset( $assignments[ $location ] ) ) {
			return $templates[ $assignments[ $location ] ]['file'];
		}
	}

	return $template;
}
add_filter( 'template_include', 'imajiner_template_include', 20 );

/**
 * Records which Imajiner template renders the request, for the body class and its CSS.
 *
 * Runs after locations are resolved and before the editor preview swaps in its copy.
 *
 * @param string $template Template file.
 * @return string
 */
function imajiner_detect_current_template( $template ) {
	$GLOBALS['imajiner_current_template'] = '';

	$file = wp_normalize_path( $template );
	foreach ( imajiner_get_templates() as $candidate ) {
		if ( wp_normalize_path( $candidate['file'] ) === $file ) {
			$GLOBALS['imajiner_current_template'] = $candidate['key'];
		}
	}

	return $template;
}
add_filter( 'template_include', 'imajiner_detect_current_template', 50 );
