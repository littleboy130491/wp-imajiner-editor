<?php

use PHPUnit\Framework\TestCase;

final class GenerationTest extends TestCase {
	private static $user;
	private static $other_user;
	private static $fixtures = array();
	private $settings;
	private $name;
	private $proposals = array();
	private $replies = array();
	private $requests = array();

	public static function setUpBeforeClass(): void {
		$suffix           = wp_generate_password( 10, false );
		self::$user       = wp_insert_user( array( 'user_login' => 'imj-test-' . $suffix, 'user_pass' => $suffix, 'role' => 'administrator' ) );
		self::$other_user = wp_insert_user( array( 'user_login' => 'imj-test-other-' . $suffix, 'user_pass' => $suffix, 'role' => 'administrator' ) );
		foreach ( array( 'page', 'location', 'part' ) as $type ) {
			$slug = 'imj-test-' . strtolower( $suffix ) . '-' . $type;
			$key  = 'part' === $type ? 'parts/' . $slug : $slug;
			$path = get_stylesheet_directory() . '/imajiner/' . $key . '.php';
			$php  = self::php( $slug );
			if ( 'part' === $type ) {
				$php = "<?php\n/**\n * Part Name: $slug\n * Part Location: tha_footer_before\n * Part Description: Keep this description\n */\n?>\n<section><h1>Before</h1></section>";
			} elseif ( 'location' === $type ) {
				$php = str_replace( ' * Template Name: ' . $slug, " * Imajiner Location: archive, search\n * Template Post Type: post", $php );
			}
			$files = array( 'php' => $php, 'css' => '.imj-' . $slug . ' .hero { color: var(--imj-color-primary); }' );
			self::assertTrue( Imajiner_Template_Store::create( $path, $files ) );
			self::$fixtures[ $type ] = array( 'slug' => $slug, 'key' => $key, 'path' => $path, 'files' => $files );
		}
	}

	public static function tearDownAfterClass(): void {
		foreach ( self::$fixtures as $fixture ) {
			foreach ( Imajiner_Template_Store::get_revisions( $fixture['path'] ) as $revision ) {
				wp_delete_post( $revision['id'], true );
			}
			unlink( $fixture['path'] );
			unlink( Imajiner_Template_Store::css_path( $fixture['path'] ) );
		}
		wp_delete_user( self::$user );
		wp_delete_user( self::$other_user );
		wp_clean_themes_cache( false );
	}

	protected function setUp(): void {
		$this->settings = get_option( Imajiner_AI::OPTION, false );
		$this->name = 'imj-test-' . substr( wp_generate_uuid4(), 0, 8 );
		wp_set_current_user( self::$user );
		update_option( Imajiner_AI::OPTION, array( 'primary' => array( 'provider' => 'openai', 'model' => 'test-model' ), 'system_prompt_append' => 'Test site instructions.' ), false );
		add_filter( 'pre_http_request', array( $this, 'http' ), 999, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'http' ), 999 );
		if ( false === $this->settings ) {
			delete_option( Imajiner_AI::OPTION );
		} else {
			update_option( Imajiner_AI::OPTION, $this->settings, false );
		}
		foreach ( $this->proposals as $id ) {
			delete_transient( 'imajiner_ai_' . self::$user . '_' . $id );
		}
		$path = get_stylesheet_directory() . '/imajiner/' . $this->name . '.php';
		foreach ( array( $path, Imajiner_Template_Store::css_path( $path ) ) as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'title' => $this->name, 'numberposts' => -1 ) ) as $page ) {
			wp_delete_post( $page->ID, true );
		}
		wp_set_current_user( 0 );
	}

	public function http( $preempt, $args, $url ) {
		$this->requests[] = json_decode( $args['body'], true );
		$reply = array_shift( $this->replies );
		if ( null === $reply ) {
			return new WP_Error( 'unexpected_request', 'No network calls are allowed by this test.' );
		}
		return array( 'headers' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => $reply ) ) ) ) ) );
	}

	private static function php( $name ): string {
		return "<?php\n/**\n * Template Name: $name\n */\nget_header();\n?>\n<!-- imj:section name=\"hero\" -->\n<section class=\"hero\"><h1>Hello</h1></section>\n<!-- /imj:section -->\n<?php get_footer();\n";
	}

	private function reply( $name, $php = null, $css = null ): string {
		return wp_json_encode( array( 'slug' => $name, 'name' => $name, 'php' => $php ?? self::php( $name ), 'css' => $css ?? '.imj-' . $name . ' .hero { color: var(--imj-color-primary); }' ) );
	}

	private function request( $action, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/imajiner/v1/ai/' . $action );
		$request->set_body_params( $params );
		$response = rest_do_request( $request );
		if ( isset( $response->get_data()['proposal'] ) ) {
			$this->proposals[] = $response->get_data()['proposal'];
		}
		return $response;
	}

	private function generate(): WP_REST_Response {
		return $this->request( 'generate', array( 'name' => $this->name, 'prompt' => 'Create a hero.' ) );
	}

	public function test_create_requires_confirmation_and_assigns_a_draft(): void {
		$this->replies[] = $this->reply( $this->name );
		$response = $this->generate();
		self::assertSame( 200, $response->get_status() );
		$proposal = $response->get_data();
		$path = get_stylesheet_directory() . '/imajiner/' . $this->name . '.php';
		self::assertFileDoesNotExist( $path );
		self::assertSame( array(), $proposal['warnings'] );
		self::assertStringNotContainsString( 'get_header', $proposal['afterMarkup'] );
		self::assertStringContainsString( 'Test site instructions.', $this->requests[0]['messages'][0]['content'] );
		self::assertSame( 200, $this->request( 'accept', array( 'proposal' => $proposal['proposal'] ) )->get_status() );
		self::assertSame( $proposal['after'], Imajiner_Template_Store::read( $path ) );
		$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'draft', 'title' => $this->name ) );
		self::assertCount( 1, $pages );
		self::assertSame( 'imajiner/' . $this->name . '.php', get_page_template_slug( $pages[0] ) );
		self::assertSame( 410, $this->request( 'accept', array( 'proposal' => $proposal['proposal'] ) )->get_status() );
	}

	public function test_invalid_output_retries_once_with_validation_feedback(): void {
		$this->replies = array( $this->reply( $this->name, null, 'body { color: red; }' ), $this->reply( $this->name ) );
		self::assertSame( 200, $this->generate()->get_status() );
		self::assertCount( 2, $this->requests );
		self::assertStringContainsString( 'CSS must contain balanced rules', $this->requests[1]['messages'][3]['content'] );
	}

	public function test_invalid_retry_never_saves(): void {
		$this->replies = array( '{}', '{}' );
		$response = $this->generate();
		self::assertSame( 422, $response->get_status() );
		self::assertNotEmpty( $response->get_data()['data']['warnings'] );
		self::assertCount( 2, $this->requests );
		self::assertFileDoesNotExist( get_stylesheet_directory() . '/imajiner/' . $this->name . '.php' );
	}

	public function test_proposals_are_owned_by_the_requesting_user_and_expire(): void {
		$this->replies[] = $this->reply( $this->name );
		$id = $this->generate()->get_data()['proposal'];
		wp_set_current_user( self::$other_user );
		self::assertSame( 410, $this->request( 'accept', array( 'proposal' => $id ) )->get_status() );
		wp_set_current_user( self::$user );
		update_option( '_transient_timeout_imajiner_ai_' . self::$user . '_' . $id, time() - 1 );
		self::assertSame( 410, $this->request( 'accept', array( 'proposal' => $id ) )->get_status() );
	}

	public function test_permissions_and_request_schema(): void {
		wp_set_current_user( 0 );
		self::assertSame( 401, $this->generate()->get_status() );
		wp_set_current_user( self::$user );
		self::assertSame( 400, $this->request( 'generate', array( 'key' => '../outside' ) )->get_status() );
		self::assertSame( 400, $this->request( 'generate', array( 'name' => $this->name ) )->get_status() );
		self::assertSame( 400, $this->request( 'generate', array( 'name' => $this->name, 'prompt' => str_repeat( 'a', 20001 ) ) )->get_status() );
		self::assertSame( 404, $this->request( 'generate', array( 'key' => 'missing-test-template' ) )->get_status() );
		self::assertSame( 400, $this->request( 'accept', array( 'proposal' => '../../outside' ) )->get_status() );
		self::assertCount( 0, $this->requests );
	}

	public function test_theme_changes_invalidate_proposals(): void {
		$this->replies[] = $this->reply( $this->name );
		$id = $this->generate()->get_data()['proposal'];
		$key = 'imajiner_ai_' . self::$user . '_' . $id;
		$proposal = get_transient( $key );
		$proposal['stylesheet'] = 'another-child-theme';
		set_transient( $key, $proposal, HOUR_IN_SECONDS );
		self::assertSame( 409, $this->request( 'accept', array( 'proposal' => $id ) )->get_status() );
	}

	public function test_css_collisions_do_not_overwrite_existing_files(): void {
		$this->replies[] = $this->reply( $this->name );
		$id = $this->generate()->get_data()['proposal'];
		$path = get_stylesheet_directory() . '/imajiner/' . $this->name . '.php';
		$css = Imajiner_Template_Store::css_path( $path );
		file_put_contents( $css, 'existing stylesheet' );
		self::assertSame( 409, $this->request( 'accept', array( 'proposal' => $id ) )->get_status() );
		self::assertSame( 'existing stylesheet', file_get_contents( $css ) );
		self::assertFileDoesNotExist( $path );
	}

	/** @dataProvider templateTypes */
	public function test_normalize_preserves_headers_and_keeps_both_files_as_a_revision( string $type ): void {
		$fixture = self::$fixtures[ $type ];
		$php = str_replace( 'Hello', 'After', self::php( $fixture['slug'] ) );
		if ( 'part' === $type ) {
			$php = "<?php\n/**\n * Part Name: {$fixture['slug']}\n */\n?>\n<section class=\"hero\"><h1>After</h1></section>";
		}
		$scope = 'part' === $type ? '.imj-part-' : '.imj-';
		$this->replies[] = $this->reply( $fixture['slug'], $php, $scope . $fixture['slug'] . ' .hero { color: var(--imj-color-secondary); }' );
		$response = $this->request( 'generate', array( 'key' => $fixture['key'] ) );
		self::assertSame( 200, $response->get_status() );
		$proposal = $response->get_data();
		self::assertSame( $fixture['files'], Imajiner_Template_Store::read( $fixture['path'] ) );
		self::assertSame( 200, $this->request( 'accept', array( 'proposal' => $proposal['proposal'] ) )->get_status() );
		self::assertSame( $proposal['after'], Imajiner_Template_Store::read( $fixture['path'] ) );
		foreach ( array( 'Template Name', 'Template Post Type', 'Imajiner Location', 'Part Name', 'Part Location', 'Part Description' ) as $header ) {
			if ( preg_match( '/ \* ' . $header . ': ([^\n]+)/', $fixture['files']['php'], $match ) ) {
				self::assertStringContainsString( $match[0], $proposal['after']['php'] );
			}
		}
		$revisions = Imajiner_Template_Store::get_revisions( $fixture['path'] );
		self::assertSame( $fixture['files'], Imajiner_Template_Store::get_revision_files( $fixture['path'], $revisions[0]['id'] ) );
	}

	public static function templateTypes(): array {
		return array( array( 'page' ), array( 'location' ), array( 'part' ) );
	}

	public function test_normalize_conflict_preserves_the_newer_source(): void {
		$fixture = self::$fixtures['page'];
		$this->replies[] = $this->reply( $fixture['slug'] );
		$id = $this->request( 'generate', array( 'key' => $fixture['key'] ) )->get_data()['proposal'];
		$path = Imajiner_Template_Store::css_path( $fixture['path'] );
		$newer = file_get_contents( $path ) . '\n/* Changed after generation. */';
		file_put_contents( $path, $newer );
		self::assertSame( 409, $this->request( 'accept', array( 'proposal' => $id ) )->get_status() );
		self::assertSame( $newer, file_get_contents( $path ) );
	}

	/** @dataProvider invalidReplies */
	public function test_reply_validation_rejects_invalid_output( string $kind ): void {
		$context = array( 'slug' => 'test-page', 'name' => 'test-page', 'type' => 'page', 'scope' => '.imj-test-page', 'headers' => array( 'Template Name' => 'test-page' ) );
		$data = json_decode( $this->reply( 'test-page' ), true );
		switch ( $kind ) {
			case 'json': $data = null; break;
			case 'missing': unset( $data['css'] ); break;
			case 'type': $data['php'] = array(); break;
			case 'slug': $data['slug'] = 'other'; break;
			case 'name': $data['name'] = 'other'; break;
			case 'syntax': $data['php'] .= '<?php if ('; break;
			case 'header': $data['php'] = '<section>Hello</section>'; break;
			case 'sections': $data['php'] = str_replace( array( '<!-- imj:section name="hero" -->', '<!-- /imj:section -->' ), '', $data['php'] ); break;
			case 'inline': $data['php'] = str_replace( '<h1>', '<h1 style="color:red">', $data['php'] ); break;
			case 'style': $data['php'] = str_replace( '<h1>', '<style>body{color:red}</style><h1>', $data['php'] ); break;
			case 'header_call': $data['php'] = str_replace( 'get_header();', '', $data['php'] ); break;
			case 'footer_call': $data['php'] = str_replace( 'get_footer();', '', $data['php'] ); break;
			case 'css': $data['css'] = 'body { color: red; }'; break;
		}
		$result = Imajiner_Generation::validate_reply( wp_json_encode( $data ), $context );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 422, $result->get_error_data()['status'] );
	}

	public static function invalidReplies(): array {
		return array_map( static function ( $kind ) { return array( $kind ); }, array( 'json', 'missing', 'type', 'slug', 'name', 'syntax', 'header', 'sections', 'inline', 'style', 'header_call', 'footer_call', 'css' ) );
	}

	public function test_parts_cannot_render_the_document_header_and_footer(): void {
		$context = array( 'slug' => 'test-part', 'name' => 'test-part', 'type' => 'part', 'scope' => '.imj-part-test-part', 'headers' => array( 'Part Name' => 'test-part' ) );
		$result = Imajiner_Generation::validate_reply( $this->reply( 'test-part', null, '' ), $context );
		self::assertInstanceOf( WP_Error::class, $result );
	}

	/** @dataProvider stylesheets */
	public function test_stylesheet_scope( string $css, bool $valid ): void {
		$result = Imajiner_Css_Editor::validate_scope( $css, '.imj-test' );
		self::assertSame( $valid, true === $result );
	}

	public static function stylesheets(): array {
		return array(
			array( '', true ),
			array( '.imj-test { color: var(--imj-color-primary); }', true ),
			array( '.imj-test .hero, .imj-test > .card:hover { color: red; }', true ),
			array( '@media (max-width: 767px) { .imj-test .hero { padding: var(--imj-space-3); } }', true ),
			array( '@supports (display: grid) { @media (max-width: 767px) { .imj-test .hero { display: grid; } } }', true ),
			array( '.imj-test .hero { content: "brace }"; }', true ),
			array( '.imj-test-other .hero { color: red; }', false ),
			array( '.imj-test + .outside { color: red; }', false ),
			array( '.imj-test ~ .outside { color: red; }', false ),
			array( '.imj-test /**/ + .outside { color: red; }', false ),
			array( '.imj-test .hero, body { color: red; }', false ),
			array( '@media (max-width: 767px) { body { color: red; } }', false ),
			array( '@import url("remote.css");', false ),
			array( '@keyframes move { from { opacity: 0; } }', false ),
			array( '.imj-test .hero { & .card { color: red; } }', false ),
			array( '.imj-test .hero { color: red;', false ),
			array( '.imj-test .hero { color: red; } }', false ),
			array( '.imj-test .hero { color: red; } /* unclosed', false ),
			array( '.imj-test .hero { content: "unclosed; }', false ),
		);
	}
}
