<?php
/**
 * Editor preview: renders a page with the edited template or part instrumented for selection.
 *
 * The editor loads a front-end URL in an iframe with
 * ?imajiner_preview=<nonce>&imajiner_template=<key>. For that request the
 * template (or part) is swapped for a copy where every element carries
 * data-imj-id, and preview.js reports clicks back to the editor.
 *
 * Templates are previewed on a URL they would render: a page using a page
 * template, or a sample URL for a single/archive location. The edited template
 * is used even if it isn't assigned there yet. Parts are previewed inside the
 * site's header and footer.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Preview rendering.
 */
class Imajiner_Preview {

	const QUERY_VAR    = 'imajiner_preview';
	const TEMPLATE_VAR = 'imajiner_template';

	/**
	 * Hooks the preview into the front end.
	 */
	public static function init() {
		// Before the theme records the current template (50), so body class and CSS match the edited template.
		add_filter( 'template_include', array( __CLASS__, 'force_template' ), 40 );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_filter( 'imajiner_part_file', array( __CLASS__, 'part_file' ), 10, 2 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'show_admin_bar' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Preview URL for a template or part.
	 *
	 * @param array        $template Template from Imajiner_Editor::get_template().
	 * @param WP_Post|null $post     Post to preview with, if any.
	 * @return string|WP_Error
	 */
	public static function url( array $template, $post = null ) {
		$base = self::base_url( $template, $post );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		return add_query_arg(
			array(
				self::QUERY_VAR    => wp_create_nonce( self::QUERY_VAR . '_' . $template['key'] ),
				self::TEMPLATE_VAR => $template['key'],
			),
			$base
		);
	}

	/**
	 * Template or part being previewed in this request, if it is a valid preview.
	 *
	 * @return array|null Template from Imajiner_Editor::get_template().
	 */
	public static function current() {
		static $current = false;
		if ( false !== $current ) {
			return $current;
		}

		$current = null;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified below.
		if ( empty( $_GET[ self::QUERY_VAR ] ) || empty( $_GET[ self::TEMPLATE_VAR ] ) || ! Imajiner_Editor::user_can_edit_templates() ) {
			return $current;
		}
		$key   = sanitize_text_field( wp_unslash( $_GET[ self::TEMPLATE_VAR ] ) );
		$nonce = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		// phpcs:enable

		if ( wp_verify_nonce( $nonce, self::QUERY_VAR . '_' . $key ) ) {
			$current = Imajiner_Editor::get_template( $key );
		}
		return $current;
	}

	/**
	 * Renders the edited template on this URL, even before it is assigned here.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function force_template( $template ) {
		$current = self::current();
		return $current && 'part' !== $current['type'] ? $current['file'] : $template;
	}

	/**
	 * Swaps the template for its instrumented copy, or renders the part preview page.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function template_include( $template ) {
		$current = self::current();
		if ( ! $current ) {
			return $template;
		}

		if ( 'part' === $current['type'] ) {
			return IMAJINER_EDITOR_DIR . 'views/part-preview.php';
		}

		$instrumented = self::build( $current['file'] );
		return $instrumented ? $instrumented : $template;
	}

	/**
	 * Renders the previewed part from its instrumented copy.
	 *
	 * @param string $file Part file.
	 * @param string $slug Part slug.
	 * @return string
	 */
	public static function part_file( $file, $slug ) {
		$current = self::current();
		if ( ! $current || 'part' !== $current['type'] || $current['slug'] !== $slug ) {
			return $file;
		}

		$instrumented = self::build( $file );
		return $instrumented ? $instrumented : $file;
	}

	/**
	 * Hides the admin bar inside the editor preview.
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public static function show_admin_bar( $show ) {
		return self::current() ? false : $show;
	}

	/**
	 * Loads the selection script and styles inside the preview.
	 */
	public static function enqueue_assets() {
		if ( ! self::current() ) {
			return;
		}

		wp_enqueue_style( 'imajiner-preview', IMAJINER_EDITOR_URL . 'assets/css/preview.css', array(), IMAJINER_EDITOR_VERSION );
		wp_enqueue_script( 'imajiner-preview', IMAJINER_EDITOR_URL . 'assets/js/preview.js', array(), IMAJINER_EDITOR_VERSION, true );
		wp_localize_script( 'imajiner-preview', 'imajinerPreview', array( 'editorOrigin' => Imajiner_Editor::origin( admin_url() ) ) );
	}

	/**
	 * Front-end URL a template or part is previewed on.
	 *
	 * @param array        $template Template.
	 * @param WP_Post|null $post     Post to preview with, if any.
	 * @return string|WP_Error
	 */
	private static function base_url( array $template, $post ) {
		if ( $post ) {
			return 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post );
		}

		if ( 'part' === $template['type'] ) {
			return home_url( '/' );
		}

		if ( 'page' === $template['type'] ) {
			$pages = get_posts(
				array(
					'post_type'   => 'any',
					'post_status' => 'publish',
					'numberposts' => 1,
					'meta_key'    => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
					'meta_value'  => Imajiner_Editor::TEMPLATE_DIR . '/' . $template['key'] . '.php', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
				)
			);
			if ( $pages ) {
				return get_permalink( $pages[0] );
			}
			// Not used by a page yet: preview it on any page (force_template() applies it there).
			$url = self::location_url( 'single:page' );
			return $url ? $url : home_url( '/' );
		}

		// Single/archive template: the first assigned location with something to show, else a recent post.
		foreach ( array_merge( $template['locations'], array( 'single:post', 'single:page' ) ) as $location ) {
			$url = self::location_url( $location );
			if ( $url ) {
				return $url;
			}
		}

		return home_url( '/' );
	}

	/**
	 * A URL that renders a location, or '' when the site has nothing to show there.
	 *
	 * @param string $location Location, e.g. "single:product" or "taxonomy:category".
	 * @return string
	 */
	private static function location_url( $location ) {
		list( $kind, $name ) = array_pad( explode( ':', $location, 2 ), 2, '' );

		switch ( $kind ) {
			case 'single':
				$posts = get_posts(
					array(
						'post_type'   => $name ? $name : array( 'post', 'page' ),
						'post_status' => 'publish',
						'numberposts' => 1,
					)
				);
				return $posts ? get_permalink( $posts[0] ) : '';

			case 'archive':
				$url = get_post_type_archive_link( $name ? $name : 'post' );
				return $url ? $url : '';

			case 'taxonomy':
				$terms = get_terms(
					array(
						'taxonomy'   => $name,
						'hide_empty' => true,
						'number'     => 1,
					)
				);
				return $terms && ! is_wp_error( $terms ) ? get_term_link( $terms[0] ) : '';

			case 'author':
				return get_author_posts_url( get_current_user_id() );

			case 'date':
				return get_year_link( (int) wp_date( 'Y' ) );

			case 'search':
				return add_query_arg( 's', 'a', home_url( '/' ) );

			case '404':
				return home_url( '/imajiner-preview-' . wp_generate_password( 8, false, false ) . '/' );
		}

		return '';
	}

	/**
	 * Writes the instrumented copy of a template or part, reusing it while the file is unchanged.
	 *
	 * @param string $path Template or part path.
	 * @return string|false Instrumented file path, or false if it couldn't be written.
	 */
	private static function build( $path ) {
		$dir = self::cache_dir();
		if ( ! $dir ) {
			return false;
		}

		$source = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		// Prefix with the path so a part and a template with the same file name don't collide.
		$prefix = substr( md5( $path ), 0, 8 ) . '-' . basename( $path, '.php' );
		$file   = $dir . $prefix . '-' . md5( $source . IMAJINER_EDITOR_VERSION ) . '.php';

		if ( file_exists( $file ) ) {
			return $file;
		}

		foreach ( (array) glob( $dir . $prefix . '-*.php' ) as $stale ) {
			wp_delete_file( $stale );
		}

		$scanner = new Imajiner_Template_Scanner( $source );
		// The guard stops the copy doing anything if it's requested directly.
		$written = file_put_contents( $file, "<?php defined( 'ABSPATH' ) || exit; ?>" . $scanner->get_instrumented_source() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return false === $written ? false : $file;
	}

	/**
	 * Directory for instrumented copies, created on first use.
	 *
	 * @return string|false Path with trailing slash, or false if it can't be created.
	 */
	private static function cache_dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'imajiner/preview/';

		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
			file_put_contents( $dir . '.htaccess', "Require all denied\n" );
			// phpcs:enable
		}

		return $dir;
	}
}
