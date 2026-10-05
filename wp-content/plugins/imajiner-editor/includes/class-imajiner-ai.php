<?php
/**
 * AI provider settings and a chat client for the supported providers.
 *
 * Most providers speak the OpenAI API: POST {base}/chat/completions and
 * GET {base}/models with a Bearer key. Claude is called through Anthropic's
 * own Messages API (POST {base}/messages with x-api-key), not a compatibility
 * layer. Any other OpenAI-compatible endpoint can be added with a custom base URL.
 *
 * API keys come from a wp-config.php constant when defined (never stored), or
 * from the settings, encrypted with Imajiner_Secrets. They are never sent to
 * the browser.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * AI client.
 */
class Imajiner_AI {

	/**
	 * Option holding the settings. Not autoloaded.
	 */
	const OPTION = 'imajiner_editor_ai';

	/**
	 * Anthropic API version header value.
	 */
	const ANTHROPIC_VERSION = '2023-06-01';

	/**
	 * Claude models that accept Anthropic's server-side refusal fallback ("fallbacks": "default").
	 */
	const ANTHROPIC_FALLBACK_MODELS = array( 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5', 'claude-fable-5', 'claude-opus-5-5', 'claude-sonnet-5-5' );

	private static $attempts = array();
	private static $response_meta = array();
	private static $last_result = array();

	public static function init() {
		if ( did_action( 'init' ) ) {
			self::register_usage_type();
		} else {
			add_action( 'init', array( __CLASS__, 'register_usage_type' ) );
		}
		add_action( 'rest_api_init', array( __CLASS__, 'register_usage_route' ) );
	}

	public static function register_usage_type() {
		register_post_type( 'imajiner_ai_usage', array( 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'can_export' => false, 'supports' => array(), 'rewrite' => false, 'query_var' => false ) );
	}

	public static function register_usage_route() {
		register_rest_route( Imajiner_Rest::NAMESPACE_V1, '/ai/usage', array(
			'methods' => 'GET', 'permission_callback' => function () { return current_user_can( 'manage_options' ) || current_user_can( 'edit_themes' ); },
			'callback' => function () {
				$logs = get_posts( array( 'post_type' => 'imajiner_ai_usage', 'post_status' => 'private', 'author' => get_current_user_id(), 'numberposts' => 50 ) );
				return rest_ensure_response( array_map( function ( $post ) { return get_post_meta( $post->ID, '_imajiner_usage', true ); }, $logs ) );
			},
		) );
	}

	public static function last_result() {
		return self::$last_result;
	}

	/**
	 * Provider definitions.
	 *
	 * Each provider has: label; api ('openai' or 'anthropic'); base_url (null when
	 * the user sets it); key_url (where to get a key, or ''); default_model (used
	 * when no model is chosen, or ''); max_tokens_param (the request field that
	 * caps output). The wp-config.php constant is IMAJINER_<ID>_API_KEY.
	 *
	 * @return array Provider id => definition.
	 */
	public static function providers() {
		$providers = array(
			'openrouter' => array(
				'label'    => 'OpenRouter',
				'base_url' => 'https://openrouter.ai/api/v1',
				'key_url'  => 'https://openrouter.ai/keys',
			),
			'openai'     => array(
				'label'            => 'OpenAI',
				'base_url'         => 'https://api.openai.com/v1',
				'key_url'          => 'https://platform.openai.com/api-keys',
				// Newer OpenAI models reject max_tokens.
				'max_tokens_param' => 'max_completion_tokens',
			),
			'anthropic'  => array(
				'label'         => 'Claude (Anthropic)',
				'api'           => 'anthropic',
				'base_url'      => 'https://api.anthropic.com/v1',
				'key_url'       => 'https://platform.claude.com/',
				'default_model' => 'claude-opus-5',
			),
			'gemini'     => array(
				'label'    => 'Google Gemini',
				'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
				'key_url'  => 'https://aistudio.google.com/apikey',
			),
			'xai'        => array(
				'label'    => 'xAI Grok',
				'base_url' => 'https://api.x.ai/v1',
				'key_url'  => 'https://console.x.ai/',
			),
			'meta'       => array(
				'label'    => 'Meta / Llama',
				'base_url' => 'https://api.meta.ai/v1',
				'key_url'  => 'https://dev.meta.ai/',
			),
			'mistral'    => array(
				'label'    => 'Mistral',
				'base_url' => 'https://api.mistral.ai/v1',
				'key_url'  => 'https://console.mistral.ai/api-keys',
			),
			'deepseek'   => array(
				'label'    => 'DeepSeek',
				'base_url' => 'https://api.deepseek.com/v1',
				'key_url'  => 'https://platform.deepseek.com/api_keys',
			),
			'groq'       => array(
				'label'    => 'Groq',
				'base_url' => 'https://api.groq.com/openai/v1',
				'key_url'  => 'https://console.groq.com/keys',
			),
			'custom'     => array(
				'label'    => __( 'Other OpenAI-compatible', 'imajiner-editor' ),
				'base_url' => null,
			),
		);

		foreach ( $providers as $id => $provider ) {
			$providers[ $id ] = array_merge(
				array(
					'api'              => 'openai',
					'key_url'          => '',
					'default_model'    => '',
					'max_tokens_param' => 'max_tokens',
					'constant'         => 'IMAJINER_' . strtoupper( $id ) . '_API_KEY',
				),
				$provider
			);
		}

		return $providers;
	}

	/**
	 * Saved settings with defaults filled in.
	 *
	 * @return array {
	 *     @type array  $primary              Provider and model used first.
	 *     @type array  $fallback             Provider and model used when the primary fails; provider '' for none.
	 *     @type string $system_prompt_append Site-specific instructions added after the default system prompt.
	 *     @type array  $providers            Provider id => key (encrypted) and base_url.
	 * }
	 */
	public static function get_settings() {
		$saved    = get_option( self::OPTION, array() );
		$settings = array(
			'system_prompt_append' => isset( $saved['system_prompt_append'] ) && is_string( $saved['system_prompt_append'] ) ? $saved['system_prompt_append'] : '',
			'providers'            => array(),
		);

		foreach ( array( 'primary', 'fallback' ) as $slot ) {
			$value             = isset( $saved[ $slot ] ) && is_array( $saved[ $slot ] ) ? $saved[ $slot ] : array();
			$provider          = isset( $value['provider'] ) && isset( self::providers()[ $value['provider'] ] ) ? $value['provider'] : '';
			$settings[ $slot ] = array(
				'provider' => $provider,
				'model'    => isset( $value['model'] ) && is_string( $value['model'] ) ? $value['model'] : '',
			);
		}

		foreach ( array_keys( self::providers() ) as $id ) {
			$settings['providers'][ $id ] = array_merge(
				array(
					'key'      => '',
					'base_url' => '',
				),
				isset( $saved['providers'][ $id ] ) && is_array( $saved['providers'][ $id ] ) ? $saved['providers'][ $id ] : array()
			);
		}

		return $settings;
	}

	/**
	 * Saves settings. Keys must already be encrypted.
	 *
	 * @param array $settings Settings from get_settings().
	 */
	public static function save_settings( array $settings ) {
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Plain API key for a provider.
	 *
	 * @param string $provider Provider id.
	 * @return string Key, or '' when none is set or it can't be decrypted.
	 */
	public static function get_api_key( $provider ) {
		if ( ! isset( self::providers()[ $provider ] ) ) {
			return '';
		}
		$constant = self::providers()[ $provider ]['constant'];
		if ( defined( $constant ) && constant( $constant ) ) {
			return (string) constant( $constant );
		}

		$stored = self::get_settings()['providers'][ $provider ]['key'];
		if ( '' === $stored ) {
			return '';
		}

		$key = Imajiner_Secrets::decrypt( $stored );
		return false === $key ? '' : $key;
	}

	/**
	 * Where a provider's key comes from, for the settings screen. Never includes the key.
	 *
	 * @param string $provider Provider id.
	 * @return array {
	 *     @type string $state  'constant', 'saved', 'unreadable' or 'missing'.
	 *     @type string $masked Last characters of the key, when known.
	 * }
	 */
	public static function key_status( $provider ) {
		$constant = self::providers()[ $provider ]['constant'];
		if ( defined( $constant ) && constant( $constant ) ) {
			return array(
				'state'  => 'constant',
				'masked' => Imajiner_Secrets::mask( (string) constant( $constant ) ),
			);
		}

		$stored = self::get_settings()['providers'][ $provider ]['key'];
		if ( '' === $stored ) {
			return array(
				'state'  => 'missing',
				'masked' => '',
			);
		}

		$key = Imajiner_Secrets::decrypt( $stored );
		if ( false === $key ) {
			return array(
				'state'  => 'unreadable',
				'masked' => '',
			);
		}

		return array(
			'state'  => 'saved',
			'masked' => Imajiner_Secrets::mask( $key ),
		);
	}

	/**
	 * Base URL of a provider's API, without a trailing slash.
	 *
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function base_url( $provider ) {
		$fixed = self::providers()[ $provider ]['base_url'];
		$constant = 'IMAJINER_' . strtoupper( $provider ) . '_BASE_URL';
		$url   = null !== $fixed ? $fixed : ( defined( $constant ) ? constant( $constant ) : self::get_settings()['providers'][ $provider ]['base_url'] );
		if ( null === $fixed && $url && is_wp_error( self::validate_base_url( $url ) ) ) {
			return '';
		}
		return untrailingslashit( $url );
	}

	/** Opt-in WP-CLI runner: env keys are process-local; return only redacted metadata. */
	public static function smoke_from_environment() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'IMAJINER_AI_SMOKE' ) ) {
			return new WP_Error( 'imajiner_smoke_opt_in', __( 'Real provider smoke tests require explicit CLI opt-in.', 'imajiner-editor' ) );
		}
		$results = array();
		$selected = array_filter( array_map( 'trim', explode( ',', getenv( 'IMAJINER_AI_SMOKE_PROVIDERS' ) ?: implode( ',', array_keys( self::providers() ) ) ) ) );
		foreach ( $selected as $id ) {
			if ( ! isset( self::providers()[ $id ] ) ) {
				continue;
			}
			$provider = self::providers()[ $id ];
			$key = getenv( $provider['constant'] );
			$model = getenv( 'IMAJINER_' . strtoupper( $id ) . '_MODEL' ) ?: $provider['default_model'];
			$results[ $id ] = array( 'key_url' => $provider['key_url'], 'state' => 'skipped_missing_env_key' );
			if ( ! $key ) {
				continue;
			}
			if ( defined( $provider['constant'] ) && constant( $provider['constant'] ) !== $key ) {
				$results[ $id ]['state'] = 'skipped_env_key_conflict';
				continue;
			}
			if ( ! defined( $provider['constant'] ) ) {
				define( $provider['constant'], $key );
			}
			$base_constant = 'IMAJINER_' . strtoupper( $id ) . '_BASE_URL';
			if ( null === $provider['base_url'] && getenv( $base_constant ) && ! defined( $base_constant ) ) {
				define( $base_constant, getenv( $base_constant ) );
			}
			$models = self::list_models( $id );
			$results[ $id ]['models'] = is_wp_error( $models ) ? sanitize_key( $models->get_error_code() ) : count( $models );
			if ( ! $model ) {
				$results[ $id ]['state'] = 'skipped_missing_env_model';
				continue;
			}
			$reply = self::chat_result( array( array( 'role' => 'user', 'content' => 'Reply with the single word OK.' ) ), array( 'provider' => $id, 'model' => $model, 'max_tokens' => 128, 'timeout' => 30 ) );
			$results[ $id ]['state'] = is_wp_error( $reply ) ? sanitize_key( $reply->get_error_code() ) : ( is_wp_error( $models ) ? 'chat_passed_models_failed' : ( 'OK' === trim( $reply['content'] ) ? 'passed' : 'unexpected_reply' ) );
			$results[ $id ]['model'] = is_wp_error( $reply ) ? '' : $reply['model'];
			$results[ $id ]['usage'] = is_wp_error( $reply ) ? null : $reply['usage'];
		}
		return $results;
	}

	/**
	 * Checks a custom base URL. HTTPS, or HTTP only for local servers (e.g. Ollama, LM Studio),
	 * so keys never travel unencrypted over the internet.
	 *
	 * @param string $url URL.
	 * @return string|WP_Error Cleaned URL.
	 */
	public static function validate_base_url( $url ) {
		$url   = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );

		if ( ! $url || empty( $parts['host'] ) ) {
			return new WP_Error( 'imajiner_ai_base_url', __( 'Enter a valid base URL, e.g. https://api.example.com/v1.', 'imajiner-editor' ) );
		}

		$host  = strtolower( $parts['host'] );
		$local = in_array( $host, array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true ) || preg_match( '/\.(localhost|test|local)$/', $host );
		if ( 'https' !== $parts['scheme'] && ! $local ) {
			return new WP_Error( 'imajiner_ai_base_url', __( 'The base URL must use https:// (http:// is only allowed for servers on this machine).', 'imajiner-editor' ) );
		}

		return untrailingslashit( $url );
	}

	/**
	 * Provider and model for the primary or fallback slot. An empty model means the provider's default.
	 *
	 * @param string $slot 'primary' or 'fallback'.
	 * @return array Provider id ('' when not set) and model id.
	 */
	public static function slot( $slot ) {
		$value = self::get_settings()[ $slot ];
		if ( '' !== $value['provider'] && '' === $value['model'] ) {
			$value['model'] = self::providers()[ $value['provider'] ]['default_model'];
		}
		return $value;
	}

	/**
	 * Sends a chat to the primary model, and to the fallback model if the primary fails.
	 *
	 * Pass provider and model in $args to call one specific model with no fallback.
	 *
	 * @param array[] $messages Messages: role (system, user, assistant) and content.
	 * @param array   $args     Optional: provider, model, max_tokens, timeout.
	 * @return string|WP_Error Reply text.
	 */
	public static function chat( array $messages, array $args = array() ) {
		$result = self::chat_result( $messages, $args );
		return is_wp_error( $result ) ? $result : $result['content'];
	}

	/** Structured reply for callers needing the actual answering model and token usage. */
	public static function chat_result( array $messages, array $args = array() ) {
		self::$attempts = array();
		self::$last_result = array();
		$messages = self::prepare_messages( $messages );
		$reply = is_wp_error( $messages ) ? $messages : self::chat_unlogged( $messages, $args );
		$last = self::$attempts ? end( self::$attempts ) : array();
		$log = array( 'time' => time(), 'provider' => isset( $last['provider'] ) ? $last['provider'] : '', 'model' => isset( $last['model'] ) ? $last['model'] : '', 'usage' => isset( $last['usage'] ) ? $last['usage'] : null, 'attempts' => self::$attempts, 'fallback_attempts' => max( 0, count( self::$attempts ) - 1 ), 'success' => ! is_wp_error( $reply ) );
		$id = wp_insert_post( array( 'post_type' => 'imajiner_ai_usage', 'post_status' => 'private', 'post_author' => get_current_user_id() ), true );
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_imajiner_usage', $log );
		}
		$ids = get_posts( array( 'post_type' => 'imajiner_ai_usage', 'post_status' => 'private', 'author' => get_current_user_id(), 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC' ) );
		foreach ( array_slice( $ids, 100 ) as $old ) {
			wp_delete_post( $old, true );
		}
		self::$last_result = $log;
		return is_wp_error( $reply ) ? $reply : array_merge( $log, array( 'content' => $reply ) );
	}

	private static function chat_unlogged( array $messages, array $args ) {
		if ( isset( $args['provider'] ) ) {
			if ( ! isset( self::providers()[ $args['provider'] ] ) ) {
				return new WP_Error( 'imajiner_ai_no_provider', __( 'Unknown AI provider.', 'imajiner-editor' ), array( 'status' => 400 ) );
			}
			$model = isset( $args['model'] ) ? $args['model'] : self::providers()[ $args['provider'] ]['default_model'];
			return self::chat_with( $args['provider'], $model, $messages, $args );
		}

		$primary = self::slot( 'primary' );
		if ( '' === $primary['provider'] ) {
			return new WP_Error( 'imajiner_ai_no_provider', __( 'Choose an AI model in Settings → Imajiner Editor.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}

		$reply = self::chat_with( $primary['provider'], $primary['model'], $messages, $args );
		if ( ! is_wp_error( $reply ) ) {
			return $reply;
		}

		$fallback = self::slot( 'fallback' );
		if ( '' === $fallback['provider'] || $fallback === $primary ) {
			return $reply;
		}

		$second = self::chat_with( $fallback['provider'], $fallback['model'], $messages, $args );
		if ( is_wp_error( $second ) ) {
			return new WP_Error(
				$second->get_error_code(),
				/* translators: 1: primary model error, 2: fallback model error. */
				sprintf( __( 'Primary model: %1$s Fallback model: %2$s', 'imajiner-editor' ), $reply->get_error_message(), $second->get_error_message() ),
				$second->get_error_data()
			);
		}
		return $second;
	}

	/**
	 * Sends a chat to one provider and model.
	 *
	 * @param string  $provider Provider id.
	 * @param string  $model    Model id.
	 * @param array[] $messages Messages.
	 * @param array   $args     Optional: max_tokens, timeout.
	 * @return string|WP_Error Reply text.
	 */
	private static function chat_with( $provider, $model, array $messages, array $args ) {
		self::$response_meta = array();
		$reply = self::chat_with_raw( $provider, $model, $messages, $args );
		self::$attempts[] = array( 'provider' => $provider, 'requested_model' => self::safe_model( $model ), 'model' => isset( self::$response_meta['model'] ) ? self::$response_meta['model'] : '', 'usage' => isset( self::$response_meta['usage'] ) ? self::$response_meta['usage'] : null, 'success' => ! is_wp_error( $reply ), 'error' => is_wp_error( $reply ) ? sanitize_key( $reply->get_error_code() ) : '' );
		return $reply;
	}

	private static function chat_with_raw( $provider, $model, array $messages, array $args ) {
		if ( ! isset( self::providers()[ $provider ] ) ) {
			return new WP_Error( 'imajiner_ai_no_provider', __( 'Unknown AI provider.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}

		if ( '' === self::get_api_key( $provider ) ) {
			/* translators: %s: provider name. */
			return new WP_Error( 'imajiner_ai_no_key', sprintf( __( 'No API key is set for %s.', 'imajiner-editor' ), self::providers()[ $provider ]['label'] ), array( 'status' => 400 ) );
		}

		if ( '' === $model ) {
			return new WP_Error( 'imajiner_ai_no_model', __( 'Choose a model in Settings → Imajiner Editor.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}

		$max_tokens = isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : 16000;
		$timeout    = min( 90, max( 1, isset( $args['timeout'] ) ? (int) $args['timeout'] : 90 ) );

		if ( 'anthropic' === self::providers()[ $provider ]['api'] ) {
			return self::chat_anthropic( $provider, $model, $messages, $max_tokens, $timeout );
		}

		$body = array(
			'model'                                           => $model,
			'messages'                                        => $messages,
			self::providers()[ $provider ]['max_tokens_param'] => $max_tokens,
		);

		$data = self::request( $provider, 'POST', '/chat/completions', $body, $timeout );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! isset( $data['choices'][0]['message']['content'] ) || ! is_string( $data['choices'][0]['message']['content'] ) || '' === trim( $data['choices'][0]['message']['content'] ) || ! empty( $data['choices'][0]['message']['refusal'] ) || in_array( isset( $data['choices'][0]['finish_reason'] ) ? $data['choices'][0]['finish_reason'] : '', array( 'length', 'content_filter' ), true ) ) {
			return new WP_Error( 'imajiner_ai_bad_response', __( 'The AI provider sent a response the editor doesn’t understand.', 'imajiner-editor' ), array( 'status' => 502 ) );
		}

		return (string) $data['choices'][0]['message']['content'];
	}

	/**
	 * Model ids offered by a provider.
	 *
	 * @param string $provider Provider id.
	 * @return string[]|WP_Error
	 */
	public static function list_models( $provider ) {
		if ( ! isset( self::providers()[ $provider ] ) ) {
			return new WP_Error( 'imajiner_ai_no_provider', __( 'Unknown AI provider.', 'imajiner-editor' ) );
		}
		// Anthropic pages its model list; ask for everything at once.
		$path = 'anthropic' === self::providers()[ $provider ]['api'] ? '/models?limit=100' : '/models';

		$data = self::request( $provider, 'GET', $path, null, 30 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$ids = array();
		foreach ( isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array() as $model ) {
			if ( isset( $model['id'] ) && is_string( $model['id'] ) ) {
				$ids[] = $model['id'];
			}
		}
		sort( $ids );

		return array_slice( $ids, 0, 1000 );
	}

	/**
	 * Sends a chat through Anthropic's Messages API.
	 *
	 * System messages go in the top-level "system" field. Sampling parameters
	 * are not sent: current Claude models reject them.
	 *
	 * @param string  $provider   Provider id.
	 * @param string  $model      Model id.
	 * @param array[] $messages   Messages.
	 * @param int     $max_tokens Output cap.
	 * @param int     $timeout    Seconds.
	 * @return string|WP_Error Reply text.
	 */
	private static function chat_anthropic( $provider, $model, array $messages, $max_tokens, $timeout ) {
		$system = array();
		$turns  = array();
		foreach ( $messages as $message ) {
			if ( 'system' === $message['role'] ) {
				$system[] = $message['content'];
			} else {
				if ( is_array( $message['content'] ) ) {
					$blocks = array();
					foreach ( $message['content'] as $part ) {
						if ( 'text' === $part['type'] ) {
							$blocks[] = array( 'type' => 'text', 'text' => $part['text'] );
						} else {
							$url = $part['image_url']['url'];
							$source = array( 'type' => 'url', 'url' => $url );
							if ( preg_match( '~^data:(image/(?:png|jpeg|webp));base64,(.+)$~sD', $url, $image ) ) {
								$source = array( 'type' => 'base64', 'media_type' => $image[1], 'data' => $image[2] );
							}
							$blocks[] = array( 'type' => 'image', 'source' => $source );
						}
					}
					$message['content'] = $blocks;
				}
				$turns[] = $message;
			}
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'messages'   => $turns,
		);
		if ( $system ) {
			$body['system'] = implode( "\n\n", $system );
		}

		$headers = array();
		if ( in_array( $model, self::ANTHROPIC_FALLBACK_MODELS, true ) ) {
			$body['fallbacks'] = 'default';
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}
		$data = self::request( $provider, 'POST', '/messages', $body, $timeout, $headers );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// A decline is a normal 200 response: check before reading content.
		if ( isset( $data['stop_reason'] ) && in_array( $data['stop_reason'], array( 'refusal', 'max_tokens' ), true ) ) {
			return new WP_Error( 'imajiner_ai_refused', __( 'The model declined this request.', 'imajiner-editor' ), array( 'status' => 422 ) );
		}

		if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
			return new WP_Error( 'imajiner_ai_bad_response', __( 'The AI provider sent a response the editor doesn’t understand.', 'imajiner-editor' ), array( 'status' => 502 ) );
		}

		$text = '';
		foreach ( $data['content'] as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}
		return '' !== trim( $text ) ? $text : new WP_Error( 'imajiner_ai_bad_response', __( 'The model returned no text.', 'imajiner-editor' ), array( 'status' => 502 ) );
	}

	/**
	 * Calls a provider's API.
	 *
	 * @param string     $provider Provider id.
	 * @param string     $method   HTTP method.
	 * @param string     $path     Path after the base URL, e.g. "/models".
	 * @param array|null $body     JSON body.
	 * @param int        $timeout  Seconds.
	 * @param array      $extra    Extra headers.
	 * @return array|WP_Error Decoded JSON.
	 */
	private static function request( $provider, $method, $path, $body, $timeout, array $extra = array() ) {
		$timeout = apply_filters( 'imajiner_ai_request_timeout', $timeout, array( 'timeout' => $timeout ) );
		if ( is_wp_error( $timeout ) ) {
			return $timeout;
		}
		if ( ! is_numeric( $timeout ) || $timeout < 1 ) {
			return new WP_Error( 'imajiner_ai_deadline', __( 'The AI request reached its time limit. Try again.', 'imajiner-editor' ), array( 'status' => 504 ) );
		}
		$timeout = min( 90, (int) $timeout );
		$key = self::get_api_key( $provider );
		if ( '' === $key ) {
			return new WP_Error( 'imajiner_ai_no_key', __( 'No API key is set for this provider.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}

		$base = self::base_url( $provider );
		if ( '' === $base ) {
			return new WP_Error( 'imajiner_ai_base_url', __( 'No base URL is set for this provider.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}

		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
		if ( 'anthropic' === self::providers()[ $provider ]['api'] ) {
			$headers['x-api-key']         = $key;
			$headers['anthropic-version'] = self::ANTHROPIC_VERSION;
		} else {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		if ( 'openrouter' === $provider ) {
			// Optional OpenRouter attribution headers.
			$headers['HTTP-Referer'] = home_url( '/' );
			$headers['X-Title']      = 'Imajiner Editor';
		}

		$response = wp_remote_request(
			$base . $path,
			array(
				'method'  => $method,
				'headers' => array_merge( $headers, $extra ),
				'body'    => null === $body ? null : wp_json_encode( $body ),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'imajiner_ai_unreachable', self::scrub( $response->get_error_message(), $key ), array( 'status' => 502 ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			// Gemini wraps its error in a list.
			$error   = isset( $data[0] ) && is_array( $data[0] ) ? $data[0] : $data;
			$message = '';
			if ( isset( $error['error']['message'] ) && is_string( $error['error']['message'] ) ) {
				$message = $error['error']['message'];
			} elseif ( isset( $error['error'] ) && is_string( $error['error'] ) ) {
				$message = $error['error'];
			} elseif ( isset( $error['detail'] ) && is_string( $error['detail'] ) ) {
				$message = $error['detail'];
			} elseif ( isset( $error['message'] ) && is_string( $error['message'] ) ) {
				$message = $error['message'];
			}
			return new WP_Error(
				'imajiner_ai_http',
				/* translators: 1: HTTP status code, 2: error message from the provider. */
				self::scrub( sprintf( __( 'The AI provider returned an error (%1$d): %2$s', 'imajiner-editor' ), $status, $message ? $message : wp_remote_retrieve_response_message( $response ) ), $key ),
				array( 'status' => 502 )
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'imajiner_ai_bad_response', __( 'The AI provider did not return JSON. Check the base URL.', 'imajiner-editor' ), array( 'status' => 502 ) );
		}

		if ( in_array( $path, array( '/messages', '/chat/completions' ), true ) ) {
			$usage = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();
			$tokens = array();
			foreach ( array( 'prompt_tokens', 'completion_tokens', 'total_tokens', 'input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens' ) as $field ) {
				if ( isset( $usage[ $field ] ) && is_numeric( $usage[ $field ] ) ) {
					$tokens[ $field ] = max( 0, (int) $usage[ $field ] );
				}
			}
			self::$response_meta = array( 'model' => isset( $data['model'] ) && is_string( $data['model'] ) ? self::safe_model( $data['model'] ) : '', 'usage' => $tokens ?: null );
		}
		return $data;
	}

	private static function safe_model( $model ) {
		foreach ( array_keys( self::providers() ) as $provider ) {
			$key = self::get_api_key( $provider );
			if ( $key ) {
				$model = str_replace( $key, '[redacted]', $model );
			}
		}
		return preg_replace( '/[^a-zA-Z0-9._:\/-]/', '', substr( $model, 0, 150 ) );
	}

	private static function prepare_messages( array $messages ) {
		$system = Imajiner_Prompts::system_prompt();
		$prepared = array( array( 'role' => 'system', 'content' => $system ) );
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] ) || ! in_array( $message['role'], array( 'system', 'user', 'assistant' ), true ) ) {
				return new WP_Error( 'imajiner_ai_message', __( 'Invalid AI message.', 'imajiner-editor' ) );
			}
			if ( 'system' === $message['role'] && $system === $message['content'] ) {
				continue;
			}
			$content = $message['content'];
			if ( is_array( $content ) && 'user' === $message['role'] && $content ) {
				foreach ( $content as $part ) {
					$valid = is_array( $part ) && isset( $part['type'] ) && ( ( 'text' === $part['type'] && isset( $part['text'] ) && is_string( $part['text'] ) ) || ( 'image_url' === $part['type'] && isset( $part['image_url']['url'] ) && is_string( $part['image_url']['url'] ) && self::image_url( $part['image_url']['url'] ) ) );
					if ( ! $valid ) {
						return new WP_Error( 'imajiner_ai_image', __( 'Use text, public HTTPS images or verified bounded image data only.', 'imajiner-editor' ) );
					}
				}
			} elseif ( ! is_string( $content ) ) {
				return new WP_Error( 'imajiner_ai_message', __( 'Invalid AI message content.', 'imajiner-editor' ) );
			}
			$prepared[] = array( 'role' => $message['role'], 'content' => $content );
		}
		return $prepared;
	}

	public static function image_url( $url ) {
		if ( is_string( $url ) && strlen( $url ) <= 7000000 && preg_match( '~^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=]+)$~D', $url, $image ) ) {
			$bytes = base64_decode( $image[2], true );
			$info = false !== $bytes ? @getimagesizefromstring( $bytes ) : false;
			return $info && strlen( $bytes ) <= 5242880 && $info['mime'] === $image[1] && $info[0] * $info[1] <= 50000000;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && 'https' === strtolower( $parts['scheme'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && (bool) wp_http_validate_url( $url );
	}

	/**
	 * Removes the key from text that may be shown or logged, in case a provider echoes it.
	 *
	 * @param string $text Text.
	 * @param string $key  API key.
	 * @return string
	 */
	private static function scrub( $text, $key ) {
		return mb_substr( str_replace( $key, '[API key]', $text ), 0, 500 );
	}
}
