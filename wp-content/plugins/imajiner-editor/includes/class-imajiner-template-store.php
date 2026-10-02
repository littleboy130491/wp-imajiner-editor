<?php
/**
 * Reads and writes template files safely, with version history.
 *
 * A template is two files edited together: the PHP template and its
 * stylesheet (imajiner/css/<slug>.css, which may not exist yet). Every write
 * is syntax-checked, the previous version of both is saved as a revision, and
 * files are staged and verified, with recovery on failure. Revisions use a private post
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
		$valid = self::validate_template( $path );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$php      = Imajiner_Filesystem::read( $path );
		if ( is_wp_error( $php ) ) {
			return $php;
		}
		$css_path = self::css_path( $path );
		$valid = Imajiner_Filesystem::check_path( $css_path );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$css = Imajiner_Filesystem::exists( $css_path ) ? Imajiner_Filesystem::read( $css_path ) : '';
		if ( is_wp_error( $css ) ) {
			return $css;
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
		$valid = self::validate_write( $path, $new_files );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
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

		$revision = self::add_revision( $path, $current, $note );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}

		return self::transaction( array_reverse( $targets, true ) );
	}

	/**
	 * Creates a new template (or part) and its stylesheet.
	 *
	 * @param string $path  Template path; must not exist yet.
	 * @param array  $files php and css source.
	 * @return true|WP_Error
	 */
	public static function create( $path, array $files ) {
		$valid = self::validate_write( $path, $files );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$ready = Imajiner_Filesystem::init();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( Imajiner_Filesystem::exists( $path ) || Imajiner_Filesystem::exists( self::css_path( $path ) ) ) {
			return new WP_Error( 'imajiner_exists', __( 'A template with that file name already exists.', 'imajiner-editor' ) );
		}

		$syntax = self::check_syntax( $files['php'] );
		if ( is_wp_error( $syntax ) ) {
			return $syntax;
		}

		return self::transaction( array( self::css_path( $path ) => $files['css'], $path => $files['php'] ) );
	}

	/**
	 * Revisions of a template, newest first.
	 *
	 * @param string $path Template path.
	 * @return array[] Each with id, date, author and note.
	 */
	public static function get_revisions( $path ) {
		if ( is_wp_error( Imajiner_Filesystem::can_write() ) || is_wp_error( Imajiner_Filesystem::validate_path( $path ) ) ) {
			return array();
		}
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
		$allowed = Imajiner_Filesystem::can_write();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$valid = Imajiner_Filesystem::validate_path( $path );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
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
				/* translators: %d: line number. */
				sprintf( __( 'PHP syntax error on line %d.', 'imajiner-editor' ), $error->getLine() )
			);
		}
		return true;
	}

	/**
	 * Stages and verifies a replacement through the configured filesystem.
	 *
	 * @param string $file   Target path.
	 * @param string $source New contents.
	 * @return true|WP_Error
	 */
	private static function replace_file( $file, $source ) {
		return Imajiner_Filesystem::write( $file, $source );
	}

	private static function validate_template( $path ) {
		$valid = Imajiner_Filesystem::validate_path( $path );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$relative = substr( wp_normalize_path( $path ), strlen( untrailingslashit( wp_normalize_path( get_stylesheet_directory() ) ) ) + 1 );
		return preg_match( '#^imajiner/(?:parts/)?[a-z0-9_-]+\.php$#D', $relative ) ? true : new WP_Error( 'imajiner_invalid_path', __( 'Use a template or part inside the active child theme.', 'imajiner-editor' ), array( 'status' => 400 ) );
	}

	private static function validate_write( $path, array $files ) {
		$allowed = Imajiner_Filesystem::can_write();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$valid = self::validate_template( $path );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! isset( $files['php'], $files['css'] ) || ! is_string( $files['php'] ) || ! is_string( $files['css'] ) ) {
			return new WP_Error( 'imajiner_invalid_files', __( 'Provide PHP and CSS source.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}
		return Imajiner_Filesystem::check_path( self::css_path( $path ) );
	}

	/** Null means delete. Snapshot all targets before changing any of them. */
	private static function transaction( array $targets, array $permissions = array() ) {
		$before = array();
		$modes = array();
		foreach ( $targets as $file => $source ) {
			$ready = Imajiner_Filesystem::init();
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			$valid = Imajiner_Filesystem::check_path( $file );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$before[ $file ] = Imajiner_Filesystem::exists( $file ) ? Imajiner_Filesystem::read( $file ) : null;
			if ( is_wp_error( $before[ $file ] ) ) {
				return $before[ $file ];
			}
			$modes[ $file ] = null === $before[ $file ] ? null : Imajiner_Filesystem::get_permissions( $file );
			if ( is_wp_error( $modes[ $file ] ) ) {
				return $modes[ $file ];
			}
		}
		$attempted = array();
		foreach ( $targets as $file => $source ) {
			$attempted[] = $file;
			$result = null === $source ? Imajiner_Filesystem::delete( $file ) : self::replace_file( $file, $source );
			if ( ! is_wp_error( $result ) && isset( $permissions[ $file ] ) ) {
				$result = Imajiner_Filesystem::set_permissions( $file, $permissions[ $file ] );
			}
			if ( is_wp_error( $result ) ) {
				$failed = false;
				foreach ( array_reverse( $attempted ) as $previous ) {
					if ( null === $before[ $previous ] ? ! Imajiner_Filesystem::exists( $previous ) : ( Imajiner_Filesystem::read( $previous ) === $before[ $previous ] && Imajiner_Filesystem::get_permissions( $previous ) === $modes[ $previous ] ) ) {
						continue;
					}
					$recovery = null === $before[ $previous ] ? Imajiner_Filesystem::delete( $previous ) : self::replace_file( $previous, $before[ $previous ] );
					if ( ! is_wp_error( $recovery ) && null !== $modes[ $previous ] ) {
						$recovery = Imajiner_Filesystem::set_permissions( $previous, $modes[ $previous ] );
					}
					$failed = $failed || is_wp_error( $recovery );
				}
				return $failed ? new WP_Error( 'imajiner_rollback_failed', __( 'The change and recovery failed. Reconnect and restore the saved revision.', 'imajiner-editor' ), array( 'status' => 500 ) ) : $result;
			}
		}
		return true;
	}

	public static function delete( $path, $base_hash ) {
		$valid = self::validate_write( $path, array( 'php' => '', 'css' => '' ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$current = self::read( $path );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( self::hash( $current ) !== $base_hash ) {
			return new WP_Error( 'imajiner_conflict', __( 'The template changed. Reload before deleting.', 'imajiner-editor' ), array( 'status' => 409 ) );
		}
		$revision = self::add_revision( $path, $current, __( 'Before deleting template', 'imajiner-editor' ) );
		return is_wp_error( $revision ) ? $revision : self::transaction( array( $path => null, self::css_path( $path ) => null ) );
	}

	public static function rename( $path, $new_path, $base_hash ) {
		$valid = self::validate_write( $new_path, array( 'php' => '', 'css' => '' ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$current = self::read( $path );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( self::hash( $current ) !== $base_hash ) {
			return new WP_Error( 'imajiner_conflict', __( 'The template changed. Reload before renaming.', 'imajiner-editor' ), array( 'status' => 409 ) );
		}
		$syntax = self::check_syntax( $current['php'] );
		if ( is_wp_error( $syntax ) ) {
			return $syntax;
		}
		if ( Imajiner_Filesystem::exists( $new_path ) || Imajiner_Filesystem::exists( self::css_path( $new_path ) ) ) {
			return new WP_Error( 'imajiner_exists', __( 'A template or stylesheet already uses that name.', 'imajiner-editor' ), array( 'status' => 409 ) );
		}
		$revision = self::add_revision( $path, $current, __( 'Before renaming template', 'imajiner-editor' ) );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		$permissions = array( $new_path => Imajiner_Filesystem::get_permissions( $path ) );
		if ( Imajiner_Filesystem::exists( self::css_path( $path ) ) ) {
			$permissions[ self::css_path( $new_path ) ] = Imajiner_Filesystem::get_permissions( self::css_path( $path ) );
		}
		foreach ( $permissions as $mode ) {
			if ( is_wp_error( $mode ) ) {
				return $mode;
			}
		}
		return self::transaction( array( self::css_path( $new_path ) => $current['css'], $new_path => $current['php'], $path => null, self::css_path( $path ) => null ), $permissions );
	}

	/** Hash is md5 of the CSS bytes; missing files use md5(''). */
	public static function write_css( $path, $base_hash, $css ) {
		$allowed = Imajiner_Filesystem::can_write();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$valid = Imajiner_Filesystem::validate_path( $path );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( '.css' !== substr( $path, -4 ) || ! is_string( $css ) ) {
			return new WP_Error( 'imajiner_invalid_path', __( 'Design tokens must be saved to a child theme CSS file.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}
		$ready = Imajiner_Filesystem::check_path( $path );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$current = Imajiner_Filesystem::exists( $path ) ? Imajiner_Filesystem::read( $path ) : '';
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( md5( $current ) !== $base_hash ) {
			return new WP_Error( 'imajiner_conflict', __( 'The stylesheet changed. Reload before saving.', 'imajiner-editor' ), array( 'status' => 409 ) );
		}
		if ( $current === $css ) {
			return true;
		}
		$revision = self::add_revision( $path, array( 'php' => '', 'css' => $current ), __( 'Before design token update', 'imajiner-editor' ) );
		return is_wp_error( $revision ) ? $revision : self::transaction( array( $path => $css ) );
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
