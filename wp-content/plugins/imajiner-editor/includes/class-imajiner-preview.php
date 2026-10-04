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
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 30 );
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
		nocache_headers();

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
		$current = self::current();
		if ( ! $current ) {
			return;
		}
		$files = self::stage( $current );
		if ( $files ) {
			wp_dequeue_style( ( 'part' === $current['type'] ? 'imajiner-part-' : 'imajiner-template-' ) . $current['slug'] );
			wp_register_style( 'imajiner-stage', false, array( 'imajiner-base' ) );
			wp_enqueue_style( 'imajiner-stage' );
			wp_add_inline_style( 'imajiner-stage', $files['css'] );
		}

		wp_enqueue_style( 'imajiner-preview', IMAJINER_EDITOR_URL . 'assets/css/preview.css', array(), IMAJINER_EDITOR_VERSION );
		wp_enqueue_script( 'imajiner-preview', IMAJINER_EDITOR_URL . 'assets/js/preview.js', array(), IMAJINER_EDITOR_VERSION, true );
		wp_localize_script( 'imajiner-preview', 'imajinerPreview', array( 'editorOrigin' => Imajiner_Editor::origin( admin_url() ) ) );
	}

	/** Returns a user-owned staged file pair while its original is current. */
	public static function staged_files( array $template, $id ) {
		$stage = is_string( $id ) && preg_match( '/^[a-f0-9-]{36}$/', $id ) ? get_transient( 'imajiner_stage_' . get_current_user_id() . '_' . $id ) : false;
		if ( ! $stage || $stage['key'] !== $template['key'] ) {
			return new WP_Error( 'imajiner_stage_expired', __( 'This preview expired. Make another edit to refresh it.', 'imajiner-editor' ), array( 'status' => 410 ) );
		}
		$files = Imajiner_Template_Store::read( $template['file'] );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		if ( $stage['stylesheet'] !== get_stylesheet() || $stage['hash'] !== Imajiner_Template_Store::hash( $files ) ) {
			return new WP_Error( 'imajiner_stage_conflict', __( 'The theme or template changed. Reload the editor.', 'imajiner-editor' ), array( 'status' => 409 ) );
		}
		return $stage['files'];
	}

	private static function stage( array $template ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- current() verifies the preview nonce and capability.
		if ( ! isset( $_GET['imajiner_stage'] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$files = self::staged_files( $template, wp_unslash( $_GET['imajiner_stage'] ) );
		if ( is_wp_error( $files ) ) {
			$data = $files->get_error_data();
			wp_die( esc_html( $files->get_error_message() ), '', array( 'response' => is_array( $data ) && isset( $data['status'] ) ? $data['status'] : 500 ) );
		}
		return $files;
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
			case 'front':
				return home_url( '/' );

			case 'home':
				$page = (int) get_option( 'page_for_posts' );
				return $page ? get_permalink( $page ) : home_url( '/' );
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
				list( $taxonomy, $slug ) = array_pad( explode( ':', $name, 2 ), 2, '' );
				if ( $slug ) {
					$term = get_term_by( 'slug', $slug, $taxonomy );
					$url = $term ? get_term_link( $term ) : '';
					return is_wp_error( $url ) ? '' : $url;
				}
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
		if ( is_wp_error( Imajiner_Filesystem::validate_path( $path, true ) ) ) {
			return false;
		}
		$dir = self::cache_dir();
		if ( ! $dir ) {
			return false;
		}

		$stage = self::stage( self::current() );
		$source = $stage ? $stage['php'] : Imajiner_Filesystem::read( $path );
		if ( is_wp_error( $source ) ) {
			return false;
		}
		// Prefix with the path so a part and a template with the same file name don't collide.
		$prefix = substr( md5( $path ), 0, 8 ) . '-' . basename( $path, '.php' );
		if ( $stage ) {
			$prefix .= '-stage-' . get_current_user_id();
		}
		$file   = $dir . $prefix . '-' . md5( $source . IMAJINER_EDITOR_VERSION ) . '.php';

		if ( is_wp_error( Imajiner_Filesystem::validate_path( $file ) ) ) {
			return false;
		}
		if ( Imajiner_Filesystem::exists( $file ) ) {
			if ( $stage ) {
				register_shutdown_function( array( 'Imajiner_Filesystem', 'delete' ), $file );
			}
			return $file;
		}

		foreach ( (array) glob( $dir . $prefix . '-*.php' ) as $stale ) {
			if ( ! $stage && false === strpos( basename( $stale ), '-stage-' ) ) {
				Imajiner_Filesystem::delete( $stale );
			} elseif ( filemtime( $stale ) < time() - HOUR_IN_SECONDS ) {
				Imajiner_Filesystem::delete( $stale );
			}
		}

		$scanner = new Imajiner_Template_Scanner( $source );
		// The guard stops the copy doing anything if it's requested directly.
		$written = Imajiner_Filesystem::write( $file, "<?php defined( 'ABSPATH' ) || exit; ?>" . $scanner->get_instrumented_source() );
		if ( ! is_wp_error( $written ) && $stage ) {
			register_shutdown_function( array( 'Imajiner_Filesystem', 'delete' ), $file );
		}

		return is_wp_error( $written ) ? false : $file;
	}

	/**
	 * Directory for instrumented copies, created on first use.
	 *
	 * @return string|false Path with trailing slash, or false if it can't be created.
	 */
	private static function cache_dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'imajiner/preview/';

		if ( is_wp_error( Imajiner_Filesystem::validate_path( untrailingslashit( $dir ) ) ) ) {
			return false;
		}
		if ( ! Imajiner_Filesystem::exists( $dir ) ) {
			if ( is_wp_error( Imajiner_Filesystem::mkdir( $dir ) ) ) {
				return false;
			}
		}
		foreach ( array( 'index.php' => "<?php defined('ABSPATH') || exit;", '.htaccess' => "Require all denied\n" ) as $name => $source ) {
			if ( ! Imajiner_Filesystem::exists( $dir . $name ) && is_wp_error( Imajiner_Filesystem::write( $dir . $name, $source ) ) ) {
				return false;
			}
		}

		return $dir;
	}
}
