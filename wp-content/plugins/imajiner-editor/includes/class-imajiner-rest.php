<?php
/**
 * REST API used by the editor screen.
 *
 * POST /imajiner/v1/templates/<key>/save                       Apply changes to the page's template and its stylesheet.
 * GET  /imajiner/v1/templates/<key>/revisions                  List saved versions.
 * POST /imajiner/v1/templates/<key>/revisions/<id>/restore     Restore a saved version.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes.
 */
class Imajiner_Rest {

	const NAMESPACE_V1 = 'imajiner/v1';

	/**
	 * Hooks route registration.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public static function register_routes() {
		Imajiner_Generation::register_routes();
		// Key: a template file name ("page-home") or a part ("parts/site-footer").
		$base = '/templates/(?P<key>(?:parts/)?[a-z0-9_-]+)';
		$hash = array(
			'type'     => 'string',
			'required' => true,
			'pattern'  => '^[a-f0-9]{32}$',
		);
		register_rest_route( self::NAMESPACE_V1, $base . '/state', array(
			'methods' => WP_REST_Server::READABLE,
			'permission_callback' => array( __CLASS__, 'can_edit' ),
			'callback' => array( __CLASS__, 'state' ),
		) );
		register_rest_route( self::NAMESPACE_V1, '/editor/breakpoints', array(
			'methods' => WP_REST_Server::CREATABLE,
			'permission_callback' => array( __CLASS__, 'can_edit' ),
			'callback' => array( __CLASS__, 'set_breakpoints' ),
		) );
		register_rest_route( self::NAMESPACE_V1, $base . '/lock', array(
			'methods' => WP_REST_Server::CREATABLE,
			'permission_callback' => array( __CLASS__, 'can_edit' ),
			'callback' => array( __CLASS__, 'lock' ),
			'args' => array( 'action' => array( 'type' => 'string', 'enum' => array( 'acquire', 'release' ), 'default' => 'acquire' ) ),
		) );
		register_rest_route( self::NAMESPACE_V1, $base . '/revisions/(?P<revision>\d+)/diff', array(
			'methods' => WP_REST_Server::READABLE,
			'permission_callback' => array( __CLASS__, 'can_edit' ),
			'callback' => array( __CLASS__, 'revision_diff' ),
		) );

		register_rest_route(
			self::NAMESPACE_V1,
			$base . '/save',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array(
					'hash'    => $hash,
					'changes' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => 500,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			$base . '/stage',
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array( __CLASS__, 'stage' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args' => array(
					'hash' => $hash,
					'changes' => array( 'type' => 'array', 'required' => true, 'maxItems' => 500 ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			$base . '/revisions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'revisions' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			$base . '/revisions/(?P<revision>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'restore' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array( 'hash' => $hash ),
			)
		);
	}

	/**
	 * Permission check shared by all routes: templates are site-wide files.
	 *
	 * @return bool
	 */
	public static function can_edit() {
		return Imajiner_Editor::user_can_edit_templates();
	}

	/**
	 * Applies the editor's changes to the template and its stylesheet.
	 *
	 * Changes with type "text" or "attr" edit the template HTML; type "style"
	 * edits a declaration in the stylesheet: { class, property, value, device },
	 * where device names a breakpoint (default: the base styles).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save( WP_REST_Request $request ) {
		return self::edit( $request, true );
	}

	/**
	 * Compiles unsaved operations for the live preview.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function stage( WP_REST_Request $request ) {
		return self::edit( $request, false );
	}

	/** Applies an ordered list of edits, optionally writing the result. */
	private static function edit( WP_REST_Request $request, $save ) {
		$template = self::get_template( $request );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		$path = $template['file'];
		$lock = Imajiner_Editor_Locks::acquire( $template['key'] );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$files = Imajiner_Template_Store::read( $path );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		if ( $request['hash'] !== Imajiner_Template_Store::hash( $files ) ) {
			return new WP_Error( 'imajiner_conflict', __( 'The template changed. Reload before editing.', 'imajiner-editor' ), array( 'status' => 409 ) );
		}
		$new_files = $files;
		$count = 0;
		$pending = array();
		foreach ( array_merge( $request['changes'], array( array( 'type' => 'batch', 'changes' => array() ) ) ) as $change ) {
			if ( ! is_array( $change ) || ! isset( $change['type'] ) ) {
				return new WP_Error( 'imajiner_invalid_change', __( 'Invalid change.', 'imajiner-editor' ), array( 'status' => 400 ) );
			}
			if ( ! in_array( $change['type'], array( 'structure', 'batch', 'source', 'proposal', 'library' ), true ) ) {
				$pending[] = $change;
				continue;
			}
			if ( $pending ) {
				$new_files = self::apply_edits( $template, $new_files, $pending );
				$count += count( $pending );
				$pending = array();
				if ( is_wp_error( $new_files ) ) {
					return self::with_status( $new_files, 400 );
				}
			}
			if ( 'batch' === $change['type'] ) {
				if ( ! isset( $change['changes'] ) || ! is_array( $change['changes'] ) || count( $change['changes'] ) > 500 ) {
					return new WP_Error( 'imajiner_invalid_change', __( 'Invalid change batch.', 'imajiner-editor' ), array( 'status' => 400 ) );
				}
				$new_files = self::apply_edits( $template, $new_files, $change['changes'] );
				$count += count( $change['changes'] );
			} elseif ( 'source' === $change['type'] ) {
				$scanner = new Imajiner_Template_Scanner( $new_files['php'], array( 'require_sections' => 'part' !== $template['type'] ) );
				$source = $scanner->change_source( isset( $change['id'] ) ? $change['id'] : '', isset( $change['source'] ) ? $change['source'] : '', isset( $change['field'] ) ? $change['field'] : '' );
				if ( is_wp_error( $source ) ) {
					return self::with_status( $source, 400 );
				}
				$new_files['php'] = $source;
				++$count;
			} elseif ( in_array( $change['type'], array( 'proposal', 'library' ), true ) ) {
				$scanner = new Imajiner_Template_Scanner( $new_files['php'], array( 'require_sections' => 'part' !== $template['type'] ) );
				$scope = Imajiner_Editor::css_scope( $template );
				if ( 'library' === $change['type'] ) {
					$entry = Imajiner_Section_Library::get( isset( $change['name'] ) ? $change['name'] : '', $scope );
					if ( is_wp_error( $entry ) ) {
						return self::with_status( $entry, 400 );
					}
					$base_source = $scanner->apply_structure( array( 'type' => 'insert', 'target' => 'root', 'position' => 'inside', 'starter' => 'section' ) );
					if ( is_wp_error( $base_source ) ) {
						return self::with_status( $base_source, 400 );
					}
					$inserted = new Imajiner_Template_Scanner( $base_source, array( 'require_sections' => 'part' !== $template['type'] ) );
					$tree = $inserted->get_structure()['tree'];
					$id = '';
					foreach ( $tree as $node ) {
						if ( 'section' === $node->type ) {
							$id = $node->id;
						}
					}
					$source = $inserted->replace_node( $id, $entry['php'] );
				} else {
					$entry = array( 'php' => isset( $change['php'] ) ? $change['php'] : null, 'css' => isset( $change['css'] ) ? $change['css'] : '' );
					$source = $scanner->replace_node( isset( $change['id'] ) ? $change['id'] : '', $entry['php'] );
				}
				if ( is_wp_error( $source ) ) {
					return self::with_status( $source, 400 );
				}
				if ( ! is_string( $entry['css'] ) || is_wp_error( Imajiner_Css_Editor::validate_scope( $entry['css'], $scope ) ) ) {
					return new WP_Error( 'imajiner_css_scope', __( 'Proposal styles must stay scoped to this template.', 'imajiner-editor' ), array( 'status' => 400 ) );
				}
				$new_files['php'] = $source;
				$new_files['css'] .= "\n" . $entry['css'];
				++$count;
			} else {
				if ( ! isset( $change['operation'] ) || ! is_array( $change['operation'] ) ) {
					return new WP_Error( 'imajiner_structure', __( 'Invalid structural operation.', 'imajiner-editor' ), array( 'status' => 400 ) );
				}
				$scanner = new Imajiner_Template_Scanner( $new_files['php'], array( 'require_sections' => 'part' !== $template['type'] ) );
				$source = $scanner->apply_structure( $change['operation'] );
				if ( is_wp_error( $source ) ) {
					return self::with_status( $source, 400 );
				}
				$new_files['php'] = $source;
				++$count;
			}
			if ( is_wp_error( $new_files ) ) {
				return self::with_status( $new_files, 400 );
			}
			if ( $count > 500 || strlen( $new_files['php'] ) > 1048576 || strlen( $new_files['css'] ) > 1048576 ) {
				return new WP_Error( 'imajiner_edit_limit', __( 'Too many changes. Save before continuing.', 'imajiner-editor' ), array( 'status' => 400 ) );
			}
		}
		try {
			token_get_all( $new_files['php'], TOKEN_PARSE );
		} catch ( ParseError $error ) {
			return new WP_Error( 'imajiner_syntax', $error->getMessage(), array( 'status' => 400 ) );
		}
		if ( ! $save ) {
			$id = wp_generate_uuid4();
			set_transient( 'imajiner_stage_' . get_current_user_id() . '_' . $id, array( 'key' => $template['key'], 'stylesheet' => get_stylesheet(), 'hash' => $request['hash'], 'files' => $new_files ), HOUR_IN_SECONDS );
			$result = Imajiner_Editor::template_payload( $template, $new_files );
			$result['hash'] = $request['hash'];
			$result['stage'] = $id;
			return rest_ensure_response( $result );
		}
		$result = Imajiner_Template_Store::write( $path, $request['hash'], $new_files, sprintf( _n( 'Before saving %d change', 'Before saving %d changes', $count, 'imajiner-editor' ), $count ) );
		return is_wp_error( $result ) ? self::with_status( $result, 400 ) : rest_ensure_response( Imajiner_Editor::template_payload( $template, $new_files ) );
	}

	/** Applies one batch whose node ids share the same scanner structure. */
	private static function apply_edits( array $template, array $files, array $changes ) {

		$html_changes  = array();
		$style_changes = array();
		foreach ( $changes as $change ) {
			if ( is_array( $change ) && isset( $change['type'] ) && 'style' === $change['type'] ) {
				$style_changes[] = $change;
			} else {
				$html_changes[] = $change;
			}
		}

		$new_files = $files;

		if ( $html_changes ) {
			$scanner          = new Imajiner_Template_Scanner( $files['php'], array( 'require_sections' => 'part' !== $template['type'] ) );
			$new_files['php'] = $scanner->apply_changes( $html_changes );
			if ( is_wp_error( $new_files['php'] ) ) {
				return self::with_status( $new_files['php'], 400 );
			}

			// The edit only touches HTML. Refuse to write if any PHP block came out different.
			$check = new Imajiner_Template_Scanner( $new_files['php'] );
			if ( $check->get_php_sources() !== $scanner->get_php_sources() ) {
				return new WP_Error( 'imajiner_php_changed', __( 'Saving would change PHP code in the template, so nothing was saved.', 'imajiner-editor' ), array( 'status' => 500 ) );
			}
		}

		if ( $style_changes ) {
			$css         = new Imajiner_Css_Editor( $files['css'] );
			$scope       = Imajiner_Editor::css_scope( $template );
			$breakpoints = Imajiner_Editor::breakpoints();
			$names       = array_keys( $breakpoints );

			foreach ( $style_changes as $change ) {
				$valid = Imajiner_Css_Editor::validate_change( $change );
				if ( is_wp_error( $valid ) ) {
					return self::with_status( $valid, 400 );
				}

				$device = isset( $change['device'] ) ? $change['device'] : $names[0];
				if ( ! is_string( $device ) || ! isset( $breakpoints[ $device ] ) ) {
					return new WP_Error( 'imajiner_invalid_style', __( 'Unknown device for a style change.', 'imajiner-editor' ), array( 'status' => 400 ) );
				}
				// Blocks for narrower breakpoints must stay after this one in the file.
				$later = wp_list_pluck( array_slice( $breakpoints, array_search( $device, $names, true ) + 1 ), 'media' );

				$value  = isset( $change['value'] ) && '' !== trim( $change['value'] ) ? trim( $change['value'] ) : null;
				$state = isset( $change['state'] ) ? $change['state'] : '';
				if ( ! in_array( $state, array( '', ':hover', ':focus-visible' ), true ) ) {
					return new WP_Error( 'imajiner_style_state', __( 'Unknown style state.', 'imajiner-editor' ), array( 'status' => 400 ) );
				}
				$selector = $scope . ' .' . $change['class'] . $state;
				$media = $breakpoints[ $device ]['media'];
				if ( isset( $change['selector'] ) ) {
					$selector = $change['selector'];
					$media = isset( $change['media'] ) ? $change['media'] : '';
					if ( ! is_string( $selector ) || ! is_string( $media ) || ! $css->has_rule( $selector, $media ) || is_wp_error( Imajiner_Css_Editor::validate_scope( $selector . ' {}', $scope ) ) ) {
						return new WP_Error( 'imajiner_style_context', __( 'Select an existing scoped style rule.', 'imajiner-editor' ), array( 'status' => 400 ) );
					}
				}
				$result = $css->set( $selector, $change['property'], $value, $media, $later );
				if ( is_wp_error( $result ) ) {
					return self::with_status( $result, 400 );
				}
			}
			$new_files['css'] = $css->get_css();
		}

		return $new_files;
	}

	/**
	 * Lists saved versions of the template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function revisions( WP_REST_Request $request ) {
		$template = self::get_template( $request );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		$path = $template['file'];
		return rest_ensure_response( Imajiner_Template_Store::get_revisions( $path ) );
	}

	/**
	 * Replaces the template with a saved version.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function restore( WP_REST_Request $request ) {
		$template = self::get_template( $request );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		$path = $template['file'];
		$lock = Imajiner_Editor_Locks::acquire( $template['key'] );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$files = Imajiner_Template_Store::get_revision_files( $path, (int) $request['revision'] );
		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$result = Imajiner_Template_Store::write( $path, $request['hash'], $files, __( 'Before restoring an earlier version', 'imajiner-editor' ) );
		if ( is_wp_error( $result ) ) {
			return self::with_status( $result, 400 );
		}

		return rest_ensure_response( Imajiner_Editor::template_payload( $template, $files ) );
	}

	public static function lock( WP_REST_Request $request ) {
		$template = self::get_template( $request );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		return rest_ensure_response( 'release' === $request['action'] ? Imajiner_Editor_Locks::release( $template['key'] ) : Imajiner_Editor_Locks::acquire( $template['key'] ) );
	}

	public static function state( WP_REST_Request $request ) {
		$template = self::get_template( $request );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		$files = Imajiner_Template_Store::read( $template['file'] );
		return is_wp_error( $files ) ? $files : rest_ensure_response( Imajiner_Editor::template_payload( $template, $files ) );
	}

	public static function set_breakpoints( WP_REST_Request $request ) {
		$valid = Imajiner_Editor::validate_breakpoints( $request['breakpoints'] );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		update_option( 'imajiner_editor_breakpoints', $valid, false );
		return rest_ensure_response( Imajiner_Editor::breakpoints() );
	}

	public static function revision_diff( WP_REST_Request $request ) {
		$template = self::get_template( $request );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		$current = Imajiner_Template_Store::read( $template['file'] );
		$previous = Imajiner_Template_Store::get_revision_files( $template['file'], (int) $request['revision'] );
		if ( is_wp_error( $current ) || is_wp_error( $previous ) ) {
			return is_wp_error( $current ) ? $current : $previous;
		}
		require_once ABSPATH . 'wp-admin/includes/revision.php';
		return rest_ensure_response( array(
			'php' => wp_text_diff( $current['php'], $previous['php'], array( 'title_left' => __( 'Current', 'imajiner-editor' ), 'title_right' => __( 'Revision', 'imajiner-editor' ) ) ),
			'css' => wp_text_diff( $current['css'], $previous['css'] ),
			'hash' => Imajiner_Template_Store::hash( $current ),
		) );
	}

	/**
	 * Looks up the request's template or part.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error Template from Imajiner_Editor::get_template().
	 */
	private static function get_template( WP_REST_Request $request ) {
		$template = Imajiner_Editor::get_template( $request['key'] );
		if ( ! $template ) {
			return new WP_Error( 'imajiner_no_template', __( 'Template not found.', 'imajiner-editor' ), array( 'status' => 404 ) );
		}
		return $template;
	}

	/**
	 * Gives an error an HTTP status unless it already has one.
	 *
	 * @param WP_Error $error  Error.
	 * @param int      $status HTTP status.
	 * @return WP_Error
	 */
	private static function with_status( WP_Error $error, $status ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
			$error->add_data( array( 'status' => $status ) );
		}
		return $error;
	}
}
