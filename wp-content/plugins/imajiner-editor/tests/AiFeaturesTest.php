<?php
/** Isolated AI jobs, selected-edit and provider regressions; all HTTP is intercepted. */
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-imajiner-ai-jobs.php';
require_once dirname( __DIR__ ) . '/includes/class-imajiner-section-ai.php';
Imajiner_AI::init();
Imajiner_AI_Jobs::init();
Imajiner_Generation::init();
Imajiner_Section_AI::init();

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AiFeaturesTest extends TestCase {
	private $owner;
	private $other;
	private $settings;
	private $slug;
	private $path;
	private $files;
	private $jobs = array();
	private $proposals = array();
	private $calls = array();
	private $responses = array();
	private $mode = 'section';

	protected function setUp(): void {
		$this->settings = get_option( Imajiner_AI::OPTION, false );
		$this->slug = 'ai-test-' . substr( wp_generate_uuid4(), 0, 8 );
		$this->owner = wp_insert_user( array( 'user_login' => $this->slug, 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
		$this->other = wp_insert_user( array( 'user_login' => $this->slug . '-other', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
		wp_set_current_user( $this->owner );
		update_option( Imajiner_AI::OPTION, array( 'primary' => array( 'provider' => 'openai', 'model' => 'mock-requested' ), 'providers' => array( 'anthropic' => array( 'key' => Imajiner_Secrets::encrypt( 'mock-anthropic-token' ) ), 'groq' => array( 'key' => Imajiner_Secrets::encrypt( 'mock-groq-token' ) ) ) ), false );
		$this->path = get_stylesheet_directory() . '/imajiner/' . $this->slug . '.php';
		$this->files = array( 'php' => self::php( $this->slug ), 'css' => '.imj-' . $this->slug . ' .hero { color: var(--imj-color-primary); }' );
		self::assertTrue( Imajiner_Template_Store::create( $this->path, $this->files ) );
		wp_clean_themes_cache( false );
		add_filter( 'pre_http_request', array( $this, 'http' ), 9999, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'http' ), 9999 );
		foreach ( $this->jobs as $id ) {
			wp_delete_post( $id, true );
			delete_option( 'imajiner_ai_worker_' . $id );
			wp_clear_scheduled_hook( 'imajiner_ai_run_job', array( $id ) );
			wp_clear_scheduled_hook( 'imajiner_ai_expire_job', array( $id ) );
		}
		foreach ( $this->proposals as $proposal ) {
			delete_transient( 'imajiner_section_ai_' . $this->owner . '_' . $proposal );
			delete_transient( 'imajiner_ai_' . $this->owner . '_' . $proposal );
		}
		foreach ( Imajiner_Template_Store::get_revisions( $this->path ) as $revision ) {
			wp_delete_post( $revision['id'], true );
		}
		foreach ( array( $this->path, Imajiner_Template_Store::css_path( $this->path ) ) as $file ) {
			if ( file_exists( $file ) ) unlink( $file );
		}
		foreach ( get_posts( array( 'post_type' => 'imajiner_ai_usage', 'post_status' => 'private', 'author' => $this->owner, 'numberposts' => -1 ) ) as $post ) {
			wp_delete_post( $post->ID, true );
		}
		wp_delete_user( $this->owner );
		wp_delete_user( $this->other );
		if ( false === $this->settings ) delete_option( Imajiner_AI::OPTION ); else update_option( Imajiner_AI::OPTION, $this->settings, false );
		wp_set_current_user( 0 );
		wp_clean_themes_cache( false );
	}

	private static function php( $slug ) {
		return "<?php\n/**\n * Template Name: $slug\n */\nget_header();\n?>\n<!-- imj:section name=\"hero\" -->\n<section class=\"hero\"><h1>Hello</h1><p>Keep this neighbor</p></section>\n<!-- /imj:section -->\n<!-- imj:section name=\"next\" -->\n<section class=\"next\"><h1>Unrelated node</h1></section>\n<!-- /imj:section -->\n<?php get_footer();\n";
	}

	public function http( $preempt, $args, $url ) {
		if ( false !== strpos( $url, 'admin-ajax.php' ) ) {
			self::assertFalse( $args['blocking'] );
			self::assertLessThan( 1, $args['timeout'] );
			return array( 'response' => array( 'code' => 200 ), 'body' => '' );
		}
		$body = isset( $args['body'] ) && is_string( $args['body'] ) ? json_decode( $args['body'], true ) : array();
		$this->calls[] = array( 'url' => $url, 'body' => $body, 'args' => $args );
		if ( $this->responses ) {
			$response = array_shift( $this->responses );
			if ( is_wp_error( $response ) ) return $response;
			return $this->response( $response );
		}
		if ( 'generate' === $this->mode ) {
			$content = wp_json_encode( array( 'slug' => $this->slug, 'name' => $this->slug, 'php' => self::php( $this->slug ), 'css' => $this->files['css'] ) );
		} else {
			preg_match( '/class (imj-ai-[a-f0-9-]+)/', $body['messages'][1]['content'], $match );
			$anchor = isset( $match[1] ) ? $match[1] : 'imj-ai-none';
			$html = '<h2 class="' . $anchor . '">Changed heading</h2>';
			if ( 'section_root' === $this->mode ) $html = '<!-- imj:section name="hero" --><section class="' . $anchor . '"><h2>Changed section</h2></section><!-- /imj:section -->';
			if ( 'invalid' === $this->mode ) $html = '<?php echo "never execute"; ?>';
			$content = wp_json_encode( array( 'html' => $html, 'css' => '.imj-' . $this->slug . ' .' . $anchor . ' { color: var(--imj-color-primary); }' ) );
		}
		return $this->response( array( 'model' => 'mock-answered-v2', 'usage' => array( 'prompt_tokens' => 9, 'completion_tokens' => 3, 'total_tokens' => 12 ), 'choices' => array( array( 'message' => array( 'content' => $content ) ) ) ) );
	}

	private function response( array $body ) {
		return array( 'headers' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $body ) );
	}

	private function request( $path, array $params = array(), $method = 'POST' ) {
		$request = new WP_REST_Request( $method, '/imajiner/v1/ai/' . $path );
		$request->set_body_params( $params );
		return rest_do_request( $request );
	}

	private function queued( $id = 'e1' ) {
		$response = $this->request( 'section', array( 'key' => $this->slug, 'hash' => Imajiner_Template_Store::hash( $this->files ), 'id' => $id, 'prompt' => 'Make a new heading. private-prompt-marker' ) );
		self::assertSame( 202, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$job = $response->get_data()['id'];
		$this->jobs[] = $job;
		return $job;
	}

	private function completed( $id = 'e1' ) {
		$job = $this->queued( $id );
		Imajiner_AI_Jobs::run( $job );
		$data = $this->request( 'jobs/' . $job, array(), 'GET' )->get_data();
		self::assertSame( 'complete', $data['state'], wp_json_encode( $data ) );
		$this->proposals[] = $data['result']['proposal'];
		return $data['result'];
	}

	public function test_http_enqueues_without_provider_or_file_writes_then_requires_acceptance(): void {
		$job = $this->queued();
		self::assertCount( 0, $this->calls );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		self::assertSame( 'queued', $this->request( 'jobs/' . $job, array(), 'GET' )->get_data()['state'] );
		Imajiner_AI_Jobs::run( $job );
		$data = $this->request( 'jobs/' . $job, array(), 'GET' )->get_data();
		self::assertSame( 'complete', $data['state'] );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		$proposal = $data['result']['proposal'];
		$this->proposals[] = $proposal;
		self::assertSame( 200, $this->request( 'section/accept', array( 'proposal' => $proposal ) )->get_status() );
		$saved = Imajiner_Template_Store::read( $this->path );
		self::assertStringContainsString( 'Changed heading', $saved['php'] );
		self::assertStringContainsString( '<p>Keep this neighbor</p>', $saved['php'] );
		self::assertStringContainsString( '<section class="next"><h1>Unrelated node</h1></section>', $saved['php'] );
		self::assertSame( ( new Imajiner_Template_Scanner( $this->files['php'] ) )->get_php_sources(), ( new Imajiner_Template_Scanner( $saved['php'] ) )->get_php_sources() );
		self::assertStringStartsWith( $this->files['css'], $saved['css'] );
		self::assertCount( 1, Imajiner_Template_Store::get_revisions( $this->path ) );
		self::assertSame( 410, $this->request( 'section/accept', array( 'proposal' => $proposal ) )->get_status() );
	}

	public function test_full_section_is_replaced_without_touching_second_section(): void {
		$this->mode = 'section_root';
		$result = $this->completed( 's0' );
		self::assertSame( 200, $this->request( 'section/accept', array( 'proposal' => $result['proposal'] ) )->get_status() );
		self::assertStringContainsString( 'Changed section', file_get_contents( $this->path ) );
		self::assertStringContainsString( '<section class="next"><h1>Unrelated node</h1></section>', file_get_contents( $this->path ) );
	}

	public function test_generation_route_is_background_and_normalization_keeps_confirmation(): void {
		$this->mode = 'generate';
		$response = $this->request( 'generate', array( 'key' => $this->slug ) );
		self::assertSame( 202, $response->get_status() );
		self::assertCount( 0, $this->calls );
		$id = $response->get_data()['id'];
		$this->jobs[] = $id;
		Imajiner_AI_Jobs::run( $id );
		$result = $this->request( 'jobs/' . $id, array(), 'GET' )->get_data();
		self::assertSame( 'complete', $result['state'], wp_json_encode( $result ) );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		$this->proposals[] = $result['result']['proposal'];
		self::assertSame( 200, $this->request( 'accept', array( 'proposal' => $result['result']['proposal'] ) )->get_status() );
	}

	public function test_worker_runs_once_even_when_dispatched_repeatedly(): void {
		$job = $this->queued();
		Imajiner_AI_Jobs::run( $job );
		Imajiner_AI_Jobs::run( $job );
		self::assertCount( 1, $this->calls );
	}

	public function test_job_and_proposal_are_private_to_owner(): void {
		$result = $this->completed();
		wp_set_current_user( $this->other );
		self::assertSame( 404, $this->request( 'jobs/' . $this->jobs[0], array(), 'GET' )->get_status() );
		self::assertSame( 404, $this->request( 'jobs/' . $this->jobs[0] . '/cancel' )->get_status() );
		self::assertSame( 410, $this->request( 'section/accept', array( 'proposal' => $result['proposal'] ) )->get_status() );
	}

	public function test_theme_changed_jobs_are_not_readable_or_run(): void {
		$id = $this->queued();
		$job = get_post_meta( $id, '_imajiner_job', true );
		$job['theme'] = 'different-child-theme';
		update_post_meta( $id, '_imajiner_job', $job );
		self::assertSame( 404, $this->request( 'jobs/' . $id, array(), 'GET' )->get_status() );
		Imajiner_AI_Jobs::run( $id );
		self::assertSame( 'failed', get_post_meta( $id, '_imajiner_job', true )['state'] );
		self::assertCount( 0, $this->calls );
	}

	public function test_stale_hash_at_queue_worker_and_acceptance(): void {
		$result = $this->completed();
		$queued = $this->queued();
		$newer = $this->files['css'] . '\n/* concurrent edit */';
		file_put_contents( Imajiner_Template_Store::css_path( $this->path ), $newer );
		self::assertSame( 409, $this->request( 'section', array( 'key' => $this->slug, 'hash' => Imajiner_Template_Store::hash( $this->files ), 'id' => 'e1', 'prompt' => 'Change' ) )->get_status() );
		Imajiner_AI_Jobs::run( $queued );
		self::assertSame( 'failed', $this->request( 'jobs/' . $queued, array(), 'GET' )->get_data()['state'] );
		self::assertSame( 409, $this->request( 'section/accept', array( 'proposal' => $result['proposal'] ) )->get_status() );
		self::assertSame( $newer, file_get_contents( Imajiner_Template_Store::css_path( $this->path ) ) );
	}

	public function test_cancelled_jobs_do_not_run(): void {
		$id = $this->queued();
		self::assertSame( 'cancelled', $this->request( 'jobs/' . $id . '/cancel' )->get_data()['state'] );
		Imajiner_AI_Jobs::run( $id );
		self::assertCount( 0, $this->calls );
		self::assertSame( '', get_post_meta( $id, '_imajiner_payload', true ) );
	}

	public function test_cancel_mid_worker_discards_result(): void {
		$id = $this->queued();
		$interrupt = function ( $preempt, $args, $url ) use ( $id ) { if ( false !== strpos( $url, '/chat/completions' ) ) $this->request( 'jobs/' . $id . '/cancel' ); return $preempt; };
		add_filter( 'pre_http_request', $interrupt, 5, 3 );
		try { Imajiner_AI_Jobs::run( $id ); } finally { remove_filter( 'pre_http_request', $interrupt, 5 ); }
		self::assertSame( 'cancelled', $this->request( 'jobs/' . $id, array(), 'GET' )->get_data()['state'] );
		self::assertSame( '', get_post_meta( $id, '_imajiner_result', true ) );
	}

	public function test_expiry_purges_private_payload(): void {
		$id = $this->queued();
		$job = get_post_meta( $id, '_imajiner_job', true );
		$job['expires'] = time() - 1;
		update_post_meta( $id, '_imajiner_job', $job );
		self::assertSame( 410, $this->request( 'jobs/' . $id, array(), 'GET' )->get_status() );
		self::assertNull( get_post( $id ) );
	}

	public function test_atomic_lock_prevents_a_second_worker(): void {
		$id = $this->queued();
		add_option( 'imajiner_ai_worker_' . $id, time(), '', false );
		Imajiner_AI_Jobs::run( $id );
		self::assertCount( 0, $this->calls );
	}

	public function test_cancellation_after_completion_revokes_proposal(): void {
		$result = $this->completed();
		self::assertSame( 'cancelled', $this->request( 'jobs/' . $this->jobs[0] . '/cancel' )->get_data()['state'] );
		self::assertSame( 410, $this->request( 'section/accept', array( 'proposal' => $result['proposal'] ) )->get_status() );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_cron_watchdog_fails_abandoned_worker_without_polling(): void {
		$id = $this->queued();
		$job = get_post_meta( $id, '_imajiner_job', true );
		$job['state'] = 'running';
		$job['started'] = time() - Imajiner_AI_Jobs::WORKER_LIMIT;
		update_post_meta( $id, '_imajiner_job', $job );
		do_action( 'imajiner_ai_run_job', $id );
		self::assertSame( 'failed', get_post_meta( $id, '_imajiner_job', true )['state'] );
		self::assertSame( '', get_post_meta( $id, '_imajiner_payload', true ) );
		self::assertCount( 0, $this->calls );
	}

	public function test_acceptance_lock_prevents_simultaneous_writes(): void {
		$result = $this->completed();
		$lock = 'imajiner_section_ai_' . $this->owner . '_' . $result['proposal'] . '_accept';
		add_option( $lock, time(), '', false );
		try {
			self::assertSame( 409, $this->request( 'section/accept', array( 'proposal' => $result['proposal'] ) )->get_status() );
			self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		} finally { delete_option( $lock ); }
	}

	public function test_invalid_output_gets_exactly_one_retry_and_never_saves(): void {
		$this->mode = 'invalid';
		$id = $this->queued();
		Imajiner_AI_Jobs::run( $id );
		self::assertCount( 2, $this->calls );
		self::assertSame( 'failed', $this->request( 'jobs/' . $id, array(), 'GET' )->get_data()['state'] );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		self::assertStringContainsString( 'Fix validation errors', $this->calls[1]['body']['messages'][3]['content'] );
	}

	public function test_scoped_css_and_unsafe_markup_are_rejected(): void {
		$range = Imajiner_Section_AI::range( $this->files['php'], 'e1' );
		$context = array( 'files' => $this->files, 'template' => array( 'type' => 'page' ), 'range' => $range, 'scope' => '.imj-' . $this->slug );
		foreach ( array( '<h1 class="imj-ai-test" onclick="alert(1)">X</h1>', '<h1 class="imj-ai-test"><script>alert(1)</script></h1>', '<h1 class="imj-ai-test"><a href="javascript:alert(1)">X</a></h1>', '<h1 class="imj-ai-test"><a href="java&#10;script:alert(1)">X</a></h1>', '<h1 class="imj-ai-test"><svg><animate attributeName="href" to="javascript:alert(1)"></animate></svg></h1>', '<h1 class="imj-ai-test">X</h1><p>Extra sibling</p>', '<?php exit; ?>' ) as $html ) {
			self::assertInstanceOf( WP_Error::class, Imajiner_Section_AI::validate_reply( wp_json_encode( array( 'html' => $html, 'css' => '' ) ), $context, 'imj-ai-test' ) );
		}
		self::assertInstanceOf( WP_Error::class, Imajiner_Section_AI::validate_reply( wp_json_encode( array( 'html' => '<h1 class="imj-ai-test">X</h1>', 'css' => '.imj-' . $this->slug . ' .next { color:red; }' ) ), $context, 'imj-ai-test' ) );
	}

	public function test_range_resolves_identical_neighbors_and_dynamic_nodes_stay_locked(): void {
		$php = str_replace( '<h1>Hello</h1>', '<h1>Hello</h1><h1>Hello</h1>', $this->files['php'] );
		$range = Imajiner_Section_AI::range( $php, 'e1' );
		self::assertSame( strpos( $php, '<h1>Hello</h1>' ), $range['start'] );
		self::assertSame( '<h1>Hello</h1>', substr( $php, $range['start'], $range['end'] - $range['start'] ) );
		$php = str_replace( '<h1>Hello</h1>', '<h1><?php the_title(); ?></h1>', $this->files['php'] );
		self::assertInstanceOf( WP_Error::class, Imajiner_Section_AI::range( $php, 'e1' ) );
		$range = Imajiner_Section_AI::range( $php, 'e2' );
		self::assertSame( '<p>Keep this neighbor</p>', substr( $php, $range['start'], $range['end'] - $range['start'] ) );
	}

	public function test_generic_design_handler_and_secret_field_rejection(): void {
		$handler = function ( $unused, $payload, $id ) { Imajiner_AI_Jobs::progress( $id, 80 ); return array( 'tokens' => array( '--imj-test' => '#000' ), 'hash' => $payload['hash'] ); };
		add_filter( 'imajiner_ai_job_handler_design', $handler, 10, 3 );
		try {
			self::assertInstanceOf( WP_Error::class, Imajiner_AI_Jobs::enqueue( 'design', array( 'api_key' => 'not-allowed' ) ) );
			$job = Imajiner_AI_Jobs::enqueue( 'design', array( 'hash' => 'test-design-hash' ) );
			$this->jobs[] = $job['id'];
			Imajiner_AI_Jobs::run( $job['id'] );
			self::assertSame( 'test-design-hash', $this->request( 'jobs/' . $job['id'], array(), 'GET' )->get_data()['result']['hash'] );
		} finally { remove_filter( 'imajiner_ai_job_handler_design', $handler, 10 ); }
	}

	public function test_usage_log_returns_actual_model_and_no_private_content(): void {
		$this->completed();
		$logs = $this->request( 'usage', array(), 'GET' )->get_data();
		self::assertSame( 'mock-answered-v2', $logs[0]['model'] );
		self::assertSame( 12, $logs[0]['usage']['total_tokens'] );
		self::assertSame( 0, $logs[0]['fallback_attempts'] );
		self::assertStringNotContainsString( 'private-prompt-marker', wp_json_encode( $logs ) );
		self::assertStringNotContainsString( 'Changed heading', wp_json_encode( $logs ) );
		self::assertStringNotContainsString( Imajiner_AI::get_api_key( 'openai' ), wp_json_encode( $logs ) );
		wp_set_current_user( $this->other );
		self::assertSame( array(), $this->request( 'usage', array(), 'GET' )->get_data() );
	}

	public function test_fallback_and_missing_usage_have_safe_metadata(): void {
		$settings = Imajiner_AI::get_settings();
		$settings['fallback'] = array( 'provider' => 'groq', 'model' => 'mock-backup' );
		Imajiner_AI::save_settings( $settings );
		$this->responses = array( new WP_Error( 'mock_failure', 'redacted network failure' ), array( 'model' => 'mock-backup-actual', 'choices' => array( array( 'message' => array( 'content' => 'OK' ) ) ) ) );
		$result = Imajiner_AI::chat_result( array( array( 'role' => 'user', 'content' => 'Private content' ) ) );
		self::assertSame( 'mock-backup-actual', $result['model'] );
		self::assertSame( 'groq', $result['provider'] );
		self::assertSame( 1, $result['fallback_attempts'] );
		self::assertNull( $result['usage'] );
		self::assertCount( 2, $result['attempts'] );
	}

	public function test_native_anthropic_image_blocks_and_system_prompt_filter(): void {
		$filter = function ( $prompt ) { return $prompt . '\nPERSISTENT_DESIGN_TOKEN'; };
		add_filter( 'imajiner_editor_system_prompt', $filter );
		$this->responses[] = array( 'model' => 'mock-claude-actual', 'usage' => array( 'input_tokens' => 5, 'output_tokens' => 1 ), 'content' => array( array( 'type' => 'text', 'text' => 'OK' ) ), 'stop_reason' => 'end_turn' );
		try {
			$result = Imajiner_AI::chat_result( array( array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'Look at this mock image' ), array( 'type' => 'image_url', 'image_url' => array( 'url' => 'https://wordpress.org/mock-image.png' ) ) ) ) ), array( 'provider' => 'anthropic', 'model' => 'mock-claude' ) );
			self::assertSame( 'OK', $result['content'] );
			$body = $this->calls[0]['body'];
			self::assertSame( array( 'type' => 'image', 'source' => array( 'type' => 'url', 'url' => 'https://wordpress.org/mock-image.png' ) ), $body['messages'][0]['content'][1] );
			self::assertStringContainsString( 'PERSISTENT_DESIGN_TOKEN', $body['system'] );
			self::assertArrayNotHasKey( 'fallbacks', $body );
			self::assertStringNotContainsString( 'mock-image.png', wp_json_encode( Imajiner_AI::last_result() ) );
		} finally { remove_filter( 'imajiner_editor_system_prompt', $filter ); }
	}

	public function test_invalid_image_parts_do_not_contact_provider(): void {
		foreach ( array( 'data:image/png;base64,not-allowed', 'http://wordpress.org/mock.png', 'https://user:pass@wordpress.org/mock.png', 'https://127.0.0.1/mock.png' ) as $url ) {
			$result = Imajiner_AI::chat( array( array( 'role' => 'user', 'content' => array( array( 'type' => 'image_url', 'image_url' => array( 'url' => $url ) ) ) ) ) );
			self::assertInstanceOf( WP_Error::class, $result );
		}
		self::assertCount( 0, $this->calls );
	}

	public function test_opt_in_runner_is_disabled_by_default(): void {
		self::assertInstanceOf( WP_Error::class, Imajiner_AI::smoke_from_environment() );
		self::assertCount( 0, $this->calls );
	}

	public function test_environment_smoke_runner_is_mocked_and_returns_metadata_only(): void {
		define( 'WP_CLI', true );
		$env = array( 'IMAJINER_AI_SMOKE' => '1', 'IMAJINER_AI_SMOKE_PROVIDERS' => 'openai', 'IMAJINER_OPENAI_MODEL' => 'mock-smoke', 'IMAJINER_OPENAI_API_KEY' => IMAJINER_OPENAI_API_KEY );
		$previous = array();
		foreach ( $env as $name => $value ) { $previous[ $name ] = getenv( $name ); putenv( $name . '=' . $value ); }
		$this->responses = array( array( 'data' => array( array( 'id' => 'mock-smoke' ) ) ), array( 'model' => 'mock-smoke-actual', 'choices' => array( array( 'message' => array( 'content' => 'OK' ) ) ) ) );
		try {
			$result = Imajiner_AI::smoke_from_environment();
			self::assertSame( 'passed', $result['openai']['state'] );
			self::assertSame( 1, $result['openai']['models'] );
			self::assertSame( 'mock-smoke-actual', $result['openai']['model'] );
			self::assertCount( 2, $this->calls );
			self::assertStringNotContainsString( IMAJINER_OPENAI_API_KEY, wp_json_encode( $result ) );
			self::assertStringStartsWith( Imajiner_Prompts::system_prompt(), $this->calls[1]['body']['messages'][0]['content'] );
		} finally {
			foreach ( $previous as $name => $value ) putenv( false === $value ? $name : $name . '=' . $value );
		}
	}

	public function test_unauthenticated_routes_reject_work_and_file_acceptance(): void {
		wp_set_current_user( 0 );
		foreach ( array( 'generate', 'section', 'section/accept', 'jobs/1/cancel' ) as $path ) {
			self::assertSame( 401, $this->request( $path, array( 'key' => $this->slug, 'hash' => Imajiner_Template_Store::hash( $this->files ), 'id' => 'e1', 'prompt' => 'Change', 'proposal' => wp_generate_uuid4() ) )->get_status() );
		}
	}
}
