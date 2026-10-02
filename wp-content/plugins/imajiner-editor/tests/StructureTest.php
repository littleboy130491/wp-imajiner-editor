<?php

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class StructureTest extends TestCase {
	private static $user;
	private static $other;
	private $key;
	private $path;
	private $files;
	private $stages = array();

	public static function setUpBeforeClass(): void {
		$suffix = wp_generate_uuid4();
		self::$user = wp_insert_user( array( 'user_login' => 'imj-structure-' . $suffix, 'user_pass' => $suffix, 'role' => 'administrator' ) );
		self::$other = wp_insert_user( array( 'user_login' => 'imj-structure-other-' . $suffix, 'user_pass' => $suffix, 'role' => 'administrator' ) );
	}

	public static function tearDownAfterClass(): void {
		wp_delete_user( self::$user );
		wp_delete_user( self::$other );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$user );
		$this->key = 'imj-structure-' . substr( wp_generate_uuid4(), 0, 8 );
		$this->path = get_stylesheet_directory() . '/imajiner/' . $this->key . '.php';
		$this->files = array(
			'php' => "<?php\n/**\n * Template Name: {$this->key}\n */\nget_header(); ?>\n<!-- imj:section name=\"hero\" -->\n<section class=\"hero\"><h2>A</h2><p>B</p><img src=\"\" alt=\"Image\"></section>\n<!-- /imj:section -->\n<!-- imj:section name=\"cta\" -->\n<div><p>C</p></div>\n<!-- /imj:section -->\n<?php get_footer(); ?>",
			'css' => '.imj-' . $this->key . ' .hero { color: red; }',
		);
		self::assertTrue( Imajiner_Template_Store::create( $this->path, $this->files ) );
		wp_clean_themes_cache( false );
	}

	protected function tearDown(): void {
		foreach ( $this->stages as $id ) {
			delete_transient( 'imajiner_stage_' . self::$user . '_' . $id );
		}
		foreach ( Imajiner_Template_Store::get_revisions( $this->path ) as $revision ) {
			wp_delete_post( $revision['id'], true );
		}
		unlink( $this->path );
		unlink( Imajiner_Template_Store::css_path( $this->path ) );
		wp_clean_themes_cache( false );
	}

	private function request( $action, array $changes, $hash = null ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/imajiner/v1/templates/' . $this->key . '/' . $action );
		$request->set_body_params( array( 'hash' => $hash ?: Imajiner_Template_Store::hash( $this->files ), 'changes' => $changes ) );
		$response = rest_do_request( $request );
		if ( 'stage' === $action && 200 === $response->get_status() ) {
			$this->stages[] = $response->get_data()['stage'];
		}
		return $response;
	}

	private function operation( array $operation ): array {
		return array( 'type' => 'structure', 'operation' => $operation );
	}

	/** @dataProvider operations */
	public function test_structural_edits_preserve_php_and_leave_disk_untouched( array $operation, $needle, $count ): void {
		$response = $this->request( 'stage', array( $this->operation( $operation ) ) );
		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$template = Imajiner_Editor::get_template( $this->key );
		$files = Imajiner_Preview::staged_files( $template, $response->get_data()['stage'] );
		self::assertIsArray( $files );
		self::assertSame( $count, substr_count( $files['php'], $needle ) );
		self::assertSame( ( new Imajiner_Template_Scanner( $this->files['php'] ) )->get_php_sources(), ( new Imajiner_Template_Scanner( $files['php'] ) )->get_php_sources() );
		self::assertSame( array(), $response->get_data()['structure']['warnings'] );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		self::assertSame( array(), Imajiner_Template_Store::get_revisions( $this->path ) );
		if ( 'move' === $operation['type'] ) {
			self::assertLessThan( strpos( $files['php'], '<h2>A</h2>' ), strpos( $files['php'], $needle ) );
		}
	}

	public function operations(): array {
		return array(
			array( array( 'type' => 'delete', 'id' => 'e2' ), '<p>B</p>', 0 ),
			array( array( 'type' => 'duplicate', 'id' => 'e1' ), '<h2>A</h2>', 2 ),
			array( array( 'type' => 'delete', 'id' => 'e3' ), '<img', 0 ),
			array( array( 'type' => 'insert', 'target' => 'e0', 'position' => 'inside', 'starter' => 'paragraph' ), '<p>New paragraph</p>', 1 ),
			array( array( 'type' => 'insert', 'target' => 'e0', 'position' => 'inside', 'starter' => 'heading' ), '<h2>New heading</h2>', 1 ),
			array( array( 'type' => 'insert', 'target' => 'e1', 'position' => 'after', 'starter' => 'link' ), '<a href="#">New link</a>', 1 ),
			array( array( 'type' => 'insert', 'target' => 'e1', 'position' => 'after', 'starter' => 'image' ), '<img src="" alt="New image">', 1 ),
			array( array( 'type' => 'insert', 'target' => 'e0', 'position' => 'inside', 'starter' => 'div' ), '<div><p>New content</p></div>', 1 ),
			array( array( 'type' => 'duplicate', 'id' => 's0' ), 'name="hero"', 2 ),
			array( array( 'type' => 'delete', 'id' => 's0' ), 'name="hero"', 0 ),
			array( array( 'type' => 'move', 'id' => 'e2', 'target' => 'e1', 'position' => 'before' ), '<p>B</p>', 1 ),
			array( array( 'type' => 'move', 'id' => 's1', 'target' => 's0', 'position' => 'before' ), 'name="cta"', 1 ),
			array( array( 'type' => 'insert', 'target' => 'root', 'position' => 'inside', 'starter' => 'section' ), 'name="new-section"', 1 ),
		);
	}

	public function test_batches_remap_ids_and_save_one_revision_of_both_files(): void {
		$changes = array(
			array( 'type' => 'batch', 'changes' => array( array( 'type' => 'text', 'id' => 't0', 'value' => 'Edited A' ) ) ),
			$this->operation( array( 'type' => 'duplicate', 'id' => 'e1' ) ),
			array( 'type' => 'batch', 'changes' => array(
				array( 'type' => 'text', 'id' => 't1', 'value' => 'Copied A' ),
				array( 'type' => 'style', 'class' => 'hero', 'property' => 'color', 'value' => 'blue', 'device' => 'desktop' ),
			) ),
			$this->operation( array( 'type' => 'move', 'id' => 'e3', 'target' => 'e1', 'position' => 'before' ) ),
			array( 'type' => 'batch', 'changes' => array( array( 'type' => 'attr', 'id' => 'e1', 'name' => 'class', 'value' => 'body' ) ) ),
		);
		$staged = $this->request( 'stage', $changes );
		self::assertSame( 200, $staged->get_status(), wp_json_encode( $staged->get_data() ) );
		$template = Imajiner_Editor::get_template( $this->key );
		$preview = Imajiner_Preview::staged_files( $template, $staged->get_data()['stage'] );
		self::assertStringContainsString( '<p class="body">B</p>', $preview['php'] );
		self::assertStringContainsString( '<h2>Copied A</h2>', $preview['php'] );
		self::assertStringContainsString( '<h2>Edited A</h2>', $preview['php'] );
		self::assertStringContainsString( 'blue', $preview['css'] );
		self::assertLessThan( strpos( $preview['php'], 'Edited A' ), strpos( $preview['php'], '>B</p>' ) );
		$saved = $this->request( 'save', $changes );
		self::assertSame( 200, $saved->get_status() );
		self::assertSame( $preview, Imajiner_Template_Store::read( $this->path ) );
		$revisions = Imajiner_Template_Store::get_revisions( $this->path );
		self::assertCount( 1, $revisions );
		self::assertSame( $this->files, Imajiner_Template_Store::get_revision_files( $this->path, $revisions[0]['id'] ) );
		self::assertSame( 409, $this->request( 'save', $changes )->get_status() );
		self::assertSame( 'imajiner_stage_conflict', Imajiner_Preview::staged_files( $template, $staged->get_data()['stage'] )->get_error_code() );
	}

	public function test_stages_are_private_and_expire(): void {
		$response = $this->request( 'stage', array( $this->operation( array( 'type' => 'duplicate', 'id' => 'e1' ) ) ) );
		$id = $response->get_data()['stage'];
		$template = Imajiner_Editor::get_template( $this->key );
		wp_set_current_user( self::$other );
		self::assertWPError( Imajiner_Preview::staged_files( $template, $id ) );
		wp_set_current_user( 0 );
		self::assertSame( 401, $this->request( 'stage', array( $this->operation( array( 'type' => 'delete', 'id' => 'e1' ) ) ) )->get_status() );
		wp_set_current_user( self::$user );
		delete_transient( 'imajiner_stage_' . self::$user . '_' . $id );
		self::assertWPError( Imajiner_Preview::staged_files( $template, $id ) );
	}

	public function test_preview_executes_the_staged_source_and_uses_its_css(): void {
		$response = $this->request( 'stage', array(
			$this->operation( array( 'type' => 'duplicate', 'id' => 'e1' ) ),
			array( 'type' => 'batch', 'changes' => array( array( 'type' => 'style', 'class' => 'hero', 'property' => 'color', 'value' => 'blue', 'device' => 'desktop' ) ) ),
		) );
		$_GET[ Imajiner_Preview::QUERY_VAR ] = wp_create_nonce( Imajiner_Preview::QUERY_VAR . '_' . $this->key );
		$_GET[ Imajiner_Preview::TEMPLATE_VAR ] = $this->key;
		$_GET['imajiner_stage'] = $response->get_data()['stage'];
		$instrumented = Imajiner_Preview::template_include( $this->path );
		self::assertNotSame( $this->path, $instrumented );
		$source = file_get_contents( $instrumented );
		self::assertSame( 2, substr_count( $source, '<h2 data-imj-id=' ) );
		self::assertSame( ( new Imajiner_Template_Scanner( $this->files['php'] ) )->get_php_sources(), array_slice( ( new Imajiner_Template_Scanner( $source ) )->get_php_sources(), 1 ) );
		Imajiner_Preview::enqueue_assets();
		self::assertStringContainsString( 'blue', wp_styles()->registered['imajiner-stage']->extra['after'][0] );
		wp_delete_file( $instrumented );
		unset( $_GET[ Imajiner_Preview::QUERY_VAR ], $_GET[ Imajiner_Preview::TEMPLATE_VAR ], $_GET['imajiner_stage'] );
	}

	private static function assertWPError( $result ): void {
		self::assertInstanceOf( WP_Error::class, $result );
	}

	public function test_dynamic_subtrees_are_locked_but_static_children_can_change(): void {
		$source = str_replace( '<p>B</p>', '<p><?php the_title(); ?></p>', $this->files['php'] );
		$scanner = new Imajiner_Template_Scanner( $source );
		foreach ( array( 's0', 'e0', 'e2' ) as $id ) {
			foreach ( array( 'delete', 'duplicate', 'move' ) as $type ) {
				self::assertWPError( $scanner->apply_structure( array( 'type' => $type, 'id' => $id, 'target' => 's1', 'position' => 'before' ) ) );
			}
		}
		$result = $scanner->apply_structure( array( 'type' => 'duplicate', 'id' => 'e1' ) );
		self::assertIsString( $result );
		self::assertSame( $scanner->get_php_sources(), ( new Imajiner_Template_Scanner( $result ) )->get_php_sources() );
		self::assertWPError( $scanner->apply_structure( array( 'type' => 'move', 'id' => 'e1', 'target' => 'e3', 'position' => 'after' ) ) );
	}

	/** @dataProvider invalidOperations */
	public function test_invalid_operations_cannot_write( array $operation ): void {
		$response = $this->request( 'save', array( $this->operation( $operation ) ) );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		self::assertSame( array(), Imajiner_Template_Store::get_revisions( $this->path ) );
	}

	public function test_deleting_every_section_is_rejected(): void {
		$response = $this->request( 'save', array(
			$this->operation( array( 'type' => 'delete', 'id' => 's0' ) ),
			$this->operation( array( 'type' => 'delete', 'id' => 's0' ) ),
		) );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_root_insert_needs_a_text_position_footer(): void {
		$source = str_replace( '<?php get_footer(); ?>', '<div data-footer="<?php get_footer(); ?>"></div>', $this->files['php'] );
		$scanner = new Imajiner_Template_Scanner( $source );
		self::assertWPError( $scanner->apply_structure( array( 'type' => 'insert', 'target' => 'root', 'position' => 'inside', 'starter' => 'section' ) ) );
	}

	public function invalidOperations(): array {
		return array(
			array( array( 'type' => 'delete', 'id' => 'p0' ) ),
			array( array( 'type' => 'move', 'id' => 'e0', 'target' => 'e1', 'position' => 'before' ) ),
			array( array( 'type' => 'move', 'id' => 'e1', 'target' => 'e5', 'position' => 'after' ) ),
			array( array( 'type' => 'move', 'id' => 'e1', 'target' => 'e1', 'position' => 'before' ) ),
			array( array( 'type' => 'insert', 'target' => 'e3', 'position' => 'inside', 'starter' => 'paragraph' ) ),
			array( array( 'type' => 'insert', 'target' => 'root', 'starter' => 'paragraph' ) ),
			array( array( 'type' => 'insert', 'target' => 'e0', 'starter' => '<?php exit;' ) ),
			array( array( 'type' => 'duplicate', 'id' => 'e999' ) ),
			array( array( 'type' => array( 'delete' ), 'id' => 's0' ) ),
		);
	}
}
