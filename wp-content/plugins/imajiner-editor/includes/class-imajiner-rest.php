<?php
/**
 * REST API used by the editor screen.
 *
 * POST /imajiner/v1/templates/<post>/save                       Apply changes to the page's template and its stylesheet.
 * GET  /imajiner/v1/templates/<post>/revisions                  List saved versions.
 * POST /imajiner/v1/templates/<post>/revisions/<id>/restore     Restore a saved version.
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
		$base = '/templates/(?P<post>\d+)';
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
	 * Permission check shared by all routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_edit( WP_REST_Request $request ) {
		$post = get_post( (int) $request['post'] );
		return $post && Imajiner_Editor::user_can_edit( $post );
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
		$path = self::template_path( $request );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$files = Imajiner_Template_Store::read( $path );
		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$html_changes  = array();
		$style_changes = array();
		foreach ( $request['changes'] as $change ) {
			if ( is_array( $change ) && isset( $change['type'] ) && 'style' === $change['type'] ) {
				$style_changes[] = $change;
			} else {
				$html_changes[] = $change;
			}
		}

		$new_files = $files;

		if ( $html_changes ) {
			$scanner          = new Imajiner_Template_Scanner( $files['php'] );
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
			$scope       = Imajiner_Editor::css_scope( $path );
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

		$count  = count( $request['changes'] );
		$result = Imajiner_Template_Store::write(
			$path,
			$request['hash'],
			$new_files,
			/* translators: %d: number of changes. */
			sprintf( _n( 'Before saving %d change', 'Before saving %d changes', $count, 'imajiner-editor' ), $count )
		);
		if ( is_wp_error( $result ) ) {
			return self::with_status( $result, 400 );
		}

		return rest_ensure_response( Imajiner_Editor::template_payload( $path, $new_files ) );
	}

	/**
	 * Lists saved versions of the template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function revisions( WP_REST_Request $request ) {
		$path = self::template_path( $request );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		return rest_ensure_response( Imajiner_Template_Store::get_revisions( $path ) );
	}

	/**
	 * Replaces the template with a saved version.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function restore( WP_REST_Request $request ) {
		$path = self::template_path( $request );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$files = Imajiner_Template_Store::get_revision_files( $path, (int) $request['revision'] );
		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$result = Imajiner_Template_Store::write( $path, $request['hash'], $files, __( 'Before restoring an earlier version', 'imajiner-editor' ) );
		if ( is_wp_error( $result ) ) {
			return self::with_status( $result, 400 );
		}

		return rest_ensure_response( Imajiner_Editor::template_payload( $path, $files ) );
	}

	/**
	 * Resolves the request's post to its template path.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|WP_Error
	 */
	private static function template_path( WP_REST_Request $request ) {
		$path = Imajiner_Editor::get_template_path( get_post( (int) $request['post'] ) );
		if ( ! $path ) {
			return new WP_Error( 'imajiner_no_template', __( 'This page does not use an Imajiner template.', 'imajiner-editor' ), array( 'status' => 404 ) );
		}
		return $path;
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
