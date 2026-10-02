<?php
/**
 * Reads and writes template files safely, with version history.
 *
 * A template is two files edited together: the PHP template and its
 * stylesheet (imajiner/css/<slug>.css, which may not exist yet). Every write
 * is syntax-checked, the previous version of both is saved as a revision, and
 * each file is replaced atomically. Revisions are stored as a private post
 * type so old template code never sits in a web-accessible file.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Template file storage.
 */
class Imajiner_Template_Store {

	const REVISION_POST_TYPE = 'imajiner_revision';

	/**
	 * Revisions kept per template; older ones are deleted.
	 */
	const MAX_REVISIONS = 30;

	/**
	 * Registers the revision post type.
	 */
	public static function init() {
		register_post_type(
			self::REVISION_POST_TYPE,
			array(
				'label'           => __( 'Imajiner revisions', 'imajiner-editor' ),
				'public'          => false,
				'show_ui'         => false,
				'rewrite'         => false,
				'query_var'       => false,
				'can_export'      => false,
				'supports'        => array( 'title', 'editor', 'author' ),
				'capability_type' => 'post',
			)
		);
	}

	/**
	 * Path of a template's stylesheet: imajiner/page-home.php => imajiner/css/page-home.css.
	 *
	 * @param string $path Template path.
	 * @return string
	 */
	public static function css_path( $path ) {
		return dirname( $path ) . '/css/' . basename( $path, '.php' ) . '.css';
	}

	/**
	 * Hash the editor uses to detect that either file changed since it was loaded.
	 *
	 * @param array $files Template files: php and css source.
	 * @return string
	 */
	public static function hash( array $files ) {
		return md5( $files['php'] . "\0" . $files['css'] );
	}

	/**
	 * Reads a template and its stylesheet.
	 *
	 * @param string $path Template path.
	 * @return array|WP_Error Array with php and css source; css is '' when the stylesheet doesn't exist.
	 */
	public static function read( $path ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$php      = file_get_contents( $path );
		$css_path = self::css_path( $path );
		$css      = file_exists( $css_path ) ? file_get_contents( $css_path ) : '';
		// phpcs:enable

		if ( false === $php || false === $css ) {
			return new WP_Error( 'imajiner_unreadable', __( 'The template files could not be read.', 'imajiner-editor' ) );
		}

		return array(
			'php' => $php,
			'css' => $css,
		);
	}

	/**
	 * Replaces a template's files after checking them and saving the current version as a revision.
	 *
	 * @param string $path      Template path.
	 * @param string $base_hash Hash of the version the editor started from.
	 * @param array  $new_files New php and css source.
	 * @param string $note      Short description of the change, stored with the revision.
	 * @return true|WP_Error
	 */
	public static function write( $path, $base_hash, array $new_files, $note ) {
		$current = self::read( $path );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		if ( self::hash( $current ) !== $base_hash ) {
			return new WP_Error( 'imajiner_conflict', __( 'The template changed since the editor loaded it. Reload the editor to get the latest version.', 'imajiner-editor' ), array( 'status' => 409 ) );
		}

		$syntax = self::check_syntax( $new_files['php'] );
		if ( is_wp_error( $syntax ) ) {
			return $syntax;
		}

		if ( $new_files === $current ) {
			return true;
		}

		$targets = array();
		foreach ( array( 'php' => $path, 'css' => self::css_path( $path ) ) as $type => $file ) {
			if ( $new_files[ $type ] !== $current[ $type ] ) {
				$targets[ $file ] = $new_files[ $type ];
			}
		}

		foreach ( array_keys( $targets ) as $file ) {
			$dir = dirname( $file );
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'imajiner_not_writable', __( 'The template stylesheet folder could not be created.', 'imajiner-editor' ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( file_exists( $file ) ? ! is_writable( $file ) : ! is_writable( $dir ) ) {
				/* translators: %s: file name. */
				return new WP_Error( 'imajiner_not_writable', sprintf( __( '%s is not writable.', 'imajiner-editor' ), basename( $file ) ) );
			}
		}

		$revision = self::add_revision( $path, $current, $note );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}

		// The stylesheet goes first: if the template write then fails, the page still works.
		foreach ( array_reverse( $targets, true ) as $file => $source ) {
			$result = self::replace_file( $file, $source );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Revisions of a template, newest first.
	 *
	 * @param string $path Template path.
	 * @return array[] Each with id, date, author and note.
	 */
	public static function get_revisions( $path ) {
		$posts = get_posts(
			array(
				'post_type'      => self::REVISION_POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => self::MAX_REVISIONS,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_key'       => '_imajiner_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'     => self::template_key( $path ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
			)
		);

		return array_map(
			function ( $post ) {
				$author = get_userdata( $post->post_author );
				return array(
					'id'     => $post->ID,
					'date'   => get_post_time( 'c', true, $post ),
					'author' => $author ? $author->display_name : '',
					'note'   => $post->post_excerpt,
				);
			},
			$posts
		);
	}

	/**
	 * Files stored in a revision of the given template.
	 *
	 * @param string $path        Template path.
	 * @param int    $revision_id Revision post ID.
	 * @return array|WP_Error php and css source.
	 */
	public static function get_revision_files( $path, $revision_id ) {
		$post = get_post( $revision_id );
		if ( ! $post || self::REVISION_POST_TYPE !== $post->post_type || get_post_meta( $post->ID, '_imajiner_template', true ) !== self::template_key( $path ) ) {
			return new WP_Error( 'imajiner_revision_not_found', __( 'Revision not found.', 'imajiner-editor' ), array( 'status' => 404 ) );
		}

		return array(
			'php' => $post->post_content,
			'css' => (string) get_post_meta( $post->ID, '_imajiner_css', true ),
		);
	}

	/**
	 * Fails if the source has a PHP syntax error.
	 *
	 * @param string $source PHP source.
	 * @return true|WP_Error
	 */
	private static function check_syntax( $source ) {
		try {
			token_get_all( $source, TOKEN_PARSE );
		} catch ( ParseError $error ) {
			return new WP_Error(
				'imajiner_syntax_error',
				/* translators: 1: PHP error message, 2: line number. */
				sprintf( __( 'PHP syntax error: %1$s on line %2$d.', 'imajiner-editor' ), $error->getMessage(), $error->getLine() )
			);
		}
		return true;
	}

	/**
	 * Writes next to the target, then renames over it, so a failed write never leaves a half-written file.
	 *
	 * @param string $file   Target path.
	 * @param string $source New contents.
	 * @return true|WP_Error
	 */
	private static function replace_file( $file, $source ) {
		$temp  = $file . '.imj-' . wp_generate_password( 8, false ) . '.tmp';
		$error = new WP_Error(
			'imajiner_write_failed',
			/* translators: %s: file name. */
			sprintf( __( '%s could not be written.', 'imajiner-editor' ), basename( $file ) )
		);

		// phpcs:disable WordPress.WP.AlternativeFunctions
		if ( false === file_put_contents( $temp, $source ) ) {
			wp_delete_file( $temp );
			return $error;
		}
		if ( file_exists( $file ) ) {
			chmod( $temp, fileperms( $file ) & 0777 );
		}
		if ( ! rename( $temp, $file ) ) {
			wp_delete_file( $temp );
			return $error;
		}
		// phpcs:enable

		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $file, true );
		}
		return true;
	}

	/**
	 * Saves a version of a template as a revision and prunes old ones.
	 *
	 * @param string $path  Template path.
	 * @param array  $files php and css source to keep.
	 * @param string $note  Short description.
	 * @return int|WP_Error Revision ID.
	 */
	private static function add_revision( $path, array $files, $note ) {
		$key = self::template_key( $path );

		// Template code isn't post HTML: keep kses from rewriting it.
		kses_remove_filters();
		$revision_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => self::REVISION_POST_TYPE,
					'post_status'  => 'private',
					'post_title'   => $key,
					'post_content' => $files['php'],
					'post_excerpt' => $note,
					'post_author'  => get_current_user_id(),
					'meta_input'   => array(
						'_imajiner_template' => $key,
						'_imajiner_css'      => $files['css'],
					),
				)
			),
			true
		);
		kses_init();

		if ( is_wp_error( $revision_id ) ) {
			return $revision_id;
		}

		$old = get_posts(
			array(
				'post_type'      => self::REVISION_POST_TYPE,
				'post_status'    => 'private',
				// WP_Query ignores offset when posts_per_page is -1.
				'posts_per_page' => 100,
				'offset'         => self::MAX_REVISIONS,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_key'       => '_imajiner_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
			)
		);
		foreach ( $old as $old_id ) {
			wp_delete_post( $old_id, true );
		}

		return $revision_id;
	}

	/**
	 * Identifies a template across requests: theme folder + path inside it.
	 *
	 * @param string $path Template path.
	 * @return string e.g. "imajiner-child/imajiner/page-example.php".
	 */
	private static function template_key( $path ) {
		$root = wp_normalize_path( trailingslashit( get_theme_root() ) );
		return ltrim( str_replace( $root, '', wp_normalize_path( $path ) ), '/' );
	}
}
