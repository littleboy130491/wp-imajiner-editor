<?php
/**
 * Template parts: reusable pieces such as the header, footer or a "before footer" banner.
 *
 * A part is a file in imajiner/parts/<slug>.php (child theme first, then the
 * parent) with a header:
 *
 *     Part Name: Site footer
 *     Part Location: footer
 *     Part Description: Site-wide footer with menu and copyright.
 *
 * The location places it site-wide: "header" and "footer" replace the theme's
 * default header and footer, the others are Theme Hook Alliance hooks. A part
 * without a location is only shown where a template calls imajiner_part( 'slug' ).
 *
 * Each part renders inside <div class="imj-part imj-part-<slug>">, which has no
 * effect on layout and scopes its CSS (imajiner/parts/css/<slug>.css).
 *
 * @package Imajiner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Folder, relative to the theme, that holds template parts.
 */
define( 'IMAJINER_PARTS_DIR', IMAJINER_TEMPLATE_DIR . '/parts' );

/**
 * Where a part can be placed site-wide.
 *
 * @return array Location => label. "header" and "footer" replace the defaults; the rest are hooks.
 */
function imajiner_part_locations() {
	return apply_filters(
		'imajiner_part_locations',
		array(
			'header'             => __( 'Site header (replaces the default header)', 'imajiner' ),
			'tha_header_after'   => __( 'After the header', 'imajiner' ),
			'tha_content_before' => __( 'Before the page content', 'imajiner' ),
			'tha_content_after'  => __( 'After the page content', 'imajiner' ),
			'tha_footer_before'  => __( 'Before the footer', 'imajiner' ),
			'footer'             => __( 'Site footer (replaces the default footer)', 'imajiner' ),
		)
	);
}

/**
 * All template parts, child theme parts overriding parent ones with the same slug.
 *
 * @return array Slug => slug, name, location, description, file.
 */
function imajiner_get_parts() {
	$parts     = array();
	$locations = imajiner_part_locations();

	foreach ( array_unique( array( get_template_directory(), get_stylesheet_directory() ) ) as $root ) {
		foreach ( (array) glob( $root . '/' . IMAJINER_PARTS_DIR . '/*.php' ) as $file ) {
			$slug = basename( $file, '.php' );
			if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
				continue;
			}

			$headers = get_file_data(
				$file,
				array(
					'name'        => 'Part Name',
					'location'    => 'Part Location',
					'description' => 'Part Description',
					'post_types'  => 'Part Post Types',
					'include'     => 'Part Include',
					'exclude'     => 'Part Exclude',
				)
			);

			$parts[ $slug ] = array(
				'slug'        => $slug,
				'name'        => $headers['name'] ? $headers['name'] : $slug,
				'location'    => isset( $locations[ $headers['location'] ] ) ? $headers['location'] : '',
				'description' => $headers['description'],
				'file'        => $file,
				'post_types'  => imajiner_part_condition_values( $headers['post_types'] ),
				'include'     => imajiner_part_condition_values( $headers['include'] ),
				'exclude'     => imajiner_part_condition_values( $headers['exclude'] ),
			);
		}
	}

	ksort( $parts );
	return $parts;
}

/** Portable, comma-separated condition headers; no PHP expressions. */
function imajiner_part_condition_values( $value ) {
	return array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $value ) ) ) ) );
}

/** Exclusions win; empty includes and post types mean unrestricted. */
function imajiner_part_is_visible( array $part ) {
	$contexts = imajiner_request_locations();
	if ( array_intersect( $part['exclude'], $contexts ) ) {
		return false;
	}
	if ( $part['include'] && ! array_intersect( $part['include'], $contexts ) ) {
		return false;
	}
	if ( $part['post_types'] ) {
		$types = is_singular() ? array( get_post_type( get_queried_object_id() ) ) : (array) get_query_var( 'post_type' );
		if ( is_home() ) {
			$types = array( 'post' );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$taxonomy = get_taxonomy( get_queried_object()->taxonomy );
			$types = $taxonomy ? $taxonomy->object_type : array();
		}
		if ( ! array_intersect( $part['post_types'], $types ) ) {
			return false;
		}
	}
	return true;
}

/** A validated editor preview may show its target regardless of conditions. */
function imajiner_part_is_preview( $slug ) {
	if ( ! class_exists( 'Imajiner_Preview' ) ) {
		return false;
	}
	$preview = Imajiner_Preview::current();
	return $preview && 'part' === $preview['type'] && $slug === $preview['slug'];
}

/**
 * Renders a template part.
 *
 * @param string $slug Part slug.
 * @param array  $args Variables passed to the part as $args.
 * @return bool Whether the part exists.
 */
function imajiner_part( $slug, $args = array() ) {
	$parts = imajiner_get_parts();
	if ( ! isset( $parts[ $slug ] ) || ( ! imajiner_part_is_preview( $slug ) && ! imajiner_part_is_visible( $parts[ $slug ] ) ) ) {
		return false;
	}

	/**
	 * Filters the file a part renders from. The editor preview swaps in an instrumented copy.
	 *
	 * @param string $file Part file.
	 * @param string $slug Part slug.
	 */
	$file = apply_filters( 'imajiner_part_file', $parts[ $slug ]['file'], $slug );
	if ( ! is_file( $file ) ) {
		return false;
	}
	imajiner_enqueue_part_styles( $slug, $parts[ $slug ] );

	echo '<div class="imj-part imj-part-' . esc_attr( $slug ) . '">';
	load_template( $file, false, $args );
	echo '</div>';

	return true;
}

/**
 * Renders every part placed at a location.
 *
 * @param string $location Location.
 * @return bool Whether any part rendered.
 */
function imajiner_render_location( $location ) {
	$rendered = false;
	foreach ( imajiner_get_parts() as $part ) {
		if ( $part['location'] === $location ) {
			$rendered = imajiner_part( $part['slug'] ) || $rendered;
		}
	}
	return $rendered;
}

/**
 * Attaches parts to the hook locations.
 */
function imajiner_register_part_hooks() {
	foreach ( array_keys( imajiner_part_locations() ) as $location ) {
		if ( in_array( $location, array( 'header', 'footer' ), true ) ) {
			continue;
		}
		add_action(
			$location,
			function () use ( $location ) {
				imajiner_render_location( $location );
			}
		);
	}
}
add_action( 'after_setup_theme', 'imajiner_register_part_hooks', 20 );

/**
 * Stylesheet path of a part or template: css/<slug>.css next to its file.
 *
 * @param string $file Part or template file.
 * @return string
 */
function imajiner_css_file( $file ) {
	return dirname( $file ) . '/css/' . basename( $file, '.php' ) . '.css';
}

/**
 * URL of a file inside the parent or child theme.
 *
 * @param string $file Absolute path.
 * @return string
 */
function imajiner_file_uri( $file ) {
	$file = wp_normalize_path( $file );
	foreach ( array( get_stylesheet_directory() => get_stylesheet_directory_uri(), get_template_directory() => get_template_directory_uri() ) as $dir => $uri ) {
		$dir = wp_normalize_path( $dir );
		if ( 0 === strpos( $file, $dir . '/' ) ) {
			return $uri . substr( $file, strlen( $dir ) );
		}
	}
	return '';
}

/**
 * Enqueues only a rendered part, using its original stylesheet during preview.
 * WordPress prints late styles explicitly if wp_head has already run, including
 * calls after wp_footer; the handle prevents duplicate output.
 */
function imajiner_enqueue_part_styles( $slug, array $part ) {
	if ( imajiner_part_is_preview( $slug ) && ( wp_style_is( 'imajiner-stage', 'enqueued' ) || wp_style_is( 'imajiner-stage', 'done' ) ) ) {
		return;
	}
	$css = imajiner_css_file( $part['file'] );
	if ( ! is_file( $css ) ) {
		return;
	}
	$handle = 'imajiner-part-' . $slug;
	wp_enqueue_style( $handle, imajiner_file_uri( $css ), array( 'imajiner-base' ), (string) filemtime( $css ) );
	if ( did_action( 'wp_head' ) ) {
		wp_print_styles( array( $handle ) );
	}
}
