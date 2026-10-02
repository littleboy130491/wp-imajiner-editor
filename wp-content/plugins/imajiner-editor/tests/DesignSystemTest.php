<?php

use PHPUnit\Framework\TestCase;

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
require_once dirname( __DIR__ ) . '/includes/class-imajiner-design-system.php';

/* The separate filesystem unit is loaded by integration, not this branch. */
if ( ! class_exists( 'Imajiner_Filesystem' ) ) {
	class Imajiner_Filesystem {
		public static function init() {
			return true;
		}
		public static function exists( $path ) {
			return $GLOBALS['wp_filesystem']->exists( $path );
		}
		public static function read( $path ) {
			$result = $GLOBALS['wp_filesystem']->get_contents( $path );
			return false === $result ? new WP_Error( 'read', 'Fixture read failed.' ) : $result;
		}
		public static function write( $path, $contents ) {
			return $GLOBALS['wp_filesystem']->put_contents( $path, $contents ) ? true : new WP_Error( 'write', 'Fixture write failed.' );
		}
		public static function mkdir( $path ) {
			return $GLOBALS['wp_filesystem']->mkdir( $path ) ? true : new WP_Error( 'mkdir', 'Fixture mkdir failed.' );
		}
		public static function delete( $path ) {
			return $GLOBALS['wp_filesystem']->delete( $path ) ? true : new WP_Error( 'delete', 'Fixture delete failed.' );
		}
		public static function move( $from, $to, $overwrite = false ) {
			return $GLOBALS['wp_filesystem']->move( $from, $to, $overwrite ) ? true : new WP_Error( 'move', 'Fixture move failed.' );
		}
	}
}

class DesignSystemTestFilesystem extends WP_Filesystem_Direct {
	public $fail_move = '';
	public $staged_callback;
	public $fail_write = false;

	public function put_contents( $file, $contents, $mode = false ) {
		if ( $this->fail_write ) {
			return false;
		}
		$result = parent::put_contents( $file, $contents, $mode );
		if ( $this->staged_callback && false !== strpos( $file, '.tmp' ) ) {
			$callback = $this->staged_callback;
			$this->staged_callback = null;
			$callback();
		}
		return $result;
	}

	public function move( $from, $to, $overwrite = false ) {
		$failure = $this->fail_move;
		$this->fail_move = '';
		if ( 'before' === $failure ) {
			return false;
		}
		if ( 'missing' === $failure ) {
			$this->delete( $to );
			return false;
		}
		$result = parent::move( $from, $to, $overwrite );
		return 'after' === $failure ? false : $result;
	}
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DesignSystemTest extends TestCase {
	private static $theme;
	private static $previous_theme;
	private static $user;
	private static $other_user;
	private static $reader;
	private $settings;
	private $filesystem;
	private $proposals = array();
	private $attachments = array();
	private $http_requests = array();
	private $ai_reply;
	private $ai_callback;
	private $reference_reply;

	public static function setUpBeforeClass(): void {
		self::$previous_theme = get_stylesheet();
		self::$theme = 'imj-design-test-' . substr( wp_generate_uuid4(), 0, 8 );
		$root = get_theme_root() . '/' . self::$theme;
		wp_mkdir_p( $root );
		file_put_contents( $root . '/style.css', "/*\nTheme Name: Disposable design-system fixture\nTemplate: imajiner\nVersion: 1.0\n*/\n" );
		wp_clean_themes_cache();
		switch_theme( self::$theme );
		self::$user = wp_insert_user( array( 'user_login' => self::$theme, 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
		self::$other_user = wp_insert_user( array( 'user_login' => self::$theme . '-other', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
		self::$reader = wp_insert_user( array( 'user_login' => self::$theme . '-reader', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		Imajiner_Design_System::register_revision_type();
		Imajiner_Design_System::register_routes();
	}

	public static function tearDownAfterClass(): void {
		switch_theme( self::$previous_theme );
		$fs = new WP_Filesystem_Direct( null );
		$fs->delete( get_theme_root() . '/' . self::$theme, true );
		foreach ( array( self::$user, self::$other_user, self::$reader ) as $user ) {
			wp_delete_user( $user );
		}
		wp_clean_themes_cache();
	}

	protected function setUp(): void {
		switch_theme( self::$theme );
		wp_set_current_user( self::$user );
		WP_Filesystem();
		$this->filesystem = new DesignSystemTestFilesystem( null );
		$GLOBALS['wp_filesystem'] = $this->filesystem;
		$this->settings = get_option( Imajiner_AI::OPTION, false );
		update_option( Imajiner_AI::OPTION, array( 'primary' => array( 'provider' => 'openai', 'model' => 'fixture-model' ) ), false );
		$this->ai_reply = wp_json_encode( array( 'summary' => 'A restrained blue palette.', 'tokens' => array( '--imj-color-primary' => '#336699', '--imj-font-body' => 'system-ui, sans-serif', '--imj-space-3' => '1.25rem' ) ) );
		$this->reference_reply = array( 'headers' => array( 'content-type' => 'text/html; charset=utf-8' ), 'response' => array( 'code' => 200 ), 'body' => '<style>body { color: #123456; font-family: Arial; padding: 2rem; }</style>' );
		add_filter( 'pre_http_request', array( $this, 'http' ), 999, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'http' ), 999 );
		remove_filter( 'wp_get_attachment_url', array( $this, 'public_image_url' ) );
		foreach ( $this->attachments as $attachment ) {
			wp_delete_attachment( $attachment, true );
		}
		foreach ( $this->proposals as $proposal ) {
			delete_transient( 'imj_design_' . self::$user . '_' . $proposal );
		}
		foreach ( get_posts( array( 'post_type' => Imajiner_Design_System::REVISION_TYPE, 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_imj_design_theme', 'meta_value' => self::$theme ) ) as $revision ) {
			wp_delete_post( $revision->ID, true );
		}
		$fs = new WP_Filesystem_Direct( null );
		$assets = get_theme_root() . '/' . self::$theme . '/assets';
		if ( is_link( $assets ) ) {
			unlink( $assets );
		} else {
			$fs->delete( $assets, true );
		}
		$this->settings ? update_option( Imajiner_AI::OPTION, $this->settings, false ) : delete_option( Imajiner_AI::OPTION );
		wp_set_current_user( 0 );
	}

	public function http( $preempt, $args, $url ) {
		$this->http_requests[] = array( 'url' => $url, 'args' => $args );
		if ( 'https://api.openai.com/v1/chat/completions' === $url ) {
			if ( $this->ai_callback ) {
				$callback = $this->ai_callback;
				$this->ai_callback = null;
				$callback();
			}
			return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => $this->ai_reply ) ) ) ) ) );
		}
		if ( 0 === strpos( $url, 'https://example.org/' ) ) {
			if ( false !== strpos( $url, '.css' ) ) {
				return array( 'headers' => array( 'content-type' => 'text/css' ), 'response' => array( 'code' => 200 ), 'body' => 'h1 { font-size: 3rem; background-color: #abcdef; }' );
			}
			return $this->reference_reply;
		}
		return new WP_Error( 'no_network', 'Unexpected network request blocked by fixture.' );
	}

	private function request( $action, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( in_array( $action, array( 'current', 'revisions' ), true ) ? 'GET' : 'POST', '/imajiner/v1/design-system/' . $action );
		$request->set_body_params( $params );
		$response = rest_do_request( $request );
		if ( isset( $response->get_data()['proposal'] ) ) {
			$this->proposals[] = $response->get_data()['proposal'];
		}
		return $response;
	}

	private function extract( array $params = array() ): WP_REST_Response {
		return $this->request( 'extract', array_merge( array( 'prompt' => 'Use blue and generous spacing.' ), $params ) );
	}

	private function path(): string {
		return get_stylesheet_directory() . '/' . Imajiner_Design_System::FILE;
	}

	private function seed( $css ): void {
		wp_mkdir_p( dirname( $this->path() ) );
		file_put_contents( $this->path(), $css );
	}

	private function accept( $id, $confirmed = true ): WP_REST_Response {
		return $this->request( 'accept', array( 'proposal' => $id, 'confirmed' => $confirmed ) );
	}

	public function test_prompt_proposal_requires_explicit_acceptance_and_css_survives_sessions(): void {
		$response = $this->extract();
		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$id = $response->get_data()['proposal'];
		self::assertFileDoesNotExist( $this->path() );
		self::assertSame( 400, $this->accept( $id, false )->get_status() );
		self::assertFileDoesNotExist( $this->path() );
		self::assertSame( 200, $this->accept( $id )->get_status() );
		self::assertSame( '#336699', Imajiner_Design_System::tokens()['--imj-color-primary'] );
		self::assertSame( '1.25rem', Imajiner_Design_System::parse_css( file_get_contents( $this->path() ) )['--imj-space-3'] );
		self::assertSame( 410, $this->accept( $id )->get_status() );
		wp_set_current_user( self::$other_user );
		self::assertSame( '#336699', $this->request( 'current' )->get_data()['tokens']['--imj-color-primary'] );
		self::assertStringContainsString( '#336699', Imajiner_Design_System::append_system_prompt( 'Template contract' ) );
		self::assertSame( '#336699', Imajiner_Design_System::merge_tokens( array( '--imj-color-primary' => '#000000' ) )->{'--imj-color-primary'} );
		$revisions = $this->request( 'revisions' )->get_data();
		self::assertCount( 1, $revisions );
		$post = get_post( $revisions[0]['id'] );
		self::assertSame( 'private', $post->post_status );
		self::assertSame( '', $post->post_content );
		self::assertFalse( get_post_type_object( $post->post_type )->public );
	}

	public function test_partial_extraction_preserves_other_existing_overrides(): void {
		$this->seed( ':root { --imj-color-primary: #111111; --imj-radius: 12px; }' );
		$response = $this->extract();
		self::assertSame( '12px', $response->get_data()['after']['tokens']['--imj-radius'] );
		self::assertSame( 200, $this->accept( $response->get_data()['proposal'] )->get_status() );
		self::assertSame( '12px', Imajiner_Design_System::tokens()['--imj-radius'] );
		$revisions = $this->request( 'revisions' )->get_data();
		self::assertSame( ':root { --imj-color-primary: #111111; --imj-radius: 12px; }', get_post( $revisions[0]['id'] )->post_content );
	}

	public function test_changed_css_conflicts_and_later_changes_are_preserved(): void {
		$id = $this->extract()->get_data()['proposal'];
		$this->seed( ':root { --imj-color-primary: #123456; }' );
		self::assertSame( 409, $this->accept( $id )->get_status() );
		self::assertSame( '#123456', Imajiner_Design_System::tokens()['--imj-color-primary'] );
		self::assertSame( array(), $this->request( 'revisions' )->get_data() );
	}

	public function test_file_appearance_and_during_ai_changes_conflict(): void {
		$this->ai_callback = function () { $this->seed( '' ); };
		self::assertSame( 409, $this->extract()->get_status() );
		self::assertSame( '', file_get_contents( $this->path() ) );
	}

	public function test_concurrent_change_during_staging_is_never_overwritten(): void {
		$id = $this->extract()->get_data()['proposal'];
		$this->filesystem->staged_callback = function () { $this->seed( ':root { --imj-color-primary: #123456; }' ); };
		self::assertSame( 409, $this->accept( $id )->get_status() );
		self::assertSame( '#123456', Imajiner_Design_System::tokens()['--imj-color-primary'] );
		self::assertSame( array(), glob( dirname( $this->path() ) . '/*.tmp' ) );
	}

	public function test_proposals_are_private_expiring_and_bound_to_user_and_theme(): void {
		$id = $this->extract()->get_data()['proposal'];
		wp_set_current_user( self::$other_user );
		self::assertSame( 410, $this->accept( $id )->get_status() );
		wp_set_current_user( self::$user );
		$key = 'imj_design_' . self::$user . '_' . $id;
		$data = get_transient( $key );
		self::assertSame( array( 'user', 'theme', 'path', 'hash', 'tokens', 'expires' ), array_keys( $data ) );
		$data['theme'] = 'different-child';
		set_transient( $key, $data, 1800 );
		self::assertSame( 403, $this->accept( $id )->get_status() );
		$data['theme'] = self::$theme;
		$data['expires'] = time() - 1;
		set_transient( $key, $data, 1800 );
		self::assertSame( 410, $this->accept( $id )->get_status() );
		self::assertFileDoesNotExist( $this->path() );
	}

	public function test_all_endpoints_reject_readers_and_parent_only_theme(): void {
		wp_set_current_user( self::$reader );
		foreach ( array( 'current', 'revisions', 'extract', 'accept', 'restore' ) as $action ) {
			self::assertSame( 403, $this->request( $action )->get_status() );
		}
		wp_set_current_user( self::$user );
		switch_theme( 'imajiner' );
		foreach ( array( 'current', 'revisions', 'extract', 'accept', 'restore' ) as $action ) {
			self::assertSame( 403, $this->request( $action )->get_status() );
		}
	}

	public function test_symlinked_token_directory_is_rejected_before_ai_or_write(): void {
		$outside = wp_get_upload_dir()['basedir'];
		wp_mkdir_p( $outside );
		symlink( $outside, get_stylesheet_directory() . '/assets' );
		self::assertSame( 403, $this->extract()->get_status() );
		self::assertSame( array(), $this->http_requests );
	}

	/** @dataProvider unsafe_values */
	public function test_unsafe_ai_css_is_rejected_without_creating_a_file( $name, $value ): void {
		$this->ai_reply = wp_json_encode( array( 'summary' => 'Untrusted result.', 'tokens' => array( $name => $value ) ) );
		self::assertSame( 422, $this->extract()->get_status() );
		self::assertFileDoesNotExist( $this->path() );
	}

	public static function unsafe_values(): array {
		return array(
			array( '--imj-color-primary', 'url(https://example.org/collect)' ),
			array( '--imj-color-primary', '#fff; } body { color: red' ),
			array( '--imj-color-primary', '/* hidden */#fff' ),
			array( '--imj-color-primary', '@import "https://example.org/x"' ),
			array( '--imj-color-primary', '\\75rl(https://example.org/x)' ),
			array( '--imj-font-body', 'Arial; background: url(x)' ),
			array( '--imj-font-body', '</style><script>alert(1)</script>' ),
			array( '--not-imj', '10px' ),
			array( '--imj-space-1', "1px\n!important" ),
			array( '--imj-space-1', array( 'invalid' ) ),
		);
	}

	public function test_numeric_fonts_colors_and_aliases_validate_and_unresolved_alias_warns(): void {
		$tokens = array( '--imj-font-body' => '"Segoe UI", Arial, sans-serif', '--imj-color-primary' => 'rgba(20, 30, 40, 0.5)', '--imj-text-2xl' => 'clamp(2rem, 4vw, 3rem)', '--imj-space-3' => '.5rem', '--imj-leading' => '1.6', '--imj-font-heading' => 'var(--imj-font-body)' );
		self::assertIsArray( Imajiner_Design_System::validate_tokens( $tokens ) );
		$this->ai_reply = wp_json_encode( array( 'summary' => 'Alias review.', 'tokens' => array( '--imj-radius' => 'var(--imj-unknown)' ) ) );
		$response = $this->extract();
		self::assertSame( 200, $response->get_status() );
		self::assertNotEmpty( $response->get_data()['warnings'] );
	}

	public function test_arbitrary_existing_css_is_preserved_and_not_replaced(): void {
		$this->seed( 'body { color: red; }' );
		self::assertSame( 422, $this->extract()->get_status() );
		self::assertSame( 'body { color: red; }', file_get_contents( $this->path() ) );
		self::assertSame( array(), $this->http_requests );
	}

	public function test_invalid_schema_and_duplicate_existing_declarations_are_rejected(): void {
		$this->ai_reply = '{"summary":"bad","tokens":{},"php":"<?php exit;"}';
		self::assertSame( 422, $this->extract()->get_status() );
		self::assertTrue( is_wp_error( Imajiner_Design_System::parse_css( ':root { --imj-radius: 1px; --imj-radius: 2px; }' ) ) );
		self::assertSame( 400, $this->extract( array( 'prompt' => '' ) )->get_status() );
	}

	/** @dataProvider failed_moves */
	public function test_filesystem_failures_preserve_or_restore_original_tokens( $failure ): void {
		$this->seed( ':root { --imj-color-primary: #111111; }' );
		$id = $this->extract()->get_data()['proposal'];
		$this->filesystem->fail_move = $failure;
		self::assertGreaterThanOrEqual( 400, $this->accept( $id )->get_status() );
		self::assertSame( ':root { --imj-color-primary: #111111; }', file_get_contents( $this->path() ) );
		self::assertSame( array(), glob( dirname( $this->path() ) . '/*.tmp' ) );
	}

	public static function failed_moves(): array {
		return array( array( 'before' ), array( 'after' ), array( 'missing' ) );
	}

	public function test_staging_write_failure_never_replaces_css_or_creates_revision(): void {
		$this->seed( ':root { --imj-radius: 5px; }' );
		$id = $this->extract()->get_data()['proposal'];
		$this->filesystem->fail_write = true;
		self::assertGreaterThanOrEqual( 400, $this->accept( $id )->get_status() );
		self::assertSame( ':root { --imj-radius: 5px; }', file_get_contents( $this->path() ) );
		self::assertSame( array(), $this->request( 'revisions' )->get_data() );
	}

	public function test_revision_restore_creates_review_then_requires_confirmation(): void {
		$this->seed( ':root { --imj-color-primary: #111111; }' );
		$id = $this->extract()->get_data()['proposal'];
		self::assertSame( 200, $this->accept( $id )->get_status() );
		$revision = $this->request( 'revisions' )->get_data()[0]['id'];
		$response = $this->request( 'restore', array( 'revision' => $revision ) );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( '#336699', Imajiner_Design_System::tokens()['--imj-color-primary'] );
		self::assertSame( '#111111', $response->get_data()['after']['tokens']['--imj-color-primary'] );
		self::assertSame( 200, $this->accept( $response->get_data()['proposal'] )->get_status() );
		self::assertSame( '#111111', Imajiner_Design_System::tokens()['--imj-color-primary'] );
		self::assertSame( 404, $this->request( 'restore', array( 'revision' => 9999999 ) )->get_status() );
	}

	/** @dataProvider unsafe_urls */
	public function test_reference_ssrf_inputs_are_blocked_without_http( $url ): void {
		self::assertSame( 400, $this->extract( array( 'prompt' => '', 'url' => $url ) )->get_status() );
		self::assertSame( array(), $this->http_requests );
	}

	public static function unsafe_urls(): array {
		return array( array( 'http://example.org/' ), array( 'https://127.0.0.1/' ), array( 'https://[::1]/' ), array( 'https://169.254.169.254/latest/meta-data/' ), array( 'https://localhost/' ), array( 'https://user:pass@example.org/' ), array( 'https://example.org:8443/' ), array( 'file:///etc/passwd' ) );
	}

	public function test_reference_samples_css_without_executing_scripts_or_following_external_sheets(): void {
		$this->reference_reply['body'] = '<script>fetch("https://example.org/execute")</script><link rel="stylesheet" href="https://external.invalid/evil.css"><link rel="stylesheet" href="/one.css"><link rel="stylesheet" href="/two.css"><link rel="stylesheet" href="/three.css"><link rel="stylesheet" href="/four.css"><style>body { color: #123456; padding: 2rem; background: url(https://example.org/track); }</style><p style="font-family: Arial; margin: 1rem">Hello</p>';
		$response = $this->extract( array( 'prompt' => '', 'url' => 'https://example.org/reference' ) );
		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		self::assertCount( 5, $this->http_requests );
		$body = json_decode( end( $this->http_requests )['args']['body'], true );
		self::assertStringContainsString( '#123456', $body['messages'][1]['content'] );
		self::assertStringContainsString( '3rem', $body['messages'][1]['content'] );
		self::assertStringContainsString( 'Arial', $body['messages'][1]['content'] );
		self::assertStringNotContainsString( 'fetch(', $body['messages'][1]['content'] );
		self::assertStringNotContainsString( 'track', $body['messages'][1]['content'] );
		foreach ( array_slice( $this->http_requests, 0, 4 ) as $http ) {
			self::assertSame( 0, $http['args']['redirection'] );
			self::assertSame( 8, $http['args']['timeout'] );
			self::assertTrue( $http['args']['reject_unsafe_urls'] );
			self::assertNotEmpty( $http['args']['limit_response_size'] );
		}
	}

	public function test_reference_redirect_wrong_mime_and_oversize_are_rejected(): void {
		$this->reference_reply['response']['code'] = 302;
		self::assertSame( 400, $this->extract( array( 'url' => 'https://example.org/reference' ) )->get_status() );
		$this->reference_reply['response']['code'] = 200;
		$this->reference_reply['headers']['content-type'] = 'application/javascript';
		self::assertSame( 400, $this->extract( array( 'url' => 'https://example.org/reference' ) )->get_status() );
		$this->reference_reply['headers']['content-type'] = 'text/html';
		$this->reference_reply['body'] = str_repeat( 'x', 262145 );
		self::assertSame( 400, $this->extract( array( 'url' => 'https://example.org/reference' ) )->get_status() );
	}

	public function public_image_url( $url ): string {
		return 'https://example.org/design-fixture.png';
	}

	private function image(): int {
		$uploads = wp_upload_dir();
		$path = $uploads['path'] . '/imj-design-test-' . wp_generate_uuid4() . '.png';
		$chunk = static function ( $type, $data ) {
			return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
		};
		$png = "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNC5', 2, 2, 8, 2, 0, 0, 0 ) );
		$png .= $chunk( 'IDAT', gzcompress( str_repeat( "\0\x33\x66\x99\x33\x66\x99", 2 ) ) ) . $chunk( 'IEND', '' );
		file_put_contents( $path, $png );
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Disposable design fixture', 'post_author' => self::$user ), $path );
		$this->attachments[] = $id;
		add_filter( 'wp_get_attachment_url', array( $this, 'public_image_url' ) );
		return $id;
	}

	public function test_screenshot_only_uses_real_attachment_image_url_content(): void {
		$image = $this->image();
		$response = $this->extract( array( 'prompt' => '', 'attachment' => $image ) );
		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$body = json_decode( end( $this->http_requests )['args']['body'], true );
		self::assertSame( 'image_url', $body['messages'][1]['content'][1]['type'] );
		self::assertSame( 'https://example.org/design-fixture.png', $body['messages'][1]['content'][1]['image_url']['url'] );
		self::assertFileDoesNotExist( $this->path() );
	}

	public function test_forged_oversize_and_outside_upload_attachment_are_rejected(): void {
		$image = $this->image();
		$file = get_attached_file( $image );
		file_put_contents( $file, "\x89PNG\r\n\x1a\n" );
		self::assertSame( 'image/png', wp_get_image_mime( $file ) );
		self::assertSame( 400, $this->extract( array( 'attachment' => $image ) )->get_status() );
		file_put_contents( $file, 'Not an actual PNG' );
		self::assertSame( 400, $this->extract( array( 'attachment' => $image ) )->get_status() );
		update_post_meta( $image, '_wp_attached_file', get_stylesheet_directory() . '/style.css' );
		self::assertSame( 400, $this->extract( array( 'attachment' => $image ) )->get_status() );
		update_post_meta( $image, '_wp_attached_file', $file );
		$this->attachments = array_diff( $this->attachments, array( $image ) );
		wp_delete_attachment( $image, true );
		$image = $this->image();
		$file = get_attached_file( $image );
		file_put_contents( $file, str_repeat( 'x', Imajiner_Design_System::IMAGE_BYTES + 1 ), FILE_APPEND );
		self::assertSame( 400, $this->extract( array( 'attachment' => $image ) )->get_status() );
		self::assertSame( array(), $this->http_requests );
	}

	public function test_screenshot_checks_ownership_and_capability_even_for_theme_editors(): void {
		$image = $this->image();
		$user = new WP_User( self::$reader );
		$user->add_cap( 'edit_themes' );
		$user->add_cap( 'upload_files' );
		wp_set_current_user( self::$reader );
		self::assertSame( 403, $this->extract( array( 'attachment' => $image ) )->get_status() );
		$user->remove_cap( 'edit_themes' );
		$user->remove_cap( 'upload_files' );
		self::assertSame( array(), $this->http_requests );
	}

	public function test_background_handler_rechecks_owner_and_hash_without_calling_ai(): void {
		$hash = $this->request( 'current' )->get_data()['hash'];
		$payload = array( 'user' => self::$other_user, 'theme' => self::$theme, 'hash' => $hash, 'prompt' => 'Blue', 'url' => '', 'attachment' => 0 );
		self::assertSame( 'imajiner_design_owner', Imajiner_Design_System::run_extraction( $payload )->get_error_code() );
		$payload['user'] = self::$user;
		$this->seed( '' );
		self::assertSame( 'imajiner_design_conflict', Imajiner_Design_System::run_extraction( $payload )->get_error_code() );
		self::assertSame( array(), $this->http_requests );
	}

	public function test_reserved_and_ipv4_mapped_dns_addresses_are_never_public(): void {
		foreach ( array( '100.64.0.1', '192.0.0.1', '198.18.0.1', '192.0.2.1', '203.0.113.1', '224.0.0.1', '::ffff:127.0.0.1', '2001:db8::1', '2002:7f00:1::1', 'fc00::1', 'fe80::1' ) as $address ) {
			self::assertFalse( Imajiner_Design_System::public_address( $address ), $address );
		}
		self::assertTrue( Imajiner_Design_System::public_address( '8.8.8.8' ) );
		self::assertTrue( Imajiner_Design_System::public_address( '2001:4860:4860::8888' ) );
	}

	public function test_disallow_file_edit_blocks_every_design_endpoint(): void {
		define( 'DISALLOW_FILE_EDIT', true );
		foreach ( array( 'current', 'revisions', 'extract', 'accept', 'restore' ) as $action ) {
			self::assertSame( 403, $this->request( $action )->get_status() );
		}
		self::assertSame( array(), $this->http_requests );
	}
}
