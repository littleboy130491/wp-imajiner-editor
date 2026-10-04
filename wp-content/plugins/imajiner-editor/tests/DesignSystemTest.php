<?php
/**
 * Disposable WP integration tests. All outbound HTTP is intercepted.
 * The filesystem contract adapter is only used before the integration unit lands.
 */
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-imajiner-design-system.php';

if ( ! class_exists( 'Imajiner_Filesystem' ) ) {
	class Imajiner_Design_Test_Filesystem {
		public static $failure = '';
		public static function init() {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			return WP_Filesystem() ? true : new WP_Error( 'test_fs', 'WP_Filesystem initialization failed.' );
		}
		public static function exists( $path ) {
			global $wp_filesystem;
			return $wp_filesystem->exists( $path );
		}
		public static function read( $path ) {
			global $wp_filesystem;
			$data = $wp_filesystem->get_contents( $path );
			return false === $data ? new WP_Error( 'test_read', 'Read failed.' ) : $data;
		}
		public static function write( $path, $contents ) {
			global $wp_filesystem;
			return 'write' !== self::$failure && $wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE ) ? true : new WP_Error( 'test_write', 'Write failed.' );
		}
		public static function mkdir( $path ) {
			global $wp_filesystem;
			return $wp_filesystem->mkdir( $path, FS_CHMOD_DIR ) ? true : new WP_Error( 'test_mkdir', 'Directory creation failed.' );
		}
		public static function delete( $path ) {
			global $wp_filesystem;
			return $wp_filesystem->delete( $path ) ? true : new WP_Error( 'test_delete', 'Delete failed.' );
		}
		public static function move( $from, $to, $overwrite = false ) {
			global $wp_filesystem;
			if ( 'partial_move' === self::$failure ) {
				$wp_filesystem->delete( $to );
				return new WP_Error( 'test_move', 'Move failed after removing target.' );
			}
			return $wp_filesystem->move( $from, $to, $overwrite ) ? true : new WP_Error( 'test_move', 'Move failed.' );
		}
	}
	class_alias( 'Imajiner_Design_Test_Filesystem', 'Imajiner_Filesystem' );
}

Imajiner_Design_System::init();
add_action( 'rest_api_init', array( 'Imajiner_Design_System', 'routes' ), 99 );

/**
 * Theme functions cache discovered parts in static variables. Isolate our
 * throwaway theme switches from the existing template test suites.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DesignSystemTest extends TestCase {
	private $theme;
	private $previous_theme;
	private $user;
	private $other;
	private $author;
	private $directory;
	private $settings;
	private $reply;
	private $requests = array();
	private $responses = array();
	private $transport_options = array();
	private $proposals = array();
	private $attachments = array();

	protected function setUp(): void {
		$this->previous_theme = get_stylesheet();
		$this->theme = 'imj-design-test-' . substr( wp_generate_uuid4(), 0, 8 );
		$this->directory = get_theme_root() . '/' . $this->theme;
		mkdir( $this->directory );
		file_put_contents( $this->directory . '/style.css', "/*\nTheme Name: Disposable Design Test\nTemplate: imajiner\n*/\n" );
		wp_clean_themes_cache();
		switch_theme( $this->theme );
		foreach ( array( 'user', 'other', 'author' ) as $property ) {
			$this->$property = wp_insert_user( array( 'user_login' => $this->theme . '-' . $property, 'user_pass' => wp_generate_password(), 'role' => 'author' === $property ? 'author' : 'administrator' ) );
		}
		get_user_by( 'id', $this->author )->add_cap( 'edit_themes' );
		wp_set_current_user( $this->user );
		$this->settings = get_option( Imajiner_AI::OPTION, false );
		update_option( Imajiner_AI::OPTION, array( 'primary' => array( 'provider' => 'openai', 'model' => 'mock-design-model' ) ), false );
		$this->reply = wp_json_encode( array( 'tokens' => array( '--imj-color-primary' => '#123456', '--imj-font-body' => 'Arial, sans-serif', '--imj-text-base' => '1rem', '--imj-space-4' => '1rem', '--imj-leading' => '1.5', '--imj-container' => '1100px', '--imj-radius' => '8px', '--imj-gutter' => '1rem' ), 'summary' => 'A calm visual system.' ) );
		add_filter( 'pre_http_request', array( $this, 'http' ), PHP_INT_MAX, 3 );
		if ( class_exists( 'Imajiner_Design_Test_Filesystem', false ) ) {
			Imajiner_Design_Test_Filesystem::$failure = '';
		}
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'http' ), PHP_INT_MAX );
		wp_set_current_user( $this->user );
		foreach ( $this->proposals as $id ) {
			delete_transient( 'imajiner_design_' . $this->user . '_' . $id );
		}
		foreach ( get_posts( array( 'post_type' => Imajiner_Design_System::REVISION, 'post_status' => 'private', 'numberposts' => -1, 'meta_key' => '_imajiner_design_theme', 'meta_value' => $this->theme ) ) as $post ) {
			wp_delete_post( $post->ID, true );
		}
		delete_option( 'imajiner_design_lock_' . md5( $this->theme ) );
		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		switch_theme( $this->previous_theme );
		$this->remove_directory( $this->directory );
		wp_clean_themes_cache();
		foreach ( array( $this->user, $this->other, $this->author ) as $id ) {
			wp_delete_user( $id );
		}
		if ( false === $this->settings ) {
			delete_option( Imajiner_AI::OPTION );
		} else {
			update_option( Imajiner_AI::OPTION, $this->settings, false );
		}
		wp_set_current_user( 0 );
	}

	private function remove_directory( $path ) {
		foreach ( scandir( $path ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$file = $path . '/' . $name;
			if ( is_dir( $file ) && ! is_link( $file ) ) {
				$this->remove_directory( $file );
			} else {
				unlink( $file );
			}
		}
		rmdir( $path );
	}

	public function http( $preempt, $args, $url ) {
		$this->requests[] = array( 'url' => $url, 'args' => $args );
		if ( isset( $this->responses[ $url ] ) ) {
			$headers = array();
			$data = '';
			$type = 'GET';
			$options = array();
			do_action_ref_array( 'requests-requests.before_request', array( &$url, &$headers, &$data, &$type, &$options ) );
			$this->transport_options[] = $options;
			return $this->responses[ $url ];
		}
		if ( 'https://api.openai.com/v1/chat/completions' === $url ) {
			return $this->response( wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => $this->reply ) ) ) ) ), 'application/json' );
		}
		return new WP_Error( 'network_blocked', 'No live HTTP requests are allowed.' );
	}

	private function response( $body, $mime = 'text/html', $code = 200 ) {
		return array( 'headers' => array( 'content-type' => $mime ), 'response' => array( 'code' => $code, 'message' => 'Mock' ), 'body' => $body );
	}

	private function request( $action, $params = array() ) {
		$request = new WP_REST_Request( in_array( $action, array( 'state', 'revisions' ), true ) ? 'GET' : 'POST', '/imajiner/v1/design-system/' . $action );
		$request->set_body_params( $params );
		$response = rest_do_request( $request );
		if ( isset( $response->get_data()['proposal'] ) ) {
			$this->proposals[] = $response->get_data()['proposal'];
		}
		return $response;
	}

	private function extract( $params = array() ) {
		$state = $this->request( 'state' );
		self::assertSame( 200, $state->get_status(), wp_json_encode( $state->get_data() ) );
		return $this->request( 'extract', array_merge( array( 'prompt' => 'A calm design with clear typography.', 'hash' => $state->get_data()['hash'] ), $params ) );
	}

	private function accept( $proposal, $confirm = true ) {
		return $this->request( 'accept', array( 'proposal' => $proposal, 'confirm' => $confirm ) );
	}

	private function file() {
		return $this->directory . '/' . Imajiner_Design_System::FILE;
	}

	private function existing( $css ) {
		if ( ! is_dir( dirname( $this->file() ) ) ) {
			mkdir( dirname( $this->file() ), 0755, true );
		}
		file_put_contents( $this->file(), $css );
	}

	public function test_review_does_not_write_and_requires_explicit_boolean_confirmation(): void {
		$response = $this->extract();
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertFileDoesNotExist( $this->file() );
		self::assertSame( array(), $data['warnings'] );
		self::assertSame( '#2563eb', $data['before']->{'--imj-color-primary'} );
		self::assertSame( 'Arial, sans-serif', $data['after']->{'--imj-font-body'} );
		self::assertSame( 400, $this->accept( $data['proposal'], false )->get_status() );
		self::assertSame( 400, $this->accept( $data['proposal'], 'true' )->get_status() );
		self::assertSame( 200, $this->accept( $data['proposal'] )->get_status() );
		self::assertSame( $data['afterCss'], file_get_contents( $this->file() ) );
		self::assertSame( 410, $this->accept( $data['proposal'] )->get_status() );
		$posts = get_posts( array( 'post_type' => Imajiner_Design_System::REVISION, 'post_status' => 'private' ) );
		self::assertCount( 1, $posts );
		self::assertFalse( get_post_type_object( Imajiner_Design_System::REVISION )->public );
		self::assertFalse( get_post_type_object( Imajiner_Design_System::REVISION )->show_in_rest );
		self::assertSame( '', $posts[0]->post_content );
	}

	public function test_accepted_css_is_authoritative_across_later_requests(): void {
		$this->existing( ":root { --imj-color-primary: #abcdef; --imj-space-8: 2rem; }\n" );
		$data = $this->extract()->get_data();
		self::assertSame( '2rem', $data['after']->{'--imj-space-8'} );
		self::assertSame( 200, $this->accept( $data['proposal'] )->get_status() );
		self::assertStringContainsString( '#123456', apply_filters( 'imajiner_design_system_prompt', '' ) );
		self::assertSame( '#123456', apply_filters( 'imajiner_design_system_tokens', array() )['--imj-color-primary'] );
		file_put_contents( $this->file(), ":root { --imj-color-primary: #fedcba; }\n" );
		self::assertStringContainsString( '#fedcba', apply_filters( 'imajiner_design_system_prompt', '' ) );
		self::assertStringNotContainsString( '#123456', apply_filters( 'imajiner_design_system_prompt', '' ) );
	}

	public function test_stale_css_and_extraction_hash_never_overwrite_later_changes(): void {
		$data = $this->extract()->get_data();
		$new = ":root { --imj-color-primary: #654321; }\n";
		$this->existing( $new );
		self::assertSame( 409, $this->accept( $data['proposal'] )->get_status() );
		self::assertSame( $new, file_get_contents( $this->file() ) );
		self::assertSame( 409, $this->request( 'extract', array( 'hash' => $data['hash'], 'prompt' => 'Change color' ) )->get_status() );
	}

	public function test_proposal_is_bound_to_user_theme_and_expiry(): void {
		$data = $this->extract()->get_data();
		wp_set_current_user( $this->other );
		self::assertSame( 410, $this->accept( $data['proposal'] )->get_status() );
		wp_set_current_user( $this->user );
		$key = 'imajiner_design_' . $this->user . '_' . $data['proposal'];
		$stored = get_transient( $key );
		$stored['theme'] = 'different-child';
		set_transient( $key, $stored, 60 );
		self::assertSame( 403, $this->accept( $data['proposal'] )->get_status() );
		$stored['theme'] = $this->theme;
		$stored['expires'] = time() - 10;
		set_transient( $key, $stored, 60 );
		self::assertSame( 410, $this->accept( $data['proposal'] )->get_status() );
		self::assertFileDoesNotExist( $this->file() );
	}

	public function test_revision_restore_requires_confirmation_and_current_hash(): void {
		$original = ":root { --imj-color-primary: #abcdef; }\n";
		$this->existing( $original );
		$data = $this->extract()->get_data();
		self::assertSame( 200, $this->accept( $data['proposal'] )->get_status() );
		$history = $this->request( 'revisions' )->get_data();
		self::assertSame( $original, $history[0]['css'] );
		$hash = $this->request( 'state' )->get_data()['hash'];
		self::assertSame( 400, $this->request( 'restore', array( 'revision' => $history[0]['id'], 'hash' => $hash ) )->get_status() );
		self::assertSame( 409, $this->request( 'restore', array( 'revision' => $history[0]['id'], 'hash' => str_repeat( '0', 64 ), 'confirm' => true ) )->get_status() );
		self::assertSame( 200, $this->request( 'restore', array( 'revision' => $history[0]['id'], 'hash' => $hash, 'confirm' => true ) )->get_status() );
		self::assertSame( $original, file_get_contents( $this->file() ) );
		$history = $this->request( 'revisions' )->get_data();
		self::assertCount( 2, $history );
	}

	public function test_restore_of_missing_file_revision_removes_new_file(): void {
		$data = $this->extract()->get_data();
		self::assertSame( 200, $this->accept( $data['proposal'] )->get_status() );
		$history = $this->request( 'revisions' )->get_data();
		$hash = $this->request( 'state' )->get_data()['hash'];
		self::assertSame( 200, $this->request( 'restore', array( 'revision' => $history[0]['id'], 'hash' => $hash, 'confirm' => true ) )->get_status() );
		self::assertFileDoesNotExist( $this->file() );
	}

	public function test_revision_from_another_theme_cannot_be_restored(): void {
		$data = $this->extract()->get_data();
		self::assertSame( 200, $this->accept( $data['proposal'] )->get_status() );
		$revision = $this->request( 'revisions' )->get_data()[0]['id'];
		update_post_meta( $revision, '_imajiner_design_theme', 'another-child' );
		$hash = $this->request( 'state' )->get_data()['hash'];
		self::assertSame( 404, $this->request( 'restore', array( 'revision' => $revision, 'hash' => $hash, 'confirm' => true ) )->get_status() );
		update_post_meta( $revision, '_imajiner_design_theme', $this->theme );
		self::assertSame( $data['afterCss'], file_get_contents( $this->file() ) );
	}

	public function test_accept_revalidates_stored_tokens(): void {
		$data = $this->extract()->get_data();
		$key = 'imajiner_design_' . $this->user . '_' . $data['proposal'];
		$stored = get_transient( $key );
		$stored['tokens']['--imj-color-primary'] = 'url(https://example.invalid/collect)';
		set_transient( $key, $stored, 60 );
		self::assertSame( 422, $this->accept( $data['proposal'] )->get_status() );
		self::assertFileDoesNotExist( $this->file() );
	}

	/** @dataProvider unsafe_tokens */
	public function test_unsafe_ai_tokens_are_rejected( $name, $value ): void {
		$this->reply = wp_json_encode( array( 'tokens' => array( $name => $value ), 'summary' => 'Unsafe proposal' ) );
		$response = $this->extract();
		self::assertSame( 422, $response->get_status() );
		self::assertNotEmpty( $response->get_data()['data']['warnings'] );
		self::assertFileDoesNotExist( $this->file() );
	}

	public function unsafe_tokens(): array {
		return array(
			array( '--imj-color-primary', 'red; } body { color: red' ),
			array( '--imj-font-family-base', 'url(https://example.invalid/collect)' ),
			array( '--imj-space-4', '1rem/*hidden*/' ),
			array( '--imj-space-4', 'var(--secret)' ),
			array( '--imj-color-primary', '\\72 ed' ),
			array( '--imj-space-4', '@import "x"' ),
			array( '--other-color-primary', '#fff' ),
			array( '--imj-font-family-base', '"unclosed' ),
			array( '--imj-color-primary', array( '#fff' ) ),
		);
	}

	public function test_malformed_schema_and_existing_non_token_css_are_not_rewritten(): void {
		$this->reply = '{}';
		self::assertSame( 422, $this->extract()->get_status() );
		$this->existing( 'body { color: red; }' );
		self::assertSame( 422, $this->request( 'state' )->get_status() );
		self::assertSame( 'body { color: red; }', file_get_contents( $this->file() ) );
	}

	public function test_parent_theme_and_symlink_writes_are_rejected(): void {
		switch_theme( 'imajiner' );
		self::assertSame( 403, $this->request( 'state' )->get_status() );
		switch_theme( $this->theme );
		symlink( get_template_directory(), $this->directory . '/assets' );
		self::assertSame( 403, $this->request( 'state' )->get_status() );
		unlink( $this->directory . '/assets' );
		$this->existing( ':root { --imj-color-primary: #fff; }' );
		unlink( $this->file() );
		symlink( get_template_directory() . '/assets/css/base.css', $this->file() );
		self::assertSame( 403, $this->request( 'state' )->get_status() );
	}

	public function test_endpoints_require_edit_themes_and_jobs_require_owner(): void {
		wp_set_current_user( 0 );
		foreach ( array( 'state', 'extract', 'accept', 'revisions', 'restore' ) as $action ) {
			self::assertSame( 403, $this->request( $action )->get_status() );
		}
		wp_set_current_user( $this->user );
		self::assertWPErrorCode( 'imajiner_design_job_owner', Imajiner_Design_System::handle_job( array( 'user' => $this->other, 'theme' => $this->theme ) ) );
	}

	public function test_file_edit_policy_blocks_all_routes(): void {
		define( 'DISALLOW_FILE_EDIT', true );
		foreach ( array( 'state', 'extract', 'accept', 'revisions', 'restore' ) as $action ) {
			self::assertSame( 403, $this->request( $action )->get_status() );
		}
	}

	public function test_reference_stylesheet_failures_are_visible_review_warnings(): void {
		$url = 'https://example.org/design-test';
		$this->responses[ $url ] = $this->response( '<link rel="stylesheet" href="/unsafe.css"><style>h1{color:#123456;}</style>' );
		$this->responses['https://example.org/unsafe.css'] = $this->response( '', 'text/css', 302 );
		$response = $this->extract( array( 'url' => $url ) );
		self::assertSame( 200, $response->get_status() );
		self::assertNotEmpty( $response->get_data()['warnings'] );
		self::assertFileDoesNotExist( $this->file() );
	}

	private static function assertWPErrorCode( $code, $value ): void {
		self::assertInstanceOf( WP_Error::class, $value );
		self::assertSame( $code, $value->get_error_code() );
	}

	public function test_reference_url_ssrf_and_schemes_are_rejected_before_network(): void {
		foreach ( array( 'http://example.org/', 'https://127.0.0.1/', 'https://10.0.0.1/', 'https://169.254.169.254/', 'https://[::1]/', 'https://localhost/', 'https://example.org:8443/', 'https://user:pass@example.org/', 'https://site.internal/' ) as $url ) {
			self::assertTrue( is_wp_error( Imajiner_Design_System::reference_data( $url ) ), $url );
		}
		self::assertSame( array(), $this->requests );
	}

	public function test_non_global_reference_addresses_are_rejected_before_network(): void {
		foreach ( array( '100.64.0.1', '100.127.255.255', '192.0.0.8', '192.0.2.1', '192.88.99.1', '198.18.0.1', '198.19.255.255', '198.51.100.1', '203.0.113.1', '224.0.0.1', '239.255.255.255' ) as $ip ) {
			$url = 'https://' . $ip . '/';
			self::assertWPErrorCode( 'imajiner_design_url', Imajiner_Design_System::validate_reference_url( $url ) );
			self::assertWPErrorCode( 'imajiner_design_url', Imajiner_Design_System::reference_data( $url ) );
		}
		self::assertSame( array(), $this->requests );
	}

	public function test_public_address_ranges_and_ipv6_special_use_boundaries(): void {
		$method = new ReflectionMethod( Imajiner_Design_System::class, 'public_address' );
		$method->setAccessible( true );
		foreach ( array( 'not-an-ip', '127.0.0.1', '10.0.0.1', '240.0.0.1', '::1', '::ffff:8.8.8.8', 'fc00::1', 'fe80::1', 'ff02::1', '2001:1ff::1', '2001:db8::1', '2002:0808:0808::1', '3ffe::1', '3fff:fff::1' ) as $ip ) {
			self::assertFalse( $method->invoke( null, $ip ), $ip );
		}
		foreach ( array( '8.8.8.8', '100.63.255.255', '100.128.0.0', '198.17.255.255', '198.20.0.0', '2001:4860:4860::8888', '2606:4700:4700::1111', '2001:200::1', '3fff:1000::1' ) as $ip ) {
			self::assertTrue( $method->invoke( null, $ip ), $ip );
		}
	}

	public function test_bounded_reference_styles_are_data_and_only_same_origin_css_is_fetched(): void {
		$url = 'https://example.org/design-test';
		$this->responses[ $url ] = $this->response( '<html><head><style>:root {--brand: #112233;} h1 {font-size: 3rem; color: #abcdef;}</style><link rel="stylesheet" href="/tokens.css"><link rel="stylesheet" href="https://example.net/private.css"><script>alert("never execute")</script></head><body style="padding: 2rem; background-image: url(https://example.invalid/collect)"></body></html>' );
		$this->responses['https://example.org/tokens.css'] = $this->response( '.card { gap: 1.5rem; font-family: Arial, sans-serif; } @import "https://example.invalid/ignore.css";', 'text/css' );
		$response = $this->extract( array( 'prompt' => '', 'url' => $url ) );
		self::assertSame( 200, $response->get_status() );
		self::assertCount( 3, $this->requests );
		self::assertSame( 0, $this->requests[0]['args']['redirection'] );
		self::assertSame( 5, $this->requests[0]['args']['timeout'] );
		self::assertSame( Imajiner_Design_System::MAX_REFERENCE + 1, $this->requests[0]['args']['limit_response_size'] );
		self::assertSame( 'WpOrg\\Requests\\Transport\\Curl', $this->transport_options[0]['transport'] );
		self::assertTrue( $this->transport_options[0]['verifyname'] );
		self::assertFalse( $this->transport_options[0]['follow_redirects'] );
		self::assertFileExists( $this->transport_options[0]['verify'] );
		$body = json_decode( $this->requests[2]['args']['body'], true );
		$prompt = $body['messages'][1]['content'];
		self::assertStringContainsString( '#112233', $prompt );
		self::assertStringContainsString( '1.5rem', $prompt );
		self::assertStringNotContainsString( 'never execute', $prompt );
		self::assertStringNotContainsString( 'collect', $prompt );
		self::assertStringContainsString( 'untrusted data', $prompt );
	}

	public function test_reference_redirect_mime_and_size_rejections(): void {
		$url = 'https://example.org/design-test';
		foreach ( array( $this->response( '', 'text/html', 302 ), $this->response( '<?php echo 1;', 'application/x-httpd-php' ), $this->response( str_repeat( 'x', Imajiner_Design_System::MAX_REFERENCE + 1 ) ) ) as $response ) {
			$this->responses[ $url ] = $response;
			self::assertSame( 422, $this->extract( array( 'url' => $url ) )->get_status() );
		}
	}

	public function test_reference_fetches_at_most_three_stylesheets_and_does_not_follow_imports(): void {
		$url = 'https://example.org/design-test';
		$html = '';
		for ( $i = 1; $i <= 5; ++$i ) {
			$html .= '<link rel="stylesheet" href="/style-' . $i . '.css">';
			$this->responses['https://example.org/style-' . $i . '.css'] = $this->response( '.item {color: #abcdef;} @import "/import.css";', 'text/css' );
		}
		$this->responses[ $url ] = $this->response( $html );
		$result = Imajiner_Design_System::reference_data( $url );
		self::assertFalse( is_wp_error( $result ) );
		self::assertCount( 4, $this->requests );
		self::assertSame( 'https://example.org/style-3.css', $this->requests[3]['url'] );
	}

	private function image( $owner = null ) {
		$upload = wp_upload_bits( $this->theme . '.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aR1sAAAAASUVORK5CYII=' ) );
		self::assertFalse( $upload['error'] );
		$id = wp_insert_attachment( array( 'post_title' => 'Disposable screenshot', 'post_mime_type' => 'image/png', 'post_author' => $owner ?? $this->user ), $upload['file'] );
		$this->attachments[] = $id;
		return $id;
	}

	public function test_screenshot_only_passes_actual_image_url_in_multimodal_message(): void {
		$id = $this->image();
		$response = $this->extract( array( 'prompt' => '', 'attachment' => $id ) );
		self::assertSame( 200, $response->get_status() );
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$content = $body['messages'][1]['content'];
		self::assertSame( 'image_url', $content[1]['type'] );
		self::assertSame( 'data:image/png;base64,' . base64_encode( file_get_contents( get_attached_file( $id ) ) ), $content[1]['image_url']['url'] );
		self::assertStringNotContainsString( get_attached_file( $id ), $this->requests[0]['args']['body'] );
	}

	public function test_screenshot_ownership_type_size_and_outside_uploads_are_enforced(): void {
		$id = $this->image();
		wp_set_current_user( $this->author );
		self::assertTrue( is_wp_error( Imajiner_Design_System::screenshot( $id ) ) );
		wp_set_current_user( $this->user );
		$file = get_attached_file( $id );
		file_put_contents( $file, '<svg onload="alert(1)"></svg>' );
		self::assertTrue( is_wp_error( Imajiner_Design_System::screenshot( $id ) ) );
		file_put_contents( $file, str_repeat( 'x', Imajiner_Design_System::MAX_IMAGE + 1 ) );
		clearstatcache( true, $file );
		self::assertTrue( is_wp_error( Imajiner_Design_System::screenshot( $id ) ) );
		update_post_meta( $id, '_wp_attached_file', $this->directory . '/style.css' );
		self::assertTrue( is_wp_error( Imajiner_Design_System::screenshot( $id ) ) );
		update_attached_file( $id, $file );
	}

	public function test_partial_transport_failure_rolls_back_and_retains_revision(): void {
		if ( ! class_exists( 'Imajiner_Design_Test_Filesystem', false ) ) {
			self::markTestSkipped( 'Fault injection uses the isolated filesystem contract adapter.' );
		}
		$original = ':root { --imj-color-primary: #aabbcc; }';
		$this->existing( $original );
		$data = $this->extract()->get_data();
		Imajiner_Design_Test_Filesystem::$failure = 'partial_move';
		self::assertSame( 500, $this->accept( $data['proposal'] )->get_status() );
		self::assertSame( $original, file_get_contents( $this->file() ) );
		self::assertCount( 1, $this->request( 'revisions' )->get_data() );
		self::assertSame( array(), glob( dirname( $this->file() ) . '/.imj-design-*.tmp' ) );
		Imajiner_Design_Test_Filesystem::$failure = '';
		self::assertSame( 200, $this->accept( $data['proposal'] )->get_status() );
	}

	public function test_staging_failure_and_concurrent_lock_never_modify_css(): void {
		$data = $this->extract()->get_data();
		$lock = 'imajiner_design_lock_' . md5( $this->theme );
		add_option( $lock, time(), '', false );
		self::assertSame( 409, $this->accept( $data['proposal'] )->get_status() );
		self::assertFileDoesNotExist( $this->file() );
		delete_option( $lock );
		if ( class_exists( 'Imajiner_Design_Test_Filesystem', false ) ) {
			Imajiner_Design_Test_Filesystem::$failure = 'write';
			self::assertSame( 500, $this->accept( $data['proposal'] )->get_status() );
			self::assertFileDoesNotExist( $this->file() );
			self::assertSame( array(), $this->request( 'revisions' )->get_data() );
		}
	}

	public function test_expired_crash_lock_can_be_recovered(): void {
		$data = $this->extract()->get_data();
		$lock = 'imajiner_design_lock_' . md5( $this->theme );
		add_option( $lock, time() - 600, '', false );
		self::assertSame( 200, $this->accept( $data['proposal'] )->get_status() );
		self::assertSame( $data['afterCss'], file_get_contents( $this->file() ) );
	}
}
