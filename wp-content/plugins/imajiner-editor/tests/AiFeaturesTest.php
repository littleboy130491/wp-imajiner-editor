<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-imajiner-ai-jobs.php';
require_once dirname( __DIR__ ) . '/includes/class-imajiner-section-ai.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AiFeaturesTest extends TestCase {
	private static $user;
	private static $other;
	private $settings;
	private $slug;
	private $path;
	private $before;
	private $jobs = array();
	private $proposals = array();
	private $requests = array();
	private $replies = array();
	private $attachments = array();

	public static function setUpBeforeClass(): void {
		Imajiner_AI_Jobs::init();
		Imajiner_Generation::init();
		Imajiner_Section_AI::init();
		Imajiner_AI::init();
		foreach ( array( 'user', 'other' ) as $property ) {
			$id = wp_insert_user( array( 'user_login' => 'imj-ai-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
			self::assertIsInt( $id );
			if ( 'user' === $property ) self::$user = $id;
			else self::$other = $id;
		}
	}

	public static function tearDownAfterClass(): void {
		foreach ( array( self::$user, self::$other ) as $id ) {
			delete_option( 'imajiner_ai_usage_' . $id );
			wp_delete_user( $id );
		}
		wp_clear_scheduled_hook( 'imajiner_ai_job_cleanup' );
	}

	protected function setUp(): void {
		$this->settings = get_option( Imajiner_AI::OPTION, false );
		wp_set_current_user( self::$user );
		update_option( Imajiner_AI::OPTION, array( 'primary' => array( 'provider' => 'openai', 'model' => 'unit-primary' ) ), false );
		$this->slug = 'imj-ai-' . substr( wp_generate_uuid4(), 0, 8 );
		$this->path = get_stylesheet_directory() . '/imajiner/' . $this->slug . '.php';
		$this->before = array( 'php' => self::php( $this->slug ), 'css' => '.imj-' . $this->slug . ' .hero { color: var(--imj-color-primary); }' );
		self::assertTrue( Imajiner_Template_Store::create( $this->path, $this->before ) );
		wp_clean_themes_cache( false );
		add_filter( 'pre_http_request', array( $this, 'http' ), 999, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'http' ), 999 );
		remove_filter( 'upload_dir', array( $this, 'uploads' ) );
		foreach ( $this->jobs as $id ) {
			wp_clear_scheduled_hook( 'imajiner_ai_job_run', array( $id ) );
			wp_delete_post( $id, true );
		}
		foreach ( $this->proposals as $id ) {
			delete_transient( 'imajiner_section_ai_' . self::$user . '_' . $id );
			delete_transient( 'imajiner_ai_' . self::$user . '_' . $id );
		}
		foreach ( Imajiner_Template_Store::get_revisions( $this->path ) as $revision ) wp_delete_post( $revision['id'], true );
		foreach ( array( $this->path, Imajiner_Template_Store::css_path( $this->path ) ) as $file ) if ( file_exists( $file ) ) unlink( $file );
		foreach ( $this->attachments as $id ) wp_delete_attachment( $id, true );
		if ( false === $this->settings ) delete_option( Imajiner_AI::OPTION );
		else update_option( Imajiner_AI::OPTION, $this->settings, false );
		wp_clean_themes_cache( false );
		wp_set_current_user( 0 );
	}

	private static function php( $slug ): string {
		return "<?php\n/**\n * Template Name: $slug\n */\nget_header();\n?>\n<!-- imj:section name=\"hero\" -->\n<section class=\"hero\"><h1>Before</h1><p>Keep me</p></section>\n<!-- /imj:section -->\n<?php get_footer();\n";
	}

	public function http( $preempt, $args, $url ) {
		if ( false !== strpos( $url, 'admin-post.php' ) ) {
			self::assertFalse( $args['blocking'] );
			self::assertLessThanOrEqual( 0.1, $args['timeout'] );
			return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => '' );
		}
		$this->requests[] = array( 'url' => $url, 'method' => $args['method'], 'body' => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null );
		$response = array_shift( $this->replies );
		if ( null === $response ) return new WP_Error( 'unexpected_request', 'Tests intercept every HTTP request.' );
		if ( is_wp_error( $response ) ) return $response;
		if ( is_string( $response ) ) $response = array( 'choices' => array( array( 'message' => array( 'content' => $response ), 'finish_reason' => 'stop' ) ), 'model' => 'unit-answer', 'usage' => array( 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15 ) );
		return array( 'headers' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $response ) );
	}

	private function request( $path, array $data = array(), $method = 'POST' ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/imajiner/v1/ai/' . $path );
		$request->set_body_params( $data );
		$response = rest_do_request( $request );
		$result = $response->get_data();
		if ( isset( $result['job'] ) ) $this->jobs[] = $result['job'];
		return $response;
	}

	private function section(): WP_REST_Response {
		return $this->request( 'section', array( 'key' => $this->slug, 'id' => 'e1', 'hash' => Imajiner_Template_Store::hash( $this->before ), 'prompt' => 'Improve this heading only.' ) );
	}

	private function replacement( $php = '<h1 class="ai-heading">After</h1>', $css = null ): string {
		return wp_json_encode( array( 'php' => $php, 'css' => $css ?? '.imj-' . $this->slug . ' .ai-heading { color: var(--imj-color-secondary); }' ) );
	}

	private function finish( $id ): array {
		Imajiner_AI_Jobs::run( $id );
		$response = $this->request( 'jobs/' . $id, array(), 'GET' );
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		if ( isset( $data['result']['proposal'] ) ) $this->proposals[] = $data['result']['proposal'];
		return $data;
	}

	public function test_generation_returns_queued_without_provider_http_and_normalization_needs_acceptance(): void {
		$php = str_replace( 'Before', 'Normalized', $this->before['php'] );
		$this->replies[] = wp_json_encode( array( 'slug' => $this->slug, 'name' => $this->slug, 'php' => $php, 'css' => $this->before['css'] ) );
		$response = $this->request( 'generate', array( 'key' => $this->slug ) );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'queued', $response->get_data()['state'] );
		self::assertCount( 0, $this->requests );
		$data = $this->finish( $response->get_data()['job'] );
		self::assertSame( 'complete', $data['state'], wp_json_encode( $data ) );
		self::assertSame( $this->before, Imajiner_Template_Store::read( $this->path ) );
		self::assertSame( 200, $this->request( 'accept', array( 'proposal' => $data['result']['proposal'] ) )->get_status() );
		self::assertSame( $php, Imajiner_Template_Store::read( $this->path )['php'] );
		self::assertSame( 410, $this->request( 'accept', array( 'proposal' => $data['result']['proposal'] ) )->get_status() );
	}

	public function test_selected_edit_retries_once_then_saves_only_selected_bytes_with_a_revision(): void {
		$this->replies = array( $this->replacement( '<h1>Bad</h1><p>Extra root</p>', '' ), $this->replacement() );
		$id = $this->section()->get_data()['job'];
		self::assertCount( 0, $this->requests );
		$data = $this->finish( $id );
		self::assertSame( 'complete', $data['state'], wp_json_encode( $data ) );
		self::assertCount( 2, $this->requests );
		self::assertStringContainsString( 'replacement node', $this->requests[1]['body']['messages'][3]['content'] );
		self::assertSame( $this->before, Imajiner_Template_Store::read( $this->path ) );
		self::assertSame( '<h1>Before</h1>', $data['result']['before']['php'] );
		$proposal = $data['result']['proposal'];
		wp_set_current_user( self::$other );
		self::assertSame( 410, $this->request( 'section/accept', array( 'proposal' => $proposal ) )->get_status() );
		wp_set_current_user( self::$user );
		$lock = 'imajiner_section_ai_' . self::$user . '_' . $proposal;
		self::assertTrue( Imajiner_AI_Jobs::acquire( $lock ) );
		self::assertSame( 409, $this->request( 'section/accept', array( 'proposal' => $proposal ) )->get_status() );
		Imajiner_AI_Jobs::release( $lock );
		self::assertSame( 200, $this->request( 'section/accept', array( 'proposal' => $proposal ) )->get_status() );
		$after = Imajiner_Template_Store::read( $this->path );
		self::assertSame( str_replace( '<h1>Before</h1>', '<h1 class="ai-heading">After</h1>', $this->before['php'] ), $after['php'] );
		self::assertStringStartsWith( $this->before['css'] . "\n", $after['css'] );
		self::assertCount( 1, Imajiner_Template_Store::get_revisions( $this->path ) );
		self::assertSame( 410, $this->request( 'section/accept', array( 'proposal' => $proposal ) )->get_status() );
	}

	public function test_invalid_css_is_retried_once_and_never_saved(): void {
		$this->replies = array( $this->replacement( '<h1 class="ai-heading">After</h1>', '.imj-' . $this->slug . ' .hero { color: red; }' ), $this->replacement( '<h1 class="ai-heading">After</h1>', '.imj-' . $this->slug . ' .ai-heading ~ p { color: red; }' ) );
		$data = $this->finish( $this->section()->get_data()['job'] );
		self::assertSame( 'failed', $data['state'] );
		self::assertCount( 2, $this->requests );
		self::assertArrayNotHasKey( 'result', $data );
		self::assertSame( $this->before, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_stale_hash_blocks_both_worker_and_acceptance(): void {
		$id = $this->section()->get_data()['job'];
		$changed = $this->before;
		$changed['css'] .= '\n';
		self::assertTrue( Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->before ), $changed, 'Unit test edit' ) );
		self::assertSame( 'failed', $this->finish( $id )['state'] );
		self::assertCount( 0, $this->requests );
		self::assertTrue( Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $changed ), $this->before, 'Restore fixture' ) );
		$this->replies[] = $this->replacement();
		$data = $this->finish( $this->section()->get_data()['job'] );
		self::assertSame( 'complete', $data['state'], wp_json_encode( $data ) );
		self::assertTrue( Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->before ), $changed, 'Unit test edit' ) );
		self::assertSame( 409, $this->request( 'section/accept', array( 'proposal' => $data['result']['proposal'] ) )->get_status() );
		self::assertSame( $changed, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_cancellation_and_ownership_hide_private_payload(): void {
		$id = $this->section()->get_data()['job'];
		wp_set_current_user( self::$other );
		self::assertSame( 404, $this->request( 'jobs/' . $id, array(), 'GET' )->get_status() );
		self::assertSame( 404, $this->request( 'jobs/' . $id, array(), 'DELETE' )->get_status() );
		wp_set_current_user( self::$user );
		self::assertSame( 200, $this->request( 'jobs/' . $id, array(), 'DELETE' )->get_status() );
		$data = $this->finish( $id );
		self::assertSame( 'cancelled', $data['state'] );
		self::assertArrayNotHasKey( 'prompt', $data );
		self::assertArrayNotHasKey( 'hash', $data );
		self::assertSame( '', get_post( $id )->post_content );
		self::assertCount( 0, $this->requests );
	}

	public function test_generic_jobs_have_progress_worker_lock_cancellation_and_terminal_states(): void {
		$runs = 0;
		$value = "<div><?php echo 'unit'; ?>\\path</div>";
		$handler = function ( $result, $payload, $id ) use ( &$runs ) {
			++$runs;
			self::assertSame( 'running', $this->request( 'jobs/' . $id, array(), 'GET' )->get_data()['state'] );
			Imajiner_AI_Jobs::run( $id );
			self::assertTrue( Imajiner_AI_Jobs::progress( $id, 70 ) );
			self::assertSame( 70, $this->request( 'jobs/' . $id, array(), 'GET' )->get_data()['progress'] );
			return array( 'value' => $payload['value'] );
		};
		add_filter( 'imajiner_ai_job_handler_unit', $handler, 10, 3 );
		try {
			$queued = Imajiner_AI_Jobs::enqueue( 'unit', array( 'value' => $value, 'hash' => 'fixture' ) );
			$this->jobs[] = $queued['job'];
			$data = $this->finish( $queued['job'] );
			self::assertSame( 'complete', $data['state'] );
			self::assertSame( 100, $data['progress'] );
			self::assertSame( array( 'value' => $value ), $data['result'] );
			self::assertSame( 'complete', $this->request( 'jobs/' . $queued['job'], array(), 'DELETE' )->get_data()['state'] );
			Imajiner_AI_Jobs::run( $queued['job'] );
			self::assertSame( 1, $runs );
		} finally { remove_filter( 'imajiner_ai_job_handler_unit', $handler ); }
	}

	public function test_running_cancellation_discards_proposal_even_when_provider_returns(): void {
		$id = $this->section()->get_data()['job'];
		$intercept = function ( $preempt, $args, $url ) use ( $id ) { $this->request( 'jobs/' . $id, array(), 'DELETE' ); return $preempt; };
		add_filter( 'pre_http_request', $intercept, 1000, 3 );
		$this->replies[] = $this->replacement();
		try {
			self::assertSame( 'cancelled', $this->finish( $id )['state'] );
			global $wpdb;
			self::assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_imajiner_section_ai_' . self::$user . '_' ) . '%' ) ) );
		} finally {
			remove_filter( 'pre_http_request', $intercept, 1000 );
		}
		self::assertSame( $this->before, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_expiry_theme_and_worker_crash_are_bounded(): void {
		$id = $this->section()->get_data()['job'];
		$job = get_post_meta( $id, '_imj_job', true );
		$job['stylesheet'] = 'other-unit-theme';
		update_post_meta( $id, '_imj_job', $job );
		self::assertSame( 409, $this->request( 'jobs/' . $id, array(), 'GET' )->get_status() );
		Imajiner_AI_Jobs::run( $id );
		self::assertSame( 'failed', get_post_meta( $id, '_imj_job', true )['state'] );
		$id = $this->section()->get_data()['job'];
		$job = get_post_meta( $id, '_imj_job', true );
		$job['state'] = 'running';
		$job['started'] = time() - Imajiner_AI_Jobs::WORK_LIMIT - 1;
		update_post_meta( $id, '_imj_job', $job );
		self::assertSame( 'failed', $this->request( 'jobs/' . $id, array(), 'GET' )->get_data()['state'] );
		$job['expires'] = time() - 1;
		update_post_meta( $id, '_imj_job', $job );
		self::assertSame( 410, $this->request( 'jobs/' . $id, array(), 'GET' )->get_status() );
		wp_update_post( array( 'ID' => $id, 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 7200 ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ) ) );
		Imajiner_AI_Jobs::cleanup();
		self::assertNull( get_post( $id ) );
	}

	public function test_source_ranges_preserve_php_unicode_and_unrelated_bytes(): void {
		$element = '<div class="target"><img src="<?php echo esc_url( $imj_image ); ?>"><span>Édit</span></div>';
		$source = "<?php get_header(); ?>\r\n<!-- imj:section name=\"one\" -->\r\n" . $element . "\r\n<!-- /imj:section -->\r\n<!-- imj:section name=\"two\" --><aside>Unrelated</aside><!-- /imj:section --><?php get_footer(); ?>";
		self::assertSame( $element, Imajiner_Section_AI::node_source( $source, 'e0' ) );
		self::assertSame( str_replace( $element, '<div>Replacement</div>', $source ), Imajiner_Section_AI::replace_node( $source, 'e0', '<div>Replacement</div>' ) );
		self::assertSame( '<img src="<?php echo esc_url( $imj_image ); ?>">', Imajiner_Section_AI::node_source( $source, 'e1' ) );
		$section = "<!-- imj:section name=\"one\" -->\r\n" . $element . "\r\n<!-- /imj:section -->";
		self::assertSame( $section, Imajiner_Section_AI::node_source( $source, 's0' ) );
		self::assertInstanceOf( WP_Error::class, Imajiner_Section_AI::node_source( $source, 'e99' ) );
	}

	public function test_permissions_and_schema_reject_invalid_selections(): void {
		wp_set_current_user( 0 );
		self::assertSame( 401, $this->section()->get_status() );
		wp_set_current_user( self::$user );
		self::assertSame( 409, $this->request( 'section', array( 'key' => $this->slug, 'id' => 'e1', 'hash' => 'old', 'prompt' => 'Change heading.' ) )->get_status() );
		self::assertSame( 400, $this->request( 'section', array( 'key' => $this->slug, 'id' => 'p1', 'hash' => 'old', 'prompt' => 'Change PHP.' ) )->get_status() );
		self::assertSame( 400, $this->request( 'section', array( 'key' => '../outside', 'id' => 'e1', 'hash' => 'old', 'prompt' => 'Change heading.' ) )->get_status() );
		self::assertCount( 0, $this->requests );
	}

	public function test_usage_logs_answering_model_tokens_fallback_and_no_content(): void {
		update_option( Imajiner_AI::OPTION, array( 'primary' => array( 'provider' => 'openai', 'model' => 'unit-primary' ), 'fallback' => array( 'provider' => 'openai', 'model' => 'unit-fallback' ) ), false );
		$this->replies = array( new WP_Error( 'http_request_failed', 'Transport failure' ), 'private-answer-content' );
		self::assertSame( 'private-answer-content', Imajiner_AI::chat( array( array( 'role' => 'user', 'content' => 'private-prompt-content' ) ) ) );
		$usage = Imajiner_AI::last_usage();
		self::assertSame( 1, $usage['fallback_attempts'] );
		self::assertSame( 'unit-answer', $usage['answered']['model'] );
		self::assertSame( 15, $usage['answered']['tokens']['total_tokens'] );
		$logs = wp_json_encode( get_option( 'imajiner_ai_usage_' . self::$user ) );
		foreach ( array( 'private-answer-content', 'private-prompt-content', Imajiner_AI::get_api_key( 'openai' ) ) as $secret ) self::assertStringNotContainsString( $secret, $logs );
		self::assertSame( 'unit-fallback', $this->requests[1]['body']['model'] );
		self::assertArrayHasKey( 'max_completion_tokens', $this->requests[1]['body'] );
		wp_set_current_user( self::$other );
		self::assertSame( array(), $this->request( 'usage', array(), 'GET' )->get_data() );
	}

	public function uploads( $uploads ) {
		$uploads['baseurl'] = 'https://media.example.invalid/uploads';
		$uploads['url'] = $uploads['baseurl'] . $uploads['subdir'];
		return $uploads;
	}

	public function test_native_anthropic_image_blocks_and_graceful_missing_usage(): void {
		$settings = Imajiner_AI::get_settings();
		$settings['providers']['anthropic']['key'] = Imajiner_Secrets::encrypt( wp_generate_password() );
		Imajiner_AI::save_settings( $settings );
		add_filter( 'upload_dir', array( $this, 'uploads' ) );
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Unit image', 'post_status' => 'inherit' ) );
		$this->attachments[] = $id;
		update_post_meta( $id, '_wp_attached_file', 'unit-image.png' );
		$url = 'https://media.example.invalid/uploads/unit-image.png';
		$messages = array( array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'Describe colors.' ), array( 'type' => 'image_url', 'image_url' => array( 'url' => $url ) ) ) ) );
		$this->replies[] = array( 'model' => 'unit-claude', 'content' => array( array( 'type' => 'text', 'text' => 'Colors described.' ) ), 'stop_reason' => 'end_turn' );
		self::assertSame( 'Colors described.', Imajiner_AI::chat( $messages, array( 'provider' => 'anthropic', 'model' => 'unit-claude' ) ) );
		$body = $this->requests[0]['body'];
		self::assertStringContainsString( 'Template', $body['system'] );
		self::assertSame( array( 'type' => 'image', 'source' => array( 'type' => 'url', 'url' => $url ) ), $body['messages'][0]['content'][1] );
		self::assertNull( Imajiner_AI::last_usage()['answered']['tokens'] );
		self::assertStringNotContainsString( $url, wp_json_encode( Imajiner_AI::last_usage() ) );
		foreach ( array( 'http://media.example.invalid/uploads/unit-image.png', 'https://media.example.invalid/uploads/missing.png', 'data:image/png;base64,invalid' ) as $invalid ) {
			$messages[0]['content'][1]['image_url']['url'] = $invalid;
			self::assertInstanceOf( WP_Error::class, Imajiner_AI::chat( $messages ) );
		}
		self::assertCount( 1, $this->requests );
	}

	public function test_smoke_runner_requires_opt_in_and_never_calls_network_by_default(): void {
		self::assertInstanceOf( WP_Error::class, Imajiner_AI::smoke_from_env() );
		self::assertCount( 0, $this->requests );
	}
}
