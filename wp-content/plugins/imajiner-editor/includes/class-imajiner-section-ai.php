<?php
/** Reviewed AI edits of a single literal subtree. @package Imajiner_Editor */
defined( 'ABSPATH' ) || exit;

class Imajiner_Section_AI {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'imajiner_ai_job_handler_section', array( __CLASS__, 'handle_job' ), 10, 3 );
		add_action( 'imajiner_ai_job_discarded', array( __CLASS__, 'discard_job' ), 10, 3 );
	}

	public static function discard_job( $type, $result, $owner ) {
		if ( 'section' === $type && is_array( $result ) && isset( $result['proposal'] ) ) {
			delete_transient( 'imajiner_section_ai_' . $owner . '_' . $result['proposal'] );
		}
	}

	public static function register_routes() {
		foreach ( array( 'section' => 'enqueue', 'section/accept' => 'accept' ) as $path => $callback ) {
			register_rest_route( Imajiner_Rest::NAMESPACE_V1, '/ai/' . $path, array(
				'methods' => 'POST', 'callback' => array( __CLASS__, $callback ),
				'permission_callback' => array( 'Imajiner_Generation', 'can_generate' ),
				'args' => 'enqueue' === $callback ? array(
					'key' => array( 'type' => 'string', 'required' => true, 'pattern' => '^(?:parts/)?[a-z0-9_-]+$' ),
					'hash' => array( 'type' => 'string', 'required' => true, 'maxLength' => 128 ),
					'id' => array( 'type' => 'string', 'required' => true, 'pattern' => '^[es][0-9]+$' ),
					'prompt' => array( 'type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 20000 ),
				) : array( 'proposal' => array( 'type' => 'string', 'required' => true, 'pattern' => '^[a-f0-9-]{36}$' ) ),
			) );
		}
	}

	private static function context( array $payload ) {
		$template = Imajiner_Editor::get_template( $payload['key'] );
		if ( ! $template || ! Imajiner_Generation::in_child_theme( $template['file'] ) ) {
			return new WP_Error( 'imajiner_not_found', __( 'Select a template in the child theme.', 'imajiner-editor' ), array( 'status' => 404 ) );
		}
		$files = Imajiner_Template_Store::read( $template['file'] );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		if ( ! hash_equals( Imajiner_Template_Store::hash( $files ), $payload['hash'] ) ) {
			return new WP_Error( 'imajiner_conflict', __( 'The template changed. Reload before using AI.', 'imajiner-editor' ), array( 'status' => 409 ) );
		}
		$range = self::range( $files['php'], $payload['id'], 'part' !== $template['type'] );
		if ( is_wp_error( $range ) ) {
			return $range;
		}
		return array( 'template' => $template, 'files' => $files, 'range' => $range, 'scope' => ( 'part' === $template['type'] ? '.imj-part-' : '.imj-' ) . $template['slug'] );
	}

	public static function enqueue( WP_REST_Request $request ) {
		$payload = array( 'key' => $request['key'], 'hash' => $request['hash'], 'id' => $request['id'], 'prompt' => trim( $request['prompt'] ) );
		$context = self::context( $payload );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$job = Imajiner_AI_Jobs::enqueue( 'section', $payload );
		return is_wp_error( $job ) ? $job : new WP_REST_Response( $job, 202 );
	}

	public static function handle_job( $unused, $payload, $job_id ) {
		$context = self::context( $payload );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$anchor = 'imj-ai-' . substr( wp_generate_uuid4(), 0, 8 );
		$range = $context['range'];
		$before = substr( $context['files']['php'], $range['start'], $range['end'] - $range['start'] );
		$messages = array(
			array( 'role' => 'system', 'content' => Imajiner_Prompts::system_prompt() ),
			array( 'role' => 'user', 'content' => 'Edit only this selected literal HTML node. Return only a JSON object with string fields html and css, not a whole template. Never emit PHP, scripts, event handlers or inline styles. Keep the same root kind and section name. The replacement must have exactly one root HTML element with class ' . $anchor . '. CSS selectors must be scoped to ' . $context['scope'] . ' .' . $anchor . '. Use design tokens. Preserve existing content unless the instruction asks otherwise.' . "\nSelected source:\n" . $before . "\nInstructions:\n" . $payload['prompt'] ),
		);
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			Imajiner_AI_Jobs::progress( $job_id, 25 + 30 * $attempt );
			$reply = Imajiner_AI::chat( $messages, array( 'timeout' => 90, 'max_tokens' => 8000 ) );
			if ( is_wp_error( $reply ) ) {
				return $reply;
			}
			$validated = self::validate_reply( $reply, $context, $anchor );
			if ( ! is_wp_error( $validated ) ) {
				break;
			}
			if ( 0 === $attempt ) {
				$messages[] = array( 'role' => 'assistant', 'content' => $reply );
				$messages[] = array( 'role' => 'user', 'content' => 'Fix validation errors and return the replacement JSON again: ' . implode( "\n", $validated->get_error_data()['warnings'] ) );
			}
		}
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$id = wp_generate_uuid4();
		$proposal = array( 'payload' => $payload, 'theme' => get_stylesheet(), 'anchor' => $anchor, 'reply' => $reply, 'job' => $job_id );
		if ( ! set_transient( self::proposal_key( $id ), $proposal, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'imajiner_proposal_failed', __( 'The proposal could not be stored.', 'imajiner-editor' ) );
		}
		$replacement = json_decode( $reply, true );
		return array( 'proposal' => $id, 'before' => array( 'html' => $before, 'css' => $context['files']['css'] ), 'after' => array( 'html' => $replacement['html'], 'css' => $validated['css'] ), 'scope' => $context['scope'], 'warnings' => array() );
	}

	public static function validate_reply( $reply, array $context, $anchor ) {
		$data = json_decode( $reply, true );
		$warnings = array();
		if ( ! is_array( $data ) || ! isset( $data['html'], $data['css'] ) || ! is_string( $data['html'] ) || ! is_string( $data['css'] ) || strlen( $data['html'] ) + strlen( $data['css'] ) > 1000000 ) {
			$warnings[] = 'Return JSON with string fields html and css.';
		} elseif ( false !== strpos( $data['html'], '<?' ) || false !== strpos( $data['html'], 'imj-php:' ) ) {
			$warnings[] = 'PHP and scanner placeholders are not allowed in visual AI edits.';
		} else {
			$scanner = new Imajiner_Template_Scanner( $data['html'], array( 'require_sections' => false ) );
			$structure = $scanner->get_structure();
			$warnings = $structure['warnings'];
			$root = 1 === count( $structure['tree'] ) ? $structure['tree'][0] : null;
			$original = $context['range']['node'];
			if ( ! $root || $root->type !== $original->type || ( 'section' === $original->type && $root->name !== $original->name ) ) {
				$warnings[] = 'Keep exactly one root of the original kind and the original section name.';
			}
			$element = $root && 'section' === $root->type && 1 === count( $root->children ) ? $root->children[0] : $root;
			$attrs = $element && isset( $element->attrs ) ? (array) $element->attrs : array();
			if ( ! $element || 'element' !== $element->type || ! in_array( $anchor, preg_split( '/\s+/', isset( $attrs['class'] ) ? $attrs['class'] : '' ), true ) ) {
				$warnings[] = 'The replacement needs exactly one root element with the requested scope class.';
			}
			$processor = new WP_HTML_Tag_Processor( $data['html'] );
			while ( $processor->next_tag() ) {
				if ( in_array( $processor->get_tag(), array( 'SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'LINK', 'META', 'BASE', 'SVG', 'MATH' ), true ) || null !== $processor->get_attribute( 'style' ) || $processor->get_attribute_names_with_prefix( 'on' ) ) {
					$warnings[] = 'Scripts, embedded documents, event handlers and inline styles are not allowed.';
				}
				foreach ( array( 'href', 'src', 'action', 'formaction', 'xlink:href', 'poster', 'background', 'cite', 'longdesc' ) as $attribute ) {
					$value = $processor->get_attribute( $attribute );
					if ( is_string( $value ) && wp_kses_bad_protocol( $value, wp_allowed_protocols() ) !== trim( $value ) ) {
						$warnings[] = 'Use safe media and link URLs.';
					}
				}
			}
			$css = Imajiner_Css_Editor::validate_scope( $data['css'], $context['scope'] . ' .' . $anchor );
			if ( is_wp_error( $css ) ) {
				$warnings[] = $css->get_error_message();
			}
			$range = $context['range'];
			$php = substr_replace( $context['files']['php'], $data['html'], $range['start'], $range['end'] - $range['start'] );
			$after = new Imajiner_Template_Scanner( $php, array( 'require_sections' => 'part' !== $context['template']['type'] ) );
			$original_scanner = new Imajiner_Template_Scanner( $context['files']['php'] );
			$warnings = array_merge( $warnings, $after->get_structure()['warnings'] );
			if ( ! $after->is_lossless() || $original_scanner->get_php_sources() !== $after->get_php_sources() ) {
				$warnings[] = 'All PHP outside the selection must stay byte-identical.';
			}
		}
		if ( $warnings ) {
			return new WP_Error( 'imajiner_ai_invalid', __( 'The selected edit failed validation. Nothing was saved.', 'imajiner-editor' ), array( 'status' => 422, 'warnings' => array_values( array_unique( $warnings ) ) ) );
		}
		return array( 'php' => $php, 'css' => $context['files']['css'] . "\n" . $data['css'] );
	}

	public static function accept( WP_REST_Request $request ) {
		$key = self::proposal_key( $request['proposal'] );
		if ( ! Imajiner_Generation::can_generate() || ! add_option( $key . '_accept', time(), '', false ) ) {
			return new WP_Error( 'imajiner_accept_busy', __( 'This proposal cannot be accepted.', 'imajiner-editor' ), array( 'status' => 409 ) );
		}
		try {
			$proposal = get_transient( $key );
			if ( ! is_array( $proposal ) || ! Imajiner_AI_Jobs::can_accept( $proposal['job'] ) ) {
				return new WP_Error( 'imajiner_expired', __( 'This proposal expired.', 'imajiner-editor' ), array( 'status' => 410 ) );
			}
			if ( get_stylesheet() !== $proposal['theme'] ) {
				return new WP_Error( 'imajiner_theme_changed', __( 'The active theme changed.', 'imajiner-editor' ), array( 'status' => 409 ) );
			}
			$context = self::context( $proposal['payload'] );
			if ( is_wp_error( $context ) ) {
				return $context;
			}
			$files = self::validate_reply( $proposal['reply'], $context, $proposal['anchor'] );
			if ( is_wp_error( $files ) ) {
				return $files;
			}
			$result = Imajiner_Template_Store::write( $context['template']['file'], $proposal['payload']['hash'], $files, __( 'Before selected AI edit', 'imajiner-editor' ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			delete_transient( $key );
			return rest_ensure_response( array( 'saved' => true, 'hash' => Imajiner_Template_Store::hash( $files ) ) );
		} finally {
			delete_option( $key . '_accept' );
		}
	}

	private static function proposal_key( $id ) {
		return 'imajiner_section_ai_' . get_current_user_id() . '_' . $id;
	}

	/** Same-byte-length PHP masking preserves source offsets; scanner determines editability. */
	public static function range( $php, $id, $require_sections = true ) {
		$scanner = new Imajiner_Template_Scanner( $php, array( 'require_sections' => $require_sections ) );
		$structure = $scanner->get_structure();
		$node = self::find( $structure['tree'], $id );
		if ( $structure['warnings'] || ! $node || empty( $node->mutable ) || ! in_array( $node->type, array( 'element', 'section' ), true ) ) {
			return new WP_Error( 'imajiner_ai_locked', __( 'Select a static section or element. PHP-containing nodes stay locked.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}
		$masked = '';
		foreach ( token_get_all( $php ) as $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			$masked .= is_array( $token ) && T_INLINE_HTML === $token[0] ? $text : str_repeat( ' ', strlen( $text ) );
		}
		$processor = new Imajiner_Source_Processor( $masked );
		$elements = 0;
		$sections = 0;
		$start = null;
		$depth = 0;
		while ( $processor->next_token() ) {
			$span = $processor->span();
			if ( '#tag' === $processor->get_token_type() && 'element' === $node->type ) {
				$tag = $processor->get_tag();
				if ( ! $processor->is_tag_closer() ) {
					$current = 'e' . $elements++;
					if ( $current === $id ) {
						$start = $span['start'];
					}
					if ( null !== $start ) {
						$leaf = in_array( $tag, Imajiner_Template_Scanner::VOID_TAGS, true ) || in_array( $tag, Imajiner_Template_Scanner::RAW_TEXT_TAGS, true ) || $processor->has_self_closing_flag();
						$depth += $leaf ? 0 : 1;
					}
				} elseif ( null !== $start ) {
					--$depth;
				}
			} elseif ( '#comment' === $processor->get_token_type() && 'section' === $node->type ) {
				$comment = trim( $processor->get_modifiable_text() );
				if ( preg_match( '/^imj:section\s+name="[^"]*"$/', $comment ) ) {
					if ( 's' . $sections++ === $id ) {
						$start = $span['start'];
					}
					if ( null !== $start ) {
						++$depth;
					}
				} elseif ( '/imj:section' === $comment && null !== $start ) {
					--$depth;
				}
			}
			if ( null !== $start && 0 === $depth ) {
				return array( 'start' => $start, 'end' => $span['end'], 'node' => $node );
			}
		}
		return new WP_Error( 'imajiner_ai_range', __( 'The selected source range could not be read.', 'imajiner-editor' ), array( 'status' => 400 ) );
	}

	private static function find( array $nodes, $id ) {
		foreach ( $nodes as $node ) {
			if ( isset( $node->id ) && $node->id === $id ) {
				return $node;
			}
			if ( isset( $node->children ) ) {
				$found = self::find( $node->children, $id );
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}
}
