<?php
/**
 * Editor preview: renders a page with its template instrumented for selection.
 *
 * The editor loads the page in an iframe with ?imajiner_preview=<nonce>. For
 * that request the page template is swapped for a copy where every element
 * carries data-imj-id, and preview.js reports clicks back to the editor.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Preview rendering.
 */
class Imajiner_Preview {

	const QUERY_VAR = 'imajiner_preview';

	/**
	 * Hooks the preview into the front end.
	 */
	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'show_admin_bar' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Preview URL for a post, to load inside the editor.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function url( WP_Post $post ) {
		$url = 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post );
		return add_query_arg( self::QUERY_VAR, wp_create_nonce( self::QUERY_VAR . '_' . $post->ID ), $url );
	}

	/**
	 * Whether the current front-end request is a valid editor preview.
	 *
	 * @return bool
	 */
	public static function is_preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
		if ( empty( $_GET[ self::QUERY_VAR ] ) || ! is_singular() ) {
			return false;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! Imajiner_Editor::user_can_edit( $post ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		return (bool) wp_verify_nonce( $nonce, self::QUERY_VAR . '_' . $post->ID );
	}

	/**
	 * Swaps the page template for its instrumented copy.
	 *
	 * @param string $template Template path WordPress picked.
	 * @return string
	 */
	public static function template_include( $template ) {
		if ( ! self::is_preview() ) {
			return $template;
		}

		$path = Imajiner_Editor::get_template_path( get_queried_object() );
		if ( ! $path ) {
			return $template;
		}

		$instrumented = self::build( $path );
		return $instrumented ? $instrumented : $template;
	}

	/**
	 * Hides the admin bar inside the editor preview.
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public static function show_admin_bar( $show ) {
		return self::is_preview() ? false : $show;
	}

	/**
	 * Loads the selection script and styles inside the preview.
	 */
	public static function enqueue_assets() {
		if ( ! self::is_preview() ) {
			return;
		}

		wp_enqueue_style( 'imajiner-preview', IMAJINER_EDITOR_URL . 'assets/css/preview.css', array(), IMAJINER_EDITOR_VERSION );
		wp_enqueue_script( 'imajiner-preview', IMAJINER_EDITOR_URL . 'assets/js/preview.js', array(), IMAJINER_EDITOR_VERSION, true );
		wp_localize_script( 'imajiner-preview', 'imajinerPreview', array( 'editorOrigin' => Imajiner_Editor::origin( admin_url() ) ) );
	}

	/**
	 * Writes the instrumented copy of a template, reusing it while the template is unchanged.
	 *
	 * @param string $path Template path.
	 * @return string|false Instrumented file path, or false if it couldn't be written.
	 */
	private static function build( $path ) {
		$dir = self::cache_dir();
		if ( ! $dir ) {
			return false;
		}

		$source = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$slug   = basename( $path, '.php' );
		$file   = $dir . $slug . '-' . md5( $source . IMAJINER_EDITOR_VERSION ) . '.php';

		if ( file_exists( $file ) ) {
			return $file;
		}

		foreach ( (array) glob( $dir . $slug . '-*.php' ) as $stale ) {
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
