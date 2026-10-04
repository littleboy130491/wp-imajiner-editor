<?php
/**
 * Reads and writes template files safely, with version history.
 *
 * A template is two files edited together: the PHP template and its
 * stylesheet (imajiner/css/<slug>.css, which may not exist yet). Every write
 * is syntax-checked, the previous version of both is saved as a revision, and
 * direct writes use atomic rename; remote writes use verified staging and rollback.
 * Revisions are stored as a private post
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
				'capabilities'    => array_fill_keys( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ), 'edit_themes' ),
				'map_meta_cap'    => false,
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
		$valid = self::validate_template_path( $path );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$php      = Imajiner_Filesystem::read( $path );
		$css_path = self::css_path( $path );
		$valid = Imajiner_Filesystem::validate_path( $css_path );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$exists = Imajiner_Filesystem::exists_checked( $css_path );
		$css = is_wp_error( $exists ) ? $exists : ( $exists ? Imajiner_Filesystem::read( $css_path ) : '' );

		if ( is_wp_error( $php ) || is_wp_error( $css ) ) {
			return is_wp_error( $php ) ? $php : $css;
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
		return self::locked( $path, function () use ( $path, $base_hash, $new_files, $note ) {
			return self::write_locked( $path, $base_hash, $new_files, $note );
		} );
	}

	private static function write_locked( $path, $base_hash, array $new_files, $note ) {
		$valid = self::validate_files( $new_files );
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
		return self::locked( $path, function () use ( $path, $files ) {
			$valid = self::validate_files( $files );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			foreach ( array( $path, self::css_path( $path ) ) as $file ) {
				$exists = Imajiner_Filesystem::exists_checked( $file );
				if ( is_wp_error( $exists ) ) {
					return $exists;
				}
				if ( $exists ) {
					return new WP_Error( 'imajiner_exists', __( 'A template with that file name already exists.', 'imajiner-editor' ) );
				}
			}

			$syntax = self::check_syntax( $files['php'] );
			if ( is_wp_error( $syntax ) ) {
				return $syntax;
			}

			return self::transaction( array( self::css_path( $path ) => $files['css'], $path => $files['php'] ) );
		} );
	}

	/**
	 * Revisions of a template, newest first.
	 *
	 * @param string $path Template path.
	 * @return array[] Each with id, date, author and note.
	 */
	public static function get_revisions( $path ) {
		if ( is_wp_error( self::validate_template_path( $path ) ) ) {
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
		$valid = self::validate_template_path( $path );
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
				/* translators: 1: PHP error message, 2: line number. */
				sprintf( __( 'PHP syntax error: %1$s on line %2$d.', 'imajiner-editor' ), $error->getMessage(), $error->getLine() )
			);
		}
		return true;
	}

	/** Strict names: only page templates or parts inside the active child. */
	public static function validate_template_path( $path ) {
		$valid = Imajiner_Filesystem::validate_path( $path );
		$root = trailingslashit( wp_normalize_path( get_stylesheet_directory() ) ) . 'imajiner/';
		if ( is_wp_error( $valid ) || 0 !== strpos( $path, $root ) || ! preg_match( '~^(?:parts/)?[a-z0-9][a-z0-9_-]*\.php$~i', substr( $path, strlen( $root ) ) ) ) {
			return new WP_Error( 'imajiner_path', __( 'Choose a template inside the active child theme’s imajiner folder.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}
		return true;
	}

	private static function validate_files( array $files ) {
		if ( ! isset( $files['php'], $files['css'] ) || ! is_string( $files['php'] ) || ! is_string( $files['css'] ) || strlen( $files['php'] ) > 1048576 || strlen( $files['css'] ) > 1048576 ) {
			return new WP_Error( 'imajiner_files', __( 'Supply PHP and CSS source, each no larger than one megabyte.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/** Database ownership lock serializes plugin writers on shared/remote hosts. */
	private static function locked( $path, $callback, $template = true ) {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return new WP_Error( 'imajiner_file_edit_disabled', __( 'File editing is disabled by site configuration.', 'imajiner-editor' ), array( 'status' => 403 ) );
		}
		$valid = $template ? self::validate_template_path( $path ) : Imajiner_Filesystem::validate_path( $path );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$ready = Imajiner_Filesystem::init();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$key = '_imajiner_store_lock_' . md5( $path );
		$lock = array( 'token' => wp_generate_uuid4(), 'expires' => time() + 300, 'user' => get_current_user_id(), 'theme' => get_stylesheet() );
		$old = get_option( $key );
		if ( is_array( $old ) && isset( $old['expires'] ) && $old['expires'] < time() ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, maybe_serialize( $old ) ) );
			wp_cache_delete( $key, 'options' );
		}
		if ( ! add_option( $key, $lock, '', false ) ) {
			return new WP_Error( 'imajiner_locked', __( 'Another filesystem operation is in progress. Retry shortly.', 'imajiner-editor' ), array( 'status' => 423 ) );
		}
		try {
			return call_user_func( $callback );
		} finally {
			if ( get_option( $key ) === $lock ) {
				delete_option( $key );
			}
		}
	}

	/** Null means delete; remember every target before making any changes. */
	private static function transaction( array $targets ) {
		$before = array();
		$modes = array();
		foreach ( $targets as $file => $source ) {
			$valid = Imajiner_Filesystem::validate_path( $file );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$exists = Imajiner_Filesystem::exists_checked( $file );
			if ( is_wp_error( $exists ) ) {
				return $exists;
			}
			$before[ $file ] = $exists ? Imajiner_Filesystem::read( $file ) : null;
			if ( is_wp_error( $before[ $file ] ) ) {
				return $before[ $file ];
			}
			$modes[ $file ] = $exists ? Imajiner_Filesystem::permissions( $file ) : null;
			if ( is_wp_error( $modes[ $file ] ) ) {
				return $modes[ $file ];
			}
		}
		$touched = array();
		foreach ( $targets as $file => $source ) {
			$touched[] = $file;
			$result = null === $source ? ( null !== $before[ $file ] ? Imajiner_Filesystem::delete( $file ) : true ) : Imajiner_Filesystem::write( $file, $source );
			if ( ! is_wp_error( $result ) && null !== $source && Imajiner_Filesystem::read( $file ) !== $source ) {
				$result = Imajiner_Filesystem::error( 'imajiner_write_failed' );
			}
			if ( is_wp_error( $result ) ) {
				$failed = false;
				foreach ( array_reverse( $touched ) as $restore ) {
					if ( null === $before[ $restore ] ) {
						$exists = Imajiner_Filesystem::exists_checked( $restore );
						$rollback = is_wp_error( $exists ) ? $exists : ( $exists ? Imajiner_Filesystem::delete( $restore ) : true );
					} else {
						$rollback = Imajiner_Filesystem::read( $restore ) === $before[ $restore ] ? true : Imajiner_Filesystem::write( $restore, $before[ $restore ], $modes[ $restore ] );
					}
					$failed = $failed || is_wp_error( $rollback );
				}
				return $failed ? Imajiner_Filesystem::error( 'imajiner_rollback_failed' ) : $result;
			}
		}
		wp_clean_themes_cache( false );
		return true;
	}

	/** Delete a PHP/CSS pair only when the caller's hash is current. */
	public static function delete( $path, $base_hash ) {
		return self::locked( $path, function () use ( $path, $base_hash ) {
			$current = self::read( $path );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			if ( self::hash( $current ) !== $base_hash ) {
				return new WP_Error( 'imajiner_conflict', __( 'The template changed. Reload before deleting.', 'imajiner-editor' ), array( 'status' => 409 ) );
			}
			$revision = self::add_revision( $path, $current, __( 'Before deleting a template', 'imajiner-editor' ) );
			return is_wp_error( $revision ) ? $revision : self::transaction( array( $path => null, self::css_path( $path ) => null ) );
		} );
	}

	/** Move without silently rewriting PHP, CSS selectors or post assignments. */
	public static function rename( $path, $new_path, $base_hash ) {
		return self::locked( $path, function () use ( $path, $new_path, $base_hash ) {
			return self::locked( $new_path, function () use ( $path, $new_path, $base_hash ) {
				$current = self::read( $path );
				if ( is_wp_error( $current ) ) {
					return $current;
				}
				if ( self::hash( $current ) !== $base_hash ) {
					return new WP_Error( 'imajiner_conflict', __( 'The template changed. Reload before renaming.', 'imajiner-editor' ), array( 'status' => 409 ) );
				}
				foreach ( array( $new_path, self::css_path( $new_path ) ) as $file ) {
					$exists = Imajiner_Filesystem::exists_checked( $file );
					if ( is_wp_error( $exists ) ) {
						return $exists;
					}
					if ( $exists ) {
						return new WP_Error( 'imajiner_exists', __( 'A file with that name already exists.', 'imajiner-editor' ), array( 'status' => 409 ) );
					}
				}
				$revision = self::add_revision( $path, $current, __( 'Before renaming a template', 'imajiner-editor' ) );
				if ( is_wp_error( $revision ) ) {
					return $revision;
				}
				$css_exists = Imajiner_Filesystem::exists_checked( self::css_path( $path ) );
				if ( is_wp_error( $css_exists ) ) {
					return $css_exists;
				}
				$moves = $css_exists ? array( self::css_path( $path ) => self::css_path( $new_path ) ) : array();
				$moves[ $path ] = $new_path;
				$moved = array();
				$result = true;
				foreach ( $moves as $source => $target ) {
					$result = Imajiner_Filesystem::move( $source, $target );
					if ( is_wp_error( $result ) ) {
						foreach ( array_reverse( $moved, true ) as $restore => $destination ) {
							if ( is_wp_error( Imajiner_Filesystem::move( $destination, $restore ) ) ) {
								return Imajiner_Filesystem::error( 'imajiner_rollback_failed' );
							}
						}
						return $result;
					}
					$moved[ $source ] = $target;
				}
				if ( true === $result ) {
					foreach ( self::get_revisions( $path ) as $item ) {
						update_post_meta( $item['id'], '_imajiner_template', self::template_key( $new_path ) );
					}
					wp_clean_themes_cache( false );
				}
				return $result;
			} );
		} );
	}

	/** Fixed child token CSS target; stale SHA-256, no arbitrary paths or PHP. */
	public static function write_design_tokens( $css, $base_hash ) {
		$path = get_stylesheet_directory() . '/imajiner/design-tokens.css';
		if ( ! is_string( $css ) || strlen( $css ) > 1048576 || preg_match( '/<\?|<\/style/i', $css ) ) {
			return new WP_Error( 'imajiner_tokens', __( 'Invalid design token stylesheet.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}
		return self::locked( $path, function () use ( $path, $css, $base_hash ) {
			$exists = Imajiner_Filesystem::exists_checked( $path );
			$current = is_wp_error( $exists ) ? $exists : ( $exists ? Imajiner_Filesystem::read( $path ) : '' );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			if ( hash( 'sha256', $current ) !== $base_hash ) {
				return new WP_Error( 'imajiner_conflict', __( 'Design tokens changed. Reload before accepting.', 'imajiner-editor' ), array( 'status' => 409 ) );
			}
			return self::transaction( array( $path => $css ) );
		}, false );
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
		$root = trailingslashit( wp_normalize_path( get_stylesheet_directory() ) );
		return get_stylesheet() . '/' . substr( wp_normalize_path( $path ), strlen( $root ) );
	}
}
