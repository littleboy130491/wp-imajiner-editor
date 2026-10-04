<?php
/**
 * Settings → Imajiner Editor: AI model and fallback, system prompt, provider keys.
 *
 * Keys are write-only: the form never contains a saved key, only its last
 * characters. Leaving a key field empty keeps the saved key.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen.
 */
class Imajiner_Settings {

	const PAGE   = 'imajiner-editor';
	const ACTION = 'imajiner_save_ai_settings';

	/**
	 * Longest accepted system prompt addition, in characters.
	 */
	const MAX_APPEND = 20000;

	/**
	 * Hooks the screen, the save handler and the REST routes.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( IMAJINER_EDITOR_DIR . 'imajiner-editor.php' ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Adds the page under Settings.
	 */
	public static function add_page() {
		$hook = add_options_page(
			__( 'Imajiner Editor', 'imajiner-editor' ),
			__( 'Imajiner Editor', 'imajiner-editor' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
		add_action( 'admin_print_scripts-' . $hook, array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Adds a "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ), esc_html__( 'Settings', 'imajiner-editor' ) ) );
		return $links;
	}

	/**
	 * Loads the script for "Load models" and "Test".
	 */
	public static function enqueue_assets() {
		wp_enqueue_script( 'imajiner-settings', IMAJINER_EDITOR_URL . 'assets/js/settings.js', array( 'wp-api-fetch' ), IMAJINER_EDITOR_VERSION, true );
		wp_localize_script(
			'imajiner-settings',
			'imajinerSettings',
			array(
				'defaultModels' => wp_list_pluck( Imajiner_AI::providers(), 'default_model' ),
			)
		);
	}

	/**
	 * Registers the routes used by the settings screen.
	 */
	public static function register_routes() {
		register_rest_route(
			Imajiner_Rest::NAMESPACE_V1,
			'/ai/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_test' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'slot' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'primary', 'fallback' ),
					),
				),
			)
		);

		register_rest_route(
			Imajiner_Rest::NAMESPACE_V1,
			'/ai/models',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_models' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'provider' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array_keys( Imajiner_AI::providers() ),
					),
				),
			)
		);
	}

	/**
	 * Only administrators manage AI settings.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Sends a tiny request to the saved primary or fallback model.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_test( WP_REST_Request $request ) {
		$slot = Imajiner_AI::slot( $request['slot'] );
		if ( '' === $slot['provider'] ) {
			return new WP_Error( 'imajiner_ai_no_provider', __( 'Choose a provider and save first.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}

		$reply = Imajiner_AI::chat(
			array(
				array(
					'role'    => 'user',
					'content' => 'Reply with the single word OK.',
				),
			),
			array(
				'provider'   => $slot['provider'],
				'model'      => $slot['model'],
				// Room for models that think before answering.
				'max_tokens' => 1024,
				'timeout'    => 30,
			)
		);

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		return rest_ensure_response(
			array(
				'model' => Imajiner_AI::last_result()['model'] ?: $slot['model'],
				'usage' => Imajiner_AI::last_result()['usage'],
				'reply' => mb_substr( trim( $reply ), 0, 100 ),
			)
		);
	}

	/**
	 * Lists a provider's models.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_models( WP_REST_Request $request ) {
		$models = Imajiner_AI::list_models( $request['provider'] );
		return is_wp_error( $models ) ? $models : rest_ensure_response( $models );
	}

	/**
	 * Saves the form. Keys are encrypted here and never echoed back.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'imajiner-editor' ), 403 );
		}
		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized below.
		$input     = isset( $_POST['imajiner_ai'] ) && is_array( $_POST['imajiner_ai'] ) ? wp_unslash( $_POST['imajiner_ai'] ) : array();
		$settings  = Imajiner_AI::get_settings();
		$providers = Imajiner_AI::providers();
		$notices   = array();

		foreach ( array( 'primary', 'fallback' ) as $slot ) {
			$fields   = isset( $input[ $slot ] ) && is_array( $input[ $slot ] ) ? $input[ $slot ] : array();
			$provider = isset( $fields['provider'] ) ? sanitize_key( $fields['provider'] ) : '';

			$settings[ $slot ] = array(
				'provider' => isset( $providers[ $provider ] ) ? $provider : '',
				'model'    => isset( $fields['model'] ) ? mb_substr( sanitize_text_field( $fields['model'] ), 0, 200 ) : '',
			);
		}

		if ( isset( $input['system_prompt_append'] ) ) {
			// Stored as written: it is prompt text (it may show HTML examples) and is only ever output escaped.
			$append = wp_check_invalid_utf8( str_replace( "\r\n", "\n", (string) $input['system_prompt_append'] ) );
			if ( mb_strlen( $append ) > self::MAX_APPEND ) {
				$append    = mb_substr( $append, 0, self::MAX_APPEND );
				$notices[] = array( 'warning', __( 'The additional instructions were too long and have been shortened.', 'imajiner-editor' ) );
			}
			$settings['system_prompt_append'] = trim( $append );
		}

		foreach ( $providers as $id => $provider ) {
			$fields  = isset( $input['providers'][ $id ] ) && is_array( $input['providers'][ $id ] ) ? $input['providers'][ $id ] : array();
			$current = $settings['providers'][ $id ];
			$new_key = isset( $fields['api_key'] ) ? trim( (string) $fields['api_key'] ) : '';

			if ( null === $provider['base_url'] && isset( $fields['base_url'] ) ) {
				$base_url = trim( (string) $fields['base_url'] );
				if ( '' === $base_url ) {
					$current['base_url'] = '';
				} else {
					$valid = Imajiner_AI::validate_base_url( $base_url );
					if ( is_wp_error( $valid ) ) {
						$notices[] = array( 'error', $provider['label'] . ': ' . $valid->get_error_message() );
					} else {
						// A saved key must not follow the URL to a different server without being re-entered.
						$old_host = wp_parse_url( $current['base_url'], PHP_URL_HOST );
						if ( '' !== $current['key'] && '' === $new_key && $old_host && wp_parse_url( $valid, PHP_URL_HOST ) !== $old_host ) {
							$current['key'] = '';
							$notices[]      = array( 'warning', $provider['label'] . ': ' . __( 'The base URL now points to a different server, so the saved key was removed. Enter the key for the new server.', 'imajiner-editor' ) );
						}
						$current['base_url'] = $valid;
					}
				}
			}

			if ( ! empty( $fields['remove_key'] ) ) {
				$current['key'] = '';
			} elseif ( '' !== $new_key ) {
				if ( strlen( $new_key ) > 500 || preg_match( '/[^\x21-\x7E]/', $new_key ) ) {
					$notices[] = array( 'error', $provider['label'] . ': ' . __( 'That doesn’t look like an API key (it has spaces or unusual characters), so it wasn’t saved.', 'imajiner-editor' ) );
				} else {
					$current['key'] = Imajiner_Secrets::encrypt( $new_key );
				}
			}

			$settings['providers'][ $id ] = $current;
		}

		Imajiner_AI::save_settings( $settings );

		// Point out a chosen model whose provider can't be used yet.
		foreach ( array( 'primary', 'fallback' ) as $slot ) {
			$id = $settings[ $slot ]['provider'];
			if ( '' !== $id && 'missing' === Imajiner_AI::key_status( $id )['state'] ) {
				$notices[] = array(
					'warning',
					sprintf(
						/* translators: 1: "Primary" or "Fallback", 2: provider name. */
						__( '%1$s model: add an API key for %2$s below.', 'imajiner-editor' ),
						'primary' === $slot ? __( 'Primary', 'imajiner-editor' ) : __( 'Fallback', 'imajiner-editor' ),
						$providers[ $id ]['label']
					),
				);
			}
		}

		$had_errors = in_array( 'error', array_column( $notices, 0 ), true );
		$notices[]  = array( 'success', $had_errors ? __( 'Your other changes were saved.', 'imajiner-editor' ) : __( 'Settings saved.', 'imajiner-editor' ) );
		set_transient( 'imajiner_settings_notices_' . get_current_user_id(), $notices, MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Renders the settings screen.
	 */
	public static function render() {
		$settings  = Imajiner_AI::get_settings();
		$providers = Imajiner_AI::providers();
		$notice_id = 'imajiner_settings_notices_' . get_current_user_id();
		$notices   = get_transient( $notice_id );
		delete_transient( $notice_id );

		$slots = array(
			'primary'  => array( __( 'Primary model', 'imajiner-editor' ), __( 'Used for every AI request.', 'imajiner-editor' ) ),
			'fallback' => array( __( 'Fallback model', 'imajiner-editor' ), __( 'Used when the primary model fails, times out or declines. A different provider keeps working if one has an outage.', 'imajiner-editor' ) ),
		);
		$labels = array(
			'constant'   => __( 'Key in wp-config.php', 'imajiner-editor' ),
			'saved'      => __( 'Key saved', 'imajiner-editor' ),
			'unreadable' => __( 'Key needs re-entering', 'imajiner-editor' ),
			'missing'    => __( 'No key', 'imajiner-editor' ),
		);
		?>
		<style>
			.imajiner-provider-box { margin: 0 0 12px; padding: 0 16px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; max-width: 960px; }
			.imajiner-provider-box summary { display: flex; align-items: center; gap: 8px; padding: 12px 0; cursor: pointer; font-size: 14px; }
			.imajiner-provider-box .form-table { margin-top: 0; }
			.imajiner-badge { padding: 1px 8px; border-radius: 10px; background: #f0f0f1; color: #50575e; font-size: 12px; }
			.imajiner-badge--saved, .imajiner-badge--constant { background: #edfaef; color: #00701a; }
			.imajiner-badge--unreadable { background: #fcf0f1; color: #b32d2e; }
			.imajiner-model-row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
			.imajiner-prompt { width: 100%; max-width: 960px; font-family: Consolas, Monaco, monospace; font-size: 12px; }
		</style>
		<div class="wrap">
			<h1><?php esc_html_e( 'Imajiner Editor', 'imajiner-editor' ); ?></h1>

			<?php foreach ( is_array( $notices ) ? $notices : array() as $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endforeach; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>

				<h2><?php esc_html_e( 'AI model', 'imajiner-editor' ); ?></h2>
				<p><?php esc_html_e( 'Used to create templates from a prompt and to normalize existing templates.', 'imajiner-editor' ); ?></p>
				<table class="form-table" role="presentation">
					<?php foreach ( $slots as $slot => $text ) : ?>
						<?php $value = $settings[ $slot ]; ?>
						<tr class="imajiner-slot" data-slot="<?php echo esc_attr( $slot ); ?>">
							<th scope="row"><label for="imajiner-<?php echo esc_attr( $slot ); ?>-provider"><?php echo esc_html( $text[0] ); ?></label></th>
							<td>
								<div class="imajiner-model-row">
									<select id="imajiner-<?php echo esc_attr( $slot ); ?>-provider" name="imajiner_ai[<?php echo esc_attr( $slot ); ?>][provider]" class="imajiner-slot-provider">
										<option value=""><?php echo 'fallback' === $slot ? esc_html__( 'No fallback', 'imajiner-editor' ) : esc_html__( 'Choose a provider', 'imajiner-editor' ); ?></option>
										<?php foreach ( $providers as $id => $provider ) : ?>
											<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $value['provider'], $id ); ?>><?php echo esc_html( $provider['label'] . ( 'missing' === Imajiner_AI::key_status( $id )['state'] ? ' ' . __( '(no key yet)', 'imajiner-editor' ) : '' ) ); ?></option>
										<?php endforeach; ?>
									</select>
									<input type="text" class="regular-text code imajiner-slot-model" name="imajiner_ai[<?php echo esc_attr( $slot ); ?>][model]" value="<?php echo esc_attr( $value['model'] ); ?>"
										list="imajiner-<?php echo esc_attr( $slot ); ?>-models" spellcheck="false" aria-label="<?php esc_attr_e( 'Model', 'imajiner-editor' ); ?>"
										placeholder="<?php echo esc_attr( '' !== $value['provider'] && $providers[ $value['provider'] ]['default_model'] ? $providers[ $value['provider'] ]['default_model'] : __( 'Model id', 'imajiner-editor' ) ); ?>">
									<datalist id="imajiner-<?php echo esc_attr( $slot ); ?>-models"></datalist>
									<button type="button" class="button imajiner-load-models"><?php esc_html_e( 'Load models', 'imajiner-editor' ); ?></button>
									<button type="button" class="button imajiner-test"><?php esc_html_e( 'Test', 'imajiner-editor' ); ?></button>
									<span class="imajiner-result" role="status" aria-live="polite"></span>
								</div>
								<p class="description"><?php echo esc_html( $text[1] ); ?> <?php esc_html_e( 'Load models and Test use the saved settings: save first after changing a provider, key or model.', 'imajiner-editor' ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'System prompt', 'imajiner-editor' ); ?></h2>
				<p style="max-width:960px"><?php esc_html_e( 'Every AI request starts with a built-in prompt that explains how to build pages for the Imajiner theme and editor: file locations, section markers, what stays editable, CSS scoping, design tokens and breakpoints. It lists the theme’s current tokens automatically.', 'imajiner-editor' ); ?></p>
				<details style="max-width:960px;margin-bottom:8px">
					<summary style="cursor:pointer"><?php esc_html_e( 'Show the built-in prompt', 'imajiner-editor' ); ?></summary>
					<textarea class="imajiner-prompt" rows="24" readonly aria-label="<?php esc_attr_e( 'Built-in system prompt', 'imajiner-editor' ); ?>"><?php echo esc_textarea( Imajiner_Prompts::default_system_prompt() ); ?></textarea>
				</details>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="imajiner-system-append"><?php esc_html_e( 'Additional instructions', 'imajiner-editor' ); ?></label></th>
						<td>
							<textarea id="imajiner-system-append" class="imajiner-prompt" rows="8" name="imajiner_ai[system_prompt_append]" placeholder="<?php esc_attr_e( 'e.g. The brand voice is friendly and direct. Use British English. Every page ends with a contact call-to-action section.', 'imajiner-editor' ); ?>"><?php echo esc_textarea( $settings['system_prompt_append'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Added after the built-in prompt for this site: brand voice, language, recurring sections, things to avoid.', 'imajiner-editor' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Providers and API keys', 'imajiner-editor' ); ?></h2>
				<p><?php esc_html_e( 'API keys are encrypted before they are stored and are never shown again. Add a key for each provider you choose above.', 'imajiner-editor' ); ?></p>

				<?php foreach ( $providers as $id => $provider ) : ?>
					<?php
					$values = $settings['providers'][ $id ];
					$status = Imajiner_AI::key_status( $id );
					$field  = 'imajiner_ai[providers][' . $id . ']';
					$used   = in_array( $id, array( $settings['primary']['provider'], $settings['fallback']['provider'] ), true );
					?>
					<details class="imajiner-provider-box" <?php echo ( $used || 'missing' !== $status['state'] ) ? 'open' : ''; ?>>
						<summary>
							<strong><?php echo esc_html( $provider['label'] ); ?></strong>
							<span class="imajiner-badge imajiner-badge--<?php echo esc_attr( $status['state'] ); ?>"><?php echo esc_html( $labels[ $status['state'] ] ); ?></span>
						</summary>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php echo null === $provider['base_url'] ? '<label for="imajiner-' . esc_attr( $id ) . '-base">' . esc_html__( 'Base URL', 'imajiner-editor' ) . '</label>' : esc_html__( 'Endpoint', 'imajiner-editor' ); ?></th>
								<td>
									<?php if ( null === $provider['base_url'] ) : ?>
										<input type="url" class="regular-text code" id="imajiner-<?php echo esc_attr( $id ); ?>-base" name="<?php echo esc_attr( $field ); ?>[base_url]" value="<?php echo esc_attr( $values['base_url'] ); ?>" placeholder="https://api.example.com/v1">
										<p class="description"><?php esc_html_e( 'The URL before /chat/completions. HTTPS required, except for servers on this machine (e.g. http://localhost:11434/v1 for Ollama).', 'imajiner-editor' ); ?></p>
									<?php else : ?>
										<code><?php echo esc_html( $provider['base_url'] ); ?></code>
										<?php if ( 'anthropic' === $provider['api'] ) : ?>
											<p class="description"><?php esc_html_e( 'Uses Anthropic’s Messages API.', 'imajiner-editor' ); ?></p>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="imajiner-<?php echo esc_attr( $id ); ?>-key"><?php esc_html_e( 'API key', 'imajiner-editor' ); ?></label></th>
								<td>
									<?php if ( 'constant' === $status['state'] ) : ?>
										<p>
											<?php
											/* translators: 1: masked key, 2: constant name. */
											printf( esc_html__( 'Set in wp-config.php (%1$s, %2$s).', 'imajiner-editor' ), esc_html( $status['masked'] ), '<code>' . esc_html( $provider['constant'] ) . '</code>' );
											?>
										</p>
									<?php else : ?>
										<input type="password" class="regular-text code" id="imajiner-<?php echo esc_attr( $id ); ?>-key" name="<?php echo esc_attr( $field ); ?>[api_key]" value="" autocomplete="new-password" spellcheck="false"
											placeholder="<?php echo esc_attr( 'saved' === $status['state'] ? __( 'Leave empty to keep the saved key', 'imajiner-editor' ) : __( 'Paste the API key', 'imajiner-editor' ) ); ?>">
										<p class="description">
											<?php if ( 'saved' === $status['state'] ) : ?>
												<?php
												/* translators: %s: masked key. */
												printf( esc_html__( 'Saved key: %s.', 'imajiner-editor' ), '<code>' . esc_html( $status['masked'] ) . '</code>' );
												?>
												<label><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[remove_key]" value="1"> <?php esc_html_e( 'Remove', 'imajiner-editor' ); ?></label>
											<?php elseif ( 'unreadable' === $status['state'] ) : ?>
												<strong><?php esc_html_e( 'The saved key can’t be decrypted (the site’s security keys changed). Enter it again.', 'imajiner-editor' ); ?></strong>
											<?php else : ?>
												<?php esc_html_e( 'No key saved.', 'imajiner-editor' ); ?>
											<?php endif; ?>
											<?php if ( $provider['key_url'] ) : ?>
												<a href="<?php echo esc_url( $provider['key_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get a key', 'imajiner-editor' ); ?></a>.
											<?php endif; ?>
											<?php
											/* translators: %s: constant name. */
											printf( esc_html__( 'Or define %s in wp-config.php to keep it out of the database.', 'imajiner-editor' ), '<code>' . esc_html( $provider['constant'] ) . '</code>' );
											?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						</table>
					</details>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
