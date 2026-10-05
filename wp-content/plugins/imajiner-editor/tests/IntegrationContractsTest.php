<?php
/** Production integration contracts with intercepted HTTP; no provider credentials or calls. */
use PHPUnit\Framework\TestCase;

class Imajiner_Integration_Corrupt_Transport extends Imajiner_Atomic_Direct_Filesystem {
	public $target;
	public $corrupted = false;
	public function wp_content_dir() { return trailingslashit( WP_CONTENT_DIR ); }
	public function move( $source, $destination, $overwrite = false ) {
		$result = parent::move( $source, $destination, $overwrite );
		if ( $result && $destination === $this->target && 0 === strpos( basename( $source ), '.imj-' ) && ! $this->corrupted ) {
			$this->corrupted = true;
			parent::put_contents( $destination, 'Disposable corrupted transfer' );
		}
		return $result;
	}
}

final class IntegrationContractsTest extends TestCase {
	private $admin;
	private $settings;
	private $calls = array();
	private $reply;
	private $file;
	private $previous;
	private $jobs = array();

	protected function setUp(): void {
		$this->admin = wp_insert_user( array( 'user_login' => 'integration-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		$this->settings = get_option( Imajiner_AI::OPTION, null );
		update_option( Imajiner_AI::OPTION, array( 'primary' => array( 'provider' => 'openai', 'model' => 'integration-mock' ), 'fallback' => array( 'provider' => '', 'model' => '' ), 'providers' => array( 'meta' => array( 'key' => Imajiner_Secrets::encrypt( 'intercepted-meta-key' ) ), 'anthropic' => array( 'key' => Imajiner_Secrets::encrypt( 'intercepted-claude-key' ) ) ) ), false );
		$this->file = imajiner_design_tokens_file();
		$this->previous = is_file( $this->file ) ? file_get_contents( $this->file ) : null;
		$this->reply = array( 'choices' => array( array( 'message' => array( 'content' => 'OK' ) ) ) );
		add_filter( 'pre_http_request', array( $this, 'http' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'http' ), 10 );
		foreach ( $this->jobs as $id ) {
			Imajiner_AI_Jobs::expire( $id );
			wp_delete_post( $id, true );
			wp_clear_scheduled_hook( 'imajiner_ai_run_job', array( $id ) );
			wp_clear_scheduled_hook( 'imajiner_ai_expire_job', array( $id ) );
		}
		foreach ( get_posts( array( 'post_type' => array( Imajiner_Design_System::REVISION, 'imajiner_ai_usage' ), 'post_status' => 'any', 'author' => $this->admin, 'numberposts' => -1 ) ) as $post ) {
			wp_delete_post( $post->ID, true );
		}
		if ( null === $this->previous ) {
			if ( is_file( $this->file ) ) { unlink( $this->file ); }
		} else {
			file_put_contents( $this->file, $this->previous );
		}
		if ( null === $this->settings ) { delete_option( Imajiner_AI::OPTION ); } else { update_option( Imajiner_AI::OPTION, $this->settings, false ); }
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->admin );
		wp_set_current_user( 0 );
	}

	public function http( $unused, $args, $url ) {
		$this->calls[] = array( 'url' => $url, 'args' => $args );
		return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $this->reply ) );
	}

	private function request( $path, $params = array(), $method = 'POST' ) {
		$request = new WP_REST_Request( $method, '/imajiner/v1/' . $path );
		$request->set_body_params( $params );
		return rest_do_request( $request );
	}

	public function test_meta_models_chat_urls_and_bearer_authentication(): void {
		$this->reply = array( 'data' => array( array( 'id' => 'integration-llama' ) ) );
		self::assertNotWPError( Imajiner_AI::list_models( 'meta' ) );
		self::assertSame( 'https://api.meta.ai/v1/models', $this->calls[0]['url'] );
		self::assertSame( 'GET', $this->calls[0]['args']['method'] );
		self::assertSame( 'Bearer intercepted-meta-key', $this->calls[0]['args']['headers']['Authorization'] );
		$this->reply = array( 'choices' => array( array( 'message' => array( 'content' => 'OK' ) ) ) );
		self::assertSame( 'OK', Imajiner_AI::chat( array( array( 'role' => 'user', 'content' => 'Mock test' ) ), array( 'provider' => 'meta', 'model' => 'integration-llama' ) ) );
		self::assertSame( 'https://api.meta.ai/v1/chat/completions', $this->calls[1]['url'] );
		self::assertSame( 'POST', $this->calls[1]['args']['method'] );
		self::assertSame( 'Bearer intercepted-meta-key', $this->calls[1]['args']['headers']['Authorization'] );
		self::assertSame( 'https://dev.meta.ai/', Imajiner_AI::providers()['meta']['key_url'] );
	}

	public function test_explicitly_declined_generation_proposal_cannot_be_saved(): void {
		$response = $this->request( 'ai/accept', array( 'proposal' => wp_generate_uuid4(), 'confirm' => false ) );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'imajiner_confirm', $response->get_data()['code'] );
		self::assertSame( array(), $this->calls );
	}

	public function test_claude_native_images_supported_fallbacks_and_explicit_model_selection(): void {
		$image = 'data:image/png;base64,' . base64_encode( file_get_contents( get_template_directory() . '/screenshot.png' ) );
		$this->reply = array( 'content' => array( array( 'type' => 'text', 'text' => 'OK' ) ), 'stop_reason' => 'end_turn' );
		foreach ( array( 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5' ) as $model ) {
			self::assertSame( 'OK', Imajiner_AI::chat( array( array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => 'Mock image' ), array( 'type' => 'image_url', 'image_url' => array( 'url' => $image ) ) ) ) ), array( 'provider' => 'anthropic', 'model' => $model ) ) );
			$call = end( $this->calls );
			$body = json_decode( $call['args']['body'], true );
			self::assertSame( 'https://api.anthropic.com/v1/messages', $call['url'] );
			self::assertSame( 'server-side-fallback-2026-07-01', $call['args']['headers']['anthropic-beta'] );
			self::assertSame( '2023-06-01', $call['args']['headers']['anthropic-version'] );
			self::assertSame( 'intercepted-claude-key', $call['args']['headers']['x-api-key'] );
			self::assertSame( $model, $body['model'] );
			self::assertSame( 'default', $body['fallbacks'] );
			$source = $body['messages'][0]['content'][1]['source'];
			self::assertSame( 'base64', $source['type'] );
			self::assertSame( 'image/png', $source['media_type'] );
			self::assertSame( file_get_contents( get_template_directory() . '/screenshot.png' ), base64_decode( $source['data'], true ) );
		}
	}

	public function test_claude_unsupported_models_do_not_receive_fallback_and_refusal_remains_error(): void {
		$this->reply = array( 'content' => array(), 'stop_reason' => 'refusal' );
		$result = Imajiner_AI::chat( array( array( 'role' => 'user', 'content' => 'Mock refusal' ) ), array( 'provider' => 'anthropic', 'model' => 'integration-explicit-model' ) );
		self::assertTrue( is_wp_error( $result ) );
		self::assertSame( 'imajiner_ai_refused', $result->get_error_code() );
		$body = json_decode( $this->calls[0]['args']['body'], true );
		self::assertSame( 'integration-explicit-model', $body['model'] );
		self::assertArrayNotHasKey( 'fallbacks', $body );
		self::assertArrayNotHasKey( 'anthropic-beta', $this->calls[0]['args']['headers'] );
	}

	public function test_design_job_enqueue_is_nonblocking_cancel_stops_work_and_clears_payload(): void {
		$state = $this->request( 'design-system/state', array(), 'GET' )->get_data();
		$response = $this->request( 'design-system/extract', array( 'async' => true, 'hash' => $state['hash'], 'prompt' => 'Mock tokens' ) );
		self::assertSame( 202, $response->get_status() );
		$id = $response->get_data()['id'];
		$this->jobs[] = $id;
		self::assertCount( 1, $this->calls );
		self::assertSame( admin_url( 'admin-ajax.php' ), $this->calls[0]['url'] );
		self::assertFalse( $this->calls[0]['args']['blocking'] );
		self::assertSame( 0.01, $this->calls[0]['args']['timeout'] );
		self::assertNotEmpty( $this->calls[0]['args']['body']['signature'] );
		self::assertSame( 200, $this->request( 'ai/jobs/' . $id . '/cancel' )->get_status() );
		Imajiner_AI_Jobs::run( $id );
		self::assertCount( 1, $this->calls );
		self::assertSame( 'cancelled', $this->request( 'ai/jobs/' . $id, array(), 'GET' )->get_data()['state'] );
		self::assertSame( '', get_post_meta( $id, '_imajiner_payload', true ) );
		self::assertFalse( Imajiner_AI_Jobs::can_accept( $id ) );
	}

	public function test_design_job_completion_acceptance_and_saved_context_on_later_request(): void {
		$state = $this->request( 'design-system/state', array(), 'GET' )->get_data();
		$this->reply = array( 'choices' => array( array( 'message' => array( 'content' => wp_json_encode( array( 'tokens' => array( '--imj-color-primary' => '#13579b' ), 'summary' => 'Mock proposal' ) ) ) ) ) );
		$response = $this->request( 'design-system/extract', array( 'async' => true, 'hash' => $state['hash'], 'prompt' => 'Mock tokens' ) );
		self::assertSame( 202, $response->get_status() );
		$id = $response->get_data()['id'];
		$this->jobs[] = $id;
		Imajiner_AI_Jobs::run( $id );
		$status = $this->request( 'ai/jobs/' . $id, array(), 'GET' )->get_data();
		self::assertSame( 'complete', $status['state'] );
		self::assertSame( $state['css'], $this->request( 'design-system/state', array(), 'GET' )->get_data()['css'] );
		$proposal = $status['result']['proposal'];
		self::assertSame( 400, $this->request( 'design-system/accept', array( 'proposal' => $proposal, 'confirm' => false ) )->get_status() );
		self::assertSame( 200, $this->request( 'design-system/accept', array( 'proposal' => $proposal, 'confirm' => true ) )->get_status() );
		wp_set_current_user( 0 );
		wp_set_current_user( $this->admin );
		self::assertStringContainsString( '#13579b', Imajiner_Prompts::system_prompt() );
		self::assertSame( '#13579b', Imajiner_Editor::design_tokens()->{'--imj-color-primary'} );
		$urls = Imajiner_Editor::design_stylesheet_urls();
		self::assertCount( 3, $urls );
		self::assertStringContainsString( '/assets/css/design-tokens.css?ver=', $urls[2] );
		self::assertStringContainsString( '/assets/css/base.css?ver=', $urls[0] );
		self::assertStringContainsString( '/style.css?ver=', $urls[1] );
	}

	public function test_shared_stylesheet_discovery_rejects_outside_roots(): void {
		$filter = function ( $sources ) { $sources[] = ABSPATH . 'wp-config.php'; $sources[] = WP_CONTENT_DIR . '/uploads/outside.css'; return $sources; };
		add_filter( 'imajiner_design_token_sources', $filter );
		try {
			foreach ( Imajiner_Editor::design_stylesheet_urls() as $url ) {
				self::assertStringNotContainsString( 'wp-config.php', $url );
				self::assertStringNotContainsString( 'outside.css', $url );
			}
		} finally { remove_filter( 'imajiner_design_token_sources', $filter ); }
	}

	public function test_design_partial_transfer_rolls_back_with_production_adapter_and_can_retry(): void {
		$original = ':root { --imj-color-primary: #aabbcc; }';
		self::assertTrue( Imajiner_Filesystem::write( $this->file, $original ) );
		$state = $this->request( 'design-system/state', array(), 'GET' )->get_data();
		$this->reply = array( 'choices' => array( array( 'message' => array( 'content' => wp_json_encode( array( 'tokens' => array( '--imj-color-primary' => '#13579b' ), 'summary' => 'Mock proposal' ) ) ) ) ) );
		$proposal = $this->request( 'design-system/extract', array( 'hash' => $state['hash'], 'prompt' => 'Mock tokens' ) )->get_data()['proposal'];
		$transport = new Imajiner_Integration_Corrupt_Transport( null );
		$transport->method = 'ftpext';
		$transport->target = $this->file;
		$client = new ReflectionProperty( Imajiner_Filesystem::class, 'client' );
		$client->setAccessible( true );
		$client->setValue( null, $transport );
		try {
			self::assertSame( 500, $this->request( 'design-system/accept', array( 'proposal' => $proposal, 'confirm' => true ) )->get_status() );
			self::assertTrue( $transport->corrupted );
			self::assertSame( $original, file_get_contents( $this->file ) );
			self::assertCount( 1, $this->request( 'design-system/revisions', array(), 'GET' )->get_data() );
			self::assertSame( array(), glob( dirname( $this->file ) . '/.imj-design-*.tmp' ) );
			self::assertSame( 200, $this->request( 'design-system/accept', array( 'proposal' => $proposal, 'confirm' => true ) )->get_status() );
			self::assertStringContainsString( '#13579b', file_get_contents( $this->file ) );
		} finally { $client->setValue( null, null ); }
	}

	private static function assertNotWPError( $value ): void {
		self::assertFalse( is_wp_error( $value ), is_wp_error( $value ) ? $value->get_error_message() : '' );
	}
}
