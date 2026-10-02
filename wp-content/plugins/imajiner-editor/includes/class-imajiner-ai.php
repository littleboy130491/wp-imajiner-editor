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

	private static $attempts = array();
	private static $response_meta = array();
	private static $last_usage = array();

	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( Imajiner_Rest::NAMESPACE_V1, '/ai/usage', array( 'methods' => 'GET', 'permission_callback' => array( 'Imajiner_Generation', 'can_generate' ), 'callback' => function () { return rest_ensure_response( get_option( 'imajiner_ai_usage_' . get_current_user_id(), array() ) ); } ) );
		} );
	}

	public static function last_usage() {
		return self::$last_usage;
	}

	public static function smoke_from_env() {
		if ( 'cli' !== PHP_SAPI || '1' !== getenv( 'IMAJINER_AI_SMOKE' ) ) return new WP_Error( 'imajiner_smoke_disabled', 'Opt in with IMAJINER_AI_SMOKE=1 in a CLI process.' );
		$results = array();
		foreach ( self::providers() as $id => $provider ) {
			$key = getenv( $provider['constant'] );
			if ( ! $key ) { $results[ $id ] = array( 'state' => 'skipped', 'reason' => 'missing_env_key' ); continue; }
			if ( defined( $provider['constant'] ) ) { $results[ $id ] = array( 'state' => 'skipped', 'reason' => 'constant_already_defined' ); continue; }
			define( $provider['constant'], $key );
			$models = self::list_models( $id );
			if ( is_wp_error( $models ) ) { $results[ $id ] = array( 'state' => 'failed', 'error' => $models->get_error_code() ); continue; }
			$model = getenv( 'IMAJINER_' . strtoupper( $id ) . '_MODEL' );
			if ( ! $model ) { $results[ $id ] = array( 'state' => 'skipped', 'models_count' => count( $models ), 'reason' => 'missing_env_model' ); continue; }
			$reply = self::chat( array( array( 'role' => 'user', 'content' => 'Reply with the single word OK.' ) ), array( 'provider' => $id, 'model' => $model, 'max_tokens' => 1024, 'timeout' => 30 ) );
			$results[ $id ] = array( 'state' => is_wp_error( $reply ) ? 'failed' : 'complete', 'models_count' => count( $models ), 'error' => is_wp_error( $reply ) ? $reply->get_error_code() : null, 'usage' => self::last_usage() );
		}
		return $results;
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
				'key_url'       => 'https://platform.claude.com/settings/keys',
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
				'label'    => 'Meta Model API',
				'base_url' => 'https://api.meta.ai/v1',
				'key_url'  => 'https://dev.meta.ai/docs/authentication',
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
		$url   = null !== $fixed ? $fixed : self::get_settings()['providers'][ $provider ]['base_url'];
		return untrailingslashit( $url );
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
		$system = Imajiner_Prompts::system_prompt();
		$messages = array_values( array_filter( $messages, function ( $message ) use ( $system ) { return ! ( isset( $message['role'], $message['content'] ) && 'system' === $message['role'] && $system === $message['content'] ); } ) );
		array_unshift( $messages, array( 'role' => 'system', 'content' => $system ) );
		self::$attempts = array();
		$valid = self::validate_messages( $messages );
		$reply = is_wp_error( $valid ) ? $valid : self::chat_impl( $messages, $args );
		self::$last_usage = array( 'id' => wp_generate_uuid4(), 'time' => time(), 'status' => is_wp_error( $reply ) ? 'failed' : 'complete', 'attempts' => self::$attempts, 'fallback_attempts' => max( 0, count( self::$attempts ) - 1 ), 'answered' => is_wp_error( $reply ) || ! self::$attempts ? null : end( self::$attempts ) );
		$option = 'imajiner_ai_usage_' . get_current_user_id();
		$logs = get_option( $option, array() );
		$logs = array_values( array_filter( is_array( $logs ) ? $logs : array(), function ( $entry ) { return $entry['time'] > time() - 30 * DAY_IN_SECONDS; } ) );
		$logs[] = self::$last_usage;
		update_option( $option, array_slice( $logs, -100 ), false );
		return $reply;
	}

	private static function validate_messages( array $messages ) {
		foreach ( $messages as $message ) {
			if ( ! isset( $message['role'], $message['content'] ) || ! in_array( $message['role'], array( 'system', 'user', 'assistant' ), true ) ) return new WP_Error( 'imajiner_ai_message', __( 'Invalid AI message.', 'imajiner-editor' ) );
			if ( is_string( $message['content'] ) ) continue;
			if ( 'user' !== $message['role'] || ! is_array( $message['content'] ) ) return new WP_Error( 'imajiner_ai_message', __( 'Invalid AI message content.', 'imajiner-editor' ) );
			foreach ( $message['content'] as $part ) {
				if ( isset( $part['type'], $part['text'] ) && 'text' === $part['type'] && is_string( $part['text'] ) ) continue;
				$url = isset( $part['image_url']['url'] ) && is_string( $part['image_url']['url'] ) ? $part['image_url']['url'] : '';
				$id = attachment_url_to_postid( $url );
				if ( ! isset( $part['type'] ) || 'image_url' !== $part['type'] || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! $id || ! wp_attachment_is_image( $id ) ) return new WP_Error( 'imajiner_ai_image', __( 'Use an HTTPS image from the media library.', 'imajiner-editor' ) );
			}
		}
		return true;
	}

	private static function chat_impl( array $messages, array $args ) {
		if ( isset( $args['provider'] ) ) {
			if ( ! isset( self::providers()[ $args['provider'] ] ) ) return new WP_Error( 'imajiner_ai_no_provider', __( 'Unknown AI provider.', 'imajiner-editor' ) );
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
		$reply = self::chat_with_impl( $provider, $model, $messages, $args );
		self::$attempts[] = array( 'provider' => $provider, 'requested_model' => $model, 'model' => isset( self::$response_meta['model'] ) ? self::$response_meta['model'] : null, 'tokens' => isset( self::$response_meta['tokens'] ) ? self::$response_meta['tokens'] : null, 'status' => is_wp_error( $reply ) ? 'failed' : 'complete', 'error' => is_wp_error( $reply ) ? $reply->get_error_code() : null );
		return $reply;
	}

	private static function chat_with_impl( $provider, $model, array $messages, array $args ) {
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
		$timeout    = isset( $args['timeout'] ) ? min( 60, max( 1, (int) $args['timeout'] ) ) : 60;

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

		if ( ! isset( $data['choices'][0]['message'] ) || ! array_key_exists( 'content', $data['choices'][0]['message'] ) ) {
			return new WP_Error( 'imajiner_ai_bad_response', __( 'The AI provider sent a response the editor doesn’t understand.', 'imajiner-editor' ), array( 'status' => 502 ) );
		}

		if ( ! is_string( $data['choices'][0]['message']['content'] ) || ! empty( $data['choices'][0]['message']['refusal'] ) || ( isset( $data['choices'][0]['finish_reason'] ) && 'length' === $data['choices'][0]['finish_reason'] ) ) return new WP_Error( 'imajiner_ai_incomplete', __( 'The AI response was refused or incomplete.', 'imajiner-editor' ) );
		return $data['choices'][0]['message']['content'];
	}

	/**
	 * Model ids offered by a provider.
	 *
	 * @param string $provider Provider id.
	 * @return string[]|WP_Error
	 */
	public static function list_models( $provider ) {
		if ( ! isset( self::providers()[ $provider ] ) ) return new WP_Error( 'imajiner_ai_no_provider', __( 'Unknown AI provider.', 'imajiner-editor' ) );
		// Anthropic pages its model list; ask for everything at once.
		$path = 'anthropic' === self::providers()[ $provider ]['api'] ? '/models?limit=1000' : '/models';

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
					$message['content'] = array_map( function ( $part ) {
						return 'text' === $part['type'] ? array( 'type' => 'text', 'text' => $part['text'] ) : array( 'type' => 'image', 'source' => array( 'type' => 'url', 'url' => $part['image_url']['url'] ) );
					}, $message['content'] );
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

		$data = self::request( $provider, 'POST', '/messages', $body, $timeout );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// A decline is a normal 200 response: check before reading content.
		if ( isset( $data['stop_reason'] ) && 'refusal' === $data['stop_reason'] ) {
			return new WP_Error( 'imajiner_ai_refused', __( 'The model declined this request.', 'imajiner-editor' ), array( 'status' => 422 ) );
		}
		if ( isset( $data['stop_reason'] ) && 'max_tokens' === $data['stop_reason'] ) return new WP_Error( 'imajiner_ai_incomplete', __( 'The AI response was incomplete.', 'imajiner-editor' ) );

		if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
			return new WP_Error( 'imajiner_ai_bad_response', __( 'The AI provider sent a response the editor doesn’t understand.', 'imajiner-editor' ), array( 'status' => 502 ) );
		}

		$text = '';
		foreach ( $data['content'] as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}
		return $text;
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
				'redirection' => 0,
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
		if ( 'POST' === $method ) {
			$tokens = array();
			foreach ( array( 'prompt_tokens', 'completion_tokens', 'total_tokens', 'input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens' ) as $field ) {
				if ( isset( $data['usage'][ $field ] ) && is_numeric( $data['usage'][ $field ] ) ) $tokens[ $field ] = max( 0, (int) $data['usage'][ $field ] );
			}
			self::$response_meta = array( 'model' => isset( $data['model'] ) && is_string( $data['model'] ) ? self::scrub( sanitize_text_field( $data['model'] ), $key ) : null, 'tokens' => $tokens ?: null );
		}

		return $data;
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
