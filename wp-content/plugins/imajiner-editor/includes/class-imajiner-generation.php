<?php
/**
 * Validated AI proposals, reviewed before they become executable templates.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

class Imajiner_Generation {

	public static function register_routes() {
		foreach ( array( 'generate', 'accept' ) as $action ) {
			register_rest_route(
				Imajiner_Rest::NAMESPACE_V1,
				'/ai/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $action ),
					'permission_callback' => array( __CLASS__, 'can_generate' ),
					'args'                => 'generate' === $action ? array(
						'prompt' => array( 'type' => 'string', 'default' => '', 'maxLength' => 20000 ),
						'name'   => array( 'type' => 'string', 'default' => '', 'maxLength' => 200 ),
						'key'    => array( 'type' => 'string', 'default' => '', 'pattern' => '^(?:parts/)?[a-z0-9_-]*$' ),
					) : array(
						'proposal' => array( 'type' => 'string', 'required' => true, 'pattern' => '^[a-f0-9-]{36}$' ),
					),
				)
			);
		}
	}

	public static function can_generate() {
		return Imajiner_Editor::theme_ready() && is_child_theme() && Imajiner_Editor::user_can_edit_templates();
	}

	public static function generate( WP_REST_Request $request ) {
		$key      = $request['key'];
		$template = $key ? Imajiner_Editor::get_template( $key ) : null;
		if ( $key && ! $template ) {
			return new WP_Error( 'imajiner_not_found', __( 'Template not found.', 'imajiner-editor' ), array( 'status' => 404 ) );
		}
		if ( ! $template && ! current_user_can( get_post_type_object( 'page' )->cap->create_posts ) ) {
			return new WP_Error( 'imajiner_cannot_create_page', __( 'You cannot create pages.', 'imajiner-editor' ), array( 'status' => 403 ) );
		}

		$name = $template ? $template['name'] : sanitize_text_field( $request['name'] );
		$slug = $template ? $template['slug'] : sanitize_title( $name );
		if ( '' === $name || ! preg_match( '/^[a-z0-9_-]+$/', $slug ) || false !== strpos( $name, '*/' ) ) {
			return new WP_Error( 'imajiner_invalid_name', __( 'Enter a name using letters and numbers.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}
		$path = $template ? $template['file'] : get_stylesheet_directory() . '/' . Imajiner_Editor::TEMPLATE_DIR . '/' . $slug . '.php';
		if ( $template && ! self::in_child_theme( $path ) ) {
			return new WP_Error( 'imajiner_parent_template', __( 'Copy this template into the child theme before normalizing it.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}
		if ( ! $template && ( file_exists( $path ) || file_exists( Imajiner_Template_Store::css_path( $path ) ) ) ) {
			return new WP_Error( 'imajiner_exists', __( 'That template or stylesheet already exists. Use another name.', 'imajiner-editor' ), array( 'status' => 409 ) );
		}
		$before = $template ? Imajiner_Template_Store::read( $path ) : array( 'php' => '', 'css' => '' );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		$prompt = trim( $request['prompt'] );
		if ( ! $template && '' === $prompt ) {
			return new WP_Error( 'imajiner_prompt_required', __( 'Describe the page to create.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}
		$type    = $template ? $template['type'] : 'page';
		$scope   = 'part' === $type ? '.imj-part-' . $slug : '.imj-' . $slug;
		$headers = $template ? get_file_data( $path, self::headers( $type ) ) : array( 'Template Name' => $name, 'Template Post Type' => '', 'Imajiner Location' => '' );
		$context = array( 'slug' => $slug, 'name' => $name, 'type' => $type, 'scope' => $scope, 'headers' => $headers );
		$messages = array(
			array( 'role' => 'system', 'content' => Imajiner_Prompts::system_prompt() ),
			array(
				'role'    => 'user',
				'content' => ( $template ? 'Normalize this template. Preserve its content, PHP behavior and assignments.' : 'Create a new page template.' )
					. "\nReturn only a JSON object with string fields slug, name, php and css. No Markdown fences."
					. "\nUse this exact metadata: " . wp_json_encode( $context )
					. "\nCSS: every selector must begin with the scope above, followed by a descendant or child selector. Only plain rules and @media or @supports groups; no nesting, imports, keyframes or other at-rules."
					. "\nInstructions: " . $prompt
					. ( $template ? "\nExisting files: " . wp_json_encode( $before ) : '' ),
			),
		);

		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$reply = Imajiner_AI::chat( $messages, array( 'max_tokens' => 16000 ) );
			if ( is_wp_error( $reply ) ) {
				return $reply;
			}
			$files = self::validate_reply( $reply, $context );
			if ( ! is_wp_error( $files ) ) {
				break;
			}
			$messages[] = array( 'role' => 'assistant', 'content' => $reply );
			$messages[] = array( 'role' => 'user', 'content' => "Fix these validation errors and return the complete JSON object again:\n" . implode( "\n", $files->get_error_data()['warnings'] ) );
		}
		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$id = wp_generate_uuid4();
		$stored = set_transient(
			self::proposal_key( $id ),
			array( 'key' => $key ?: $slug, 'name' => $name, 'stylesheet' => get_stylesheet(), 'normalize' => (bool) $template, 'hash' => Imajiner_Template_Store::hash( $before ), 'files' => $files, 'context' => $context ),
			HOUR_IN_SECONDS
		);
		if ( ! $stored ) {
			return new WP_Error( 'imajiner_proposal_failed', __( 'The proposal could not be stored. Nothing was saved.', 'imajiner-editor' ), array( 'status' => 500 ) );
		}
		$scanner = new Imajiner_Template_Scanner( $before['php'], array( 'require_sections' => 'part' !== $type ) );
		return rest_ensure_response(
			array(
				'proposal'       => $id,
				'before'         => $before,
				'after'          => $files,
				'beforeMarkup'   => self::static_markup( $before['php'] ),
				'afterMarkup'    => self::static_markup( $files['php'] ),
				'beforeWarnings' => $template ? $scanner->get_structure()['warnings'] : array(),
				'warnings'       => array(),
				'scope'          => $scope,
			)
		);
	}

	public static function accept( WP_REST_Request $request ) {
		$transient = self::proposal_key( $request['proposal'] );
		$proposal  = get_transient( $transient );
		if ( ! is_array( $proposal ) ) {
			return new WP_Error( 'imajiner_expired', __( 'This proposal expired. Generate it again.', 'imajiner-editor' ), array( 'status' => 410 ) );
		}
		if ( $proposal['stylesheet'] !== get_stylesheet() ) {
			return new WP_Error( 'imajiner_theme_changed', __( 'The active theme changed. Generate the proposal again.', 'imajiner-editor' ), array( 'status' => 409 ) );
		}
		$files = self::validate_reply( wp_json_encode( array_merge( $proposal['files'], array( 'slug' => $proposal['context']['slug'], 'name' => $proposal['name'] ) ) ), $proposal['context'] );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		if ( $proposal['normalize'] ) {
			$template = Imajiner_Editor::get_template( $proposal['key'] );
			if ( ! $template || ! self::in_child_theme( $template['file'] ) ) {
				return new WP_Error( 'imajiner_not_found', __( 'Template not found.', 'imajiner-editor' ), array( 'status' => 404 ) );
			}
			$result = Imajiner_Template_Store::write( $template['file'], $proposal['hash'], $files, __( 'Before AI normalization', 'imajiner-editor' ) );
		} else {
			if ( ! current_user_can( get_post_type_object( 'page' )->cap->create_posts ) ) {
				return new WP_Error( 'imajiner_cannot_create_page', __( 'You cannot create pages.', 'imajiner-editor' ), array( 'status' => 403 ) );
			}
			$path = get_stylesheet_directory() . '/' . Imajiner_Editor::TEMPLATE_DIR . '/' . $proposal['key'] . '.php';
			if ( file_exists( Imajiner_Template_Store::css_path( $path ) ) ) {
				return new WP_Error( 'imajiner_exists', __( 'That stylesheet already exists. Generate with another name.', 'imajiner-editor' ), array( 'status' => 409 ) );
			}
			$result = Imajiner_Template_Store::create( $path, $files );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		wp_clean_themes_cache( false );
		delete_transient( $transient );
		if ( ! $proposal['normalize'] ) {
			$page = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'draft',
					'post_title'  => $proposal['name'],
					'meta_input'  => array( '_wp_page_template' => Imajiner_Editor::TEMPLATE_DIR . '/' . $proposal['key'] . '.php' ),
				),
				true
			);
			if ( is_wp_error( $page ) ) {
				return new WP_Error( 'imajiner_page_failed', __( 'The template was saved, but a draft page could not be created. Assign the template to a page manually.', 'imajiner-editor' ), array( 'status' => 500 ) );
			}
		}
		return rest_ensure_response( array( 'url' => Imajiner_Editor::editor_url( $proposal['key'] ) ) );
	}

	private static function proposal_key( $id ) {
		return 'imajiner_ai_' . get_current_user_id() . '_' . $id;
	}

	private static function in_child_theme( $path ) {
		$root = realpath( get_stylesheet_directory() );
		$file = realpath( $path );
		return $root && $file && 0 === strpos( $file, $root . DIRECTORY_SEPARATOR );
	}

	private static function static_markup( $php ) {
		$html = '';
		foreach ( token_get_all( $php ) as $token ) {
			if ( is_array( $token ) && T_INLINE_HTML === $token[0] ) {
				$html .= $token[1];
			}
		}
		return $html;
	}

	private static function headers( $type ) {
		$names = 'part' === $type ? array( 'Part Name', 'Part Description', 'Part Location' ) : array( 'Template Name', 'Template Post Type', 'Imajiner Location' );
		return array_combine( $names, $names );
	}

	public static function validate_reply( $reply, array $context ) {
		$data   = json_decode( $reply, true );
		$errors = array();
		foreach ( array( 'slug', 'name', 'php', 'css' ) as $field ) {
			if ( ! is_array( $data ) || ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) ) {
				$errors[] = 'Return a JSON object with string fields slug, name, php and css.';
				break;
			}
		}
		if ( ! $errors ) {
			if ( ! preg_match( '/\A\s*<\?php\s*\/\*\*/', $data['php'] ) ) {
				$errors[] = 'Start the PHP file with a template header docblock.';
			}
			if ( $data['slug'] !== $context['slug'] || $data['name'] !== $context['name'] ) {
				$errors[] = 'Keep the exact requested slug and name.';
			}
			try {
				token_get_all( $data['php'], TOKEN_PARSE );
			} catch ( ParseError $error ) {
				$errors[] = 'PHP syntax: ' . $error->getMessage() . ' on line ' . $error->getLine();
			}
			if ( ! $errors ) {
				foreach ( $context['headers'] as $header => $value ) {
					$updated = Imajiner_Builder::set_header( $data['php'], $header, $value );
					if ( is_wp_error( $updated ) ) {
						$errors[] = $updated->get_error_message();
						break;
					}
					$data['php'] = $updated;
				}
				$scanner = new Imajiner_Template_Scanner( $data['php'], array( 'require_sections' => 'part' !== $context['type'] ) );
				$errors  = array_merge( $errors, $scanner->get_structure()['warnings'] );
				if ( ! $scanner->is_lossless() ) {
					$errors[] = 'The scanner cannot round-trip the PHP source.';
				}
				$processor = new WP_HTML_Tag_Processor( $scanner->get_instrumented_source() );
				while ( $processor->next_tag() ) {
					if ( 'STYLE' === $processor->get_tag() || null !== $processor->get_attribute( 'style' ) ) {
						$errors[] = 'Move inline styles to the CSS file.';
						break;
					}
				}
			}
			$css = Imajiner_Css_Editor::validate_scope( $data['css'], $context['scope'] );
			if ( is_wp_error( $css ) ) {
				$errors[] = $css->get_error_message();
			}
		}
		if ( $errors ) {
			return new WP_Error( 'imajiner_ai_invalid', __( 'The AI output did not pass validation. Nothing was saved.', 'imajiner-editor' ), array( 'status' => 422, 'warnings' => array_values( array_unique( $errors ) ) ) );
		}
		return array( 'php' => $data['php'], 'css' => $data['css'] );
	}
}
