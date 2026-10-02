<?php
/** Validated proposals replacing one selected node, only after review. */
defined( 'ABSPATH' ) || exit;

class Imajiner_Section_AI {
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'imajiner_ai_job_handler_section', array( __CLASS__, 'handle_job' ), 10, 3 );
		add_action( 'imajiner_ai_job_discard_section', array( __CLASS__, 'discard' ) );
	}

	public static function discard( $result ) {
		if ( is_array( $result ) && isset( $result['proposal'] ) ) delete_transient( self::proposal_key( $result['proposal'] ) );
	}

	public static function register_routes() {
		foreach ( array( 'section' => 'generate', 'section/accept' => 'accept' ) as $path => $method ) {
			register_rest_route( Imajiner_Rest::NAMESPACE_V1, '/ai/' . $path, array( 'methods' => 'POST', 'callback' => array( __CLASS__, $method ), 'permission_callback' => array( 'Imajiner_Generation', 'can_generate' ), 'args' => 'generate' === $method ? array(
				'key' => array( 'type' => 'string', 'required' => true, 'pattern' => '^(?:parts/)?[a-z0-9_-]+$' ),
				'id' => array( 'type' => 'string', 'required' => true, 'pattern' => '^[es][0-9]+$' ),
				'hash' => array( 'type' => 'string', 'required' => true ),
				'prompt' => array( 'type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 20000 ),
			) : array( 'proposal' => array( 'type' => 'string', 'required' => true, 'pattern' => '^[a-f0-9-]{36}$' ) ) ) );
		}
	}

	private static function context( $key, $hash ) {
		$template = Imajiner_Editor::get_template( $key );
		if ( ! $template || ! Imajiner_Generation::in_child_theme( $template['file'] ) ) return new WP_Error( 'imajiner_not_found', __( 'Select a template in the child theme.', 'imajiner-editor' ), array( 'status' => 404 ) );
		$before = Imajiner_Template_Store::read( $template['file'] );
		if ( is_wp_error( $before ) ) return $before;
		if ( ! hash_equals( Imajiner_Template_Store::hash( $before ), (string) $hash ) ) return new WP_Error( 'imajiner_stale', __( 'The template changed. Reload before requesting AI edits.', 'imajiner-editor' ), array( 'status' => 409 ) );
		$scanner = new Imajiner_Template_Scanner( $before['php'], array( 'require_sections' => 'part' !== $template['type'] ) );
		if ( $scanner->get_structure()['warnings'] ) return new WP_Error( 'imajiner_contract', __( 'Normalize this template before editing with AI.', 'imajiner-editor' ), array( 'status' => 422 ) );
		return array( 'template' => $template, 'before' => $before, 'scanner' => $scanner, 'validation' => array( 'slug' => $template['slug'], 'name' => $template['name'], 'type' => $template['type'], 'scope' => 'part' === $template['type'] ? '.imj-part-' . $template['slug'] : '.imj-' . $template['slug'], 'headers' => get_file_data( $template['file'], Imajiner_Generation::headers( $template['type'] ) ) ) );
	}

	public static function generate( WP_REST_Request $request ) {
		$context = self::context( $request['key'], $request['hash'] );
		if ( is_wp_error( $context ) ) return $context;
		$source = self::node_source( $context['before']['php'], $request['id'] );
		if ( is_wp_error( $source ) ) return $source;
		return rest_ensure_response( Imajiner_AI_Jobs::enqueue( 'section', array( 'key' => $request['key'], 'hash' => $request['hash'], 'id' => $request['id'], 'prompt' => $request['prompt'] ) ) );
	}

	public static function handle_job( $result, $payload, $job_id ) {
		Imajiner_AI_Jobs::progress( $job_id, 20 );
		$context = self::context( $payload['key'], $payload['hash'] );
		if ( is_wp_error( $context ) ) return $context;
		$source = self::node_source( $context['before']['php'], $payload['id'] );
		if ( is_wp_error( $source ) ) return $source;
		$messages = array(
			array( 'role' => 'system', 'content' => Imajiner_Prompts::system_prompt() ),
			array( 'role' => 'user', 'content' => "Edit only the selected node. Return only JSON with string fields php (replacement node only) and css (new scoped rules only). Keep section markers when replacing a section; return exactly one root element when replacing an element. Do not include template headers, get_header/get_footer, unrelated content or Markdown. Existing PHP behavior should be preserved unless explicitly requested. CSS selectors must start with the template scope followed by a descendant or child selector. Use a unique class on the replacement node for all new rules.\nScope: " . $context['validation']['scope'] . "\nSelected source:\n" . $source . "\nExisting CSS:\n" . $context['before']['css'] . "\nInstructions:\n" . $payload['prompt'] ),
		);
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$reply = Imajiner_AI::chat( $messages, array( 'max_tokens' => 16000 ) );
			if ( is_wp_error( $reply ) ) return $reply;
			$proposal = self::validate( $reply, $context, $payload['id'] );
			if ( ! is_wp_error( $proposal ) ) break;
			$messages[] = array( 'role' => 'assistant', 'content' => $reply );
			$messages[] = array( 'role' => 'user', 'content' => 'Correct the JSON replacement only: ' . $proposal->get_error_message() . ' ' . wp_json_encode( $proposal->get_error_data() ) );
		}
		if ( is_wp_error( $proposal ) ) return $proposal;
		Imajiner_AI_Jobs::progress( $job_id, 90 );
		$id = wp_generate_uuid4();
		$proposal['key'] = $payload['key'];
		$proposal['hash'] = $payload['hash'];
		$proposal['id'] = $payload['id'];
		$proposal['stylesheet'] = get_stylesheet();
		if ( ! set_transient( self::proposal_key( $id ), $proposal, HOUR_IN_SECONDS ) ) return new WP_Error( 'imajiner_proposal_failed', __( 'The proposal could not be stored.', 'imajiner-editor' ) );
		return array( 'proposal' => $id, 'before' => array( 'php' => $source, 'css' => $context['before']['css'] ), 'after' => $proposal['replacement'], 'beforeMarkup' => Imajiner_Generation::static_markup( $source ), 'afterMarkup' => Imajiner_Generation::static_markup( $proposal['replacement']['php'] ), 'scope' => $context['validation']['scope'], 'usage' => Imajiner_AI::last_usage() );
	}

	private static function proposal_key( $id ) {
		return 'imajiner_section_ai_' . get_current_user_id() . '_' . $id;
	}

	public static function validate( $reply, array $context, $id ) {
		$data = json_decode( $reply, true );
		if ( ! is_array( $data ) || ! isset( $data['php'], $data['css'] ) || ! is_string( $data['php'] ) || ! is_string( $data['css'] ) ) return new WP_Error( 'imajiner_ai_invalid', __( 'Return replacement PHP and scoped CSS strings.', 'imajiner-editor' ), array( 'status' => 422 ) );
		$fragment = new Imajiner_Template_Scanner( $data['php'], array( 'require_sections' => false ) );
		$tree = $fragment->get_structure();
		$roots = array_values( array_filter( $tree['tree'], function ( $node ) { return 'element' === $node->type || 'section' === $node->type; } ) );
		if ( $tree['warnings'] || count( $roots ) !== 1 || count( $tree['tree'] ) !== 1 || ( 's' === $id[0] ? 'section' : 'element' ) !== $roots[0]->type ) return new WP_Error( 'imajiner_ai_invalid', __( 'Return exactly one balanced replacement node.', 'imajiner-editor' ), array( 'status' => 422, 'warnings' => $tree['warnings'] ) );
		$php = self::replace_node( $context['before']['php'], $id, $data['php'] );
		if ( is_wp_error( $php ) ) return $php;
		$scope = $context['validation']['scope'];
		$css_check = Imajiner_Css_Editor::validate_scope( $data['css'], $scope );
		if ( is_wp_error( $css_check ) ) return $css_check;
		$unique = self::unique_class( $roots[0], $context['before']['php'], $context['before']['css'] );
		if ( trim( $data['css'] ) && ( ! $unique || ! self::node_scoped_css( $data['css'], $scope, $unique ) ) ) return new WP_Error( 'imajiner_ai_css', __( 'Scope every new CSS selector to a unique class on the replacement node.', 'imajiner-editor' ), array( 'status' => 422 ) );
		$files = array( 'php' => $php, 'css' => $context['before']['css'] . ( trim( $data['css'] ) ? "\n" . $data['css'] : '' ) );
		$checked = Imajiner_Generation::validate_reply( wp_json_encode( array_merge( $files, array( 'slug' => $context['validation']['slug'], 'name' => $context['validation']['name'] ) ) ), $context['validation'] );
		if ( is_wp_error( $checked ) ) return $checked;
		if ( $checked['php'] !== $php ) return new WP_Error( 'imajiner_ai_metadata', __( 'Keep template metadata unchanged.', 'imajiner-editor' ), array( 'status' => 422 ) );
		return array( 'replacement' => $data );
	}

	private static function unique_class( $node, $php, $css ) {
		if ( 'section' === $node->type ) {
			$elements = array_values( array_filter( $node->children, function ( $child ) { return 'element' === $child->type; } ) );
			if ( 1 !== count( $elements ) ) return '';
			$node = $elements[0];
		}
		$classes = isset( $node->attrs->class ) && is_string( $node->attrs->class ) ? $node->attrs->class : '';
		foreach ( preg_split( '/\s+/', $classes ) as $class ) {
			if ( preg_match( '/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $class ) && false === strpos( $php . $css, $class ) ) return $class;
		}
		return '';
	}

	private static function node_scoped_css( $css, $scope, $class ) {
		$css = preg_replace( '~/\*.*?\*/~s', '', $css );
		preg_match_all( '/([^{}]+)\{/', $css, $matches );
		foreach ( $matches[1] as $prelude ) {
			if ( '@' === substr( trim( $prelude ), 0, 1 ) ) continue;
			foreach ( explode( ',', $prelude ) as $selector ) {
				if ( ! preg_match( '/^' . preg_quote( $scope, '/' ) . '\s+(?:>\s*)?\.' . preg_quote( $class, '/' ) . '(?=[\s.:#\[>+~]|$)/', trim( $selector ) ) || preg_match( '/[+~]|:has\(|:is\(|:where\(/i', $selector ) ) return false;
			}
		}
		return true;
	}

	public static function accept( WP_REST_Request $request ) {
		$key = self::proposal_key( $request['proposal'] );
		if ( ! Imajiner_AI_Jobs::acquire( $key ) ) return new WP_Error( 'imajiner_busy', __( 'This proposal is already being accepted.', 'imajiner-editor' ), array( 'status' => 409 ) );
		try {
			$proposal = get_transient( $key );
			if ( ! is_array( $proposal ) ) return new WP_Error( 'imajiner_expired', __( 'This proposal expired or was already accepted.', 'imajiner-editor' ), array( 'status' => 410 ) );
			if ( $proposal['stylesheet'] !== get_stylesheet() ) return new WP_Error( 'imajiner_theme_changed', __( 'The active theme changed.', 'imajiner-editor' ), array( 'status' => 409 ) );
			$context = self::context( $proposal['key'], $proposal['hash'] );
			if ( is_wp_error( $context ) ) return $context;
			$checked = self::validate( wp_json_encode( $proposal['replacement'] ), $context, $proposal['id'] );
			if ( is_wp_error( $checked ) ) return $checked;
			$php = self::replace_node( $context['before']['php'], $proposal['id'], $checked['replacement']['php'] );
			$files = array( 'php' => $php, 'css' => $context['before']['css'] . ( trim( $checked['replacement']['css'] ) ? "\n" . $checked['replacement']['css'] : '' ) );
			$result = Imajiner_Template_Store::write( $context['template']['file'], $proposal['hash'], $files, __( 'Before selected-node AI edit', 'imajiner-editor' ) );
			if ( is_wp_error( $result ) ) return $result;
			delete_transient( $key );
			return rest_ensure_response( array( 'saved' => true ) );
		} finally {
			Imajiner_AI_Jobs::release( $key );
		}
	}

	public static function node_source( $php, $id ) {
		$span = self::node_span( $php, $id );
		return is_wp_error( $span ) ? $span : substr( $php, $span['start'], $span['end'] - $span['start'] );
	}

	public static function replace_node( $php, $id, $replacement ) {
		$span = self::node_span( $php, $id );
		return is_wp_error( $span ) ? $span : substr_replace( $php, $replacement, $span['start'], $span['end'] - $span['start'] );
	}

	private static function node_span( $php, $id ) {
		$scanner = new Imajiner_Template_Scanner( $php, array( 'require_sections' => false ) );
		if ( $scanner->get_structure()['warnings'] || ! $scanner->is_lossless() || ! preg_match( '/^[es][0-9]+$/', $id ) ) return new WP_Error( 'imajiner_node', __( 'Select a balanced section or element.', 'imajiner-editor' ), array( 'status' => 422 ) );
		$masked = '';
		foreach ( token_get_all( $php ) as $token ) {
			$masked .= is_array( $token ) && T_INLINE_HTML === $token[0] ? $token[1] : str_repeat( ' ', strlen( is_array( $token ) ? $token[1] : $token ) );
		}
		$processor = new Imajiner_Source_Processor( $masked );
		$elements = 0;
		$sections = 0;
		$stack = array();
		while ( $processor->next_token() ) {
			$span = $processor->span();
			$type = $processor->get_token_type();
			if ( '#tag' === $type && ! $processor->is_tag_closer() ) {
				$tag = $processor->get_tag();
				$current = 'e' . $elements++;
				$leaf = in_array( $tag, Imajiner_Template_Scanner::VOID_TAGS, true ) || in_array( $tag, Imajiner_Template_Scanner::RAW_TEXT_TAGS, true ) || $processor->has_self_closing_flag();
				if ( $leaf && $current === $id ) return $span;
				if ( ! $leaf ) $stack[] = array( 'id' => $current, 'tag' => $tag, 'start' => $span['start'] );
			} elseif ( '#tag' === $type && $processor->is_tag_closer() ) {
				$open = array_pop( $stack );
				if ( ! $open || $open['tag'] !== $processor->get_tag() ) break;
				if ( $open['id'] === $id ) return array( 'start' => $open['start'], 'end' => $span['end'] );
			} elseif ( '#comment' === $type ) {
				$comment = trim( $processor->get_modifiable_text() );
				if ( preg_match( '/^imj:section\s+name="[^"]*"$/', $comment ) ) {
					$stack[] = array( 'id' => 's' . $sections++, 'tag' => 'section-marker', 'start' => $span['start'] );
				} elseif ( '/imj:section' === $comment ) {
					$open = array_pop( $stack );
					if ( ! $open || 'section-marker' !== $open['tag'] ) break;
					if ( $open['id'] === $id ) return array( 'start' => $open['start'], 'end' => $span['end'] );
				}
			}
		}
		return new WP_Error( 'imajiner_node', __( 'Selected node not found.', 'imajiner-editor' ), array( 'status' => 404 ) );
	}
}
