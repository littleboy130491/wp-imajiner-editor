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
					'changes' => array( 'type' => 'array', 'required' => true, 'minItems' => 1, 'maxItems' => 500 ),
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
			if ( ! in_array( $change['type'], array( 'structure', 'batch' ), true ) ) {
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
				$result = $css->set( $scope . ' .' . $change['class'], $change['property'], $value, $breakpoints[ $device ]['media'], $later );
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
