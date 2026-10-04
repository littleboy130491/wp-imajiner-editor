<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-imajiner-editor-locks.php';
require_once dirname( __DIR__ ) . '/includes/class-imajiner-section-library.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class EditorFeaturesTest extends TestCase {
	private $user;
	private $other;
	private $key;
	private $path;
	private $files;
	private $stages = array();
	private $breakpoints;

	protected function setUp(): void {
		$suffix = wp_generate_uuid4();
		$this->user = wp_insert_user( array( 'user_login' => 'imj-editor-' . $suffix, 'user_pass' => $suffix, 'role' => 'administrator' ) );
		$this->other = wp_insert_user( array( 'user_login' => 'imj-editor-other-' . $suffix, 'user_pass' => $suffix, 'role' => 'administrator' ) );
		wp_set_current_user( $this->user );
		$this->key = 'imj-editor-' . substr( $suffix, 0, 8 );
		$this->path = get_stylesheet_directory() . '/imajiner/' . $this->key . '.php';
		$this->files = array(
			'php' => "<?php\n/**\n * Template Name: {$this->key}\n */\nget_header(); ?>\n<!-- imj:section name=\"hero\" -->\n<section class=\"hero\"><p>Hello <strong>bold</strong> and <a href=\"#\">link</a> <?php the_title(); ?>!</p></section>\n<!-- /imj:section -->\n<!-- imj:section name=\"static\" -->\n<section><p>Static</p></section>\n<!-- /imj:section -->\n<?php get_footer(); ?>",
			'css' => '.imj-' . $this->key . ' .hero { color: red; }',
		);
		$this->breakpoints = get_option( 'imajiner_editor_breakpoints' );
		self::assertTrue( Imajiner_Template_Store::create( $this->path, $this->files ) );
		wp_clean_themes_cache( false );
	}

	protected function tearDown(): void {
		foreach ( array( $this->user, $this->other ) as $user ) {
			wp_set_current_user( $user );
			Imajiner_Editor_Locks::release( $this->key );
			foreach ( $this->stages as $stage ) { delete_transient( 'imajiner_stage_' . $user . '_' . $stage ); }
			wp_delete_user( $user );
		}
		foreach ( Imajiner_Template_Store::get_revisions( $this->path ) as $revision ) { wp_delete_post( $revision['id'], true ); }
		unlink( $this->path );
		unlink( Imajiner_Template_Store::css_path( $this->path ) );
		if ( false === $this->breakpoints ) { delete_option( 'imajiner_editor_breakpoints' ); } else { update_option( 'imajiner_editor_breakpoints', $this->breakpoints ); }
		wp_clean_themes_cache( false );
	}

	private function request( $action, $params = array(), $method = 'POST' ) {
		$request = new WP_REST_Request( $method, '/imajiner/v1/templates/' . $this->key . '/' . $action );
		$request->set_body_params( array_merge( array( 'hash' => Imajiner_Template_Store::hash( $this->files ), 'changes' => array() ), $params ) );
		$response = rest_do_request( $request );
		if ( 'stage' === $action && 200 === $response->get_status() ) { $this->stages[] = $response->get_data()['stage']; }
		return $response;
	}

	private function staged( $response ) {
		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return Imajiner_Preview::staged_files( Imajiner_Editor::get_template( $this->key ), $response->get_data()['stage'] );
	}

	public function test_text_markers_and_mixed_edits_preserve_markup_and_php(): void {
		$scanner = new Imajiner_Template_Scanner( $this->files['php'] );
		$preview = $scanner->get_instrumented_source();
		self::assertStringContainsString( '<!--imj-text:t0-->Hello <!--/imj-text:t0--><strong', $preview );
		self::assertStringContainsString( '<!--imj-text:t1-->bold<!--/imj-text:t1-->', $preview );
		self::assertStringContainsString( '<!--imj-text:t4-->!<!--/imj-text:t4-->', $preview );
		self::assertSame( $scanner->get_php_sources(), ( new Imajiner_Template_Scanner( $preview ) )->get_php_sources() );
		$changed = $scanner->apply_changes( array( array( 'type' => 'text', 'id' => 't2', 'value' => 'plus' ) ) );
		self::assertStringContainsString( '</strong> plus <a href="#">link</a>', $changed );
		self::assertSame( $scanner->get_php_sources(), ( new Imajiner_Template_Scanner( $changed ) )->get_php_sources() );
	}

	public function test_replacement_preserves_outside_bytes_and_rejects_php_bearing_selection(): void {
		$scanner = new Imajiner_Template_Scanner( $this->files['php'] );
		$source = $scanner->get_node_source( 'e4' );
		self::assertSame( '<section><p>Static</p></section>', $source );
		self::assertSame( str_replace( $source, '<section><h2>Accepted</h2></section>', $this->files['php'] ), $scanner->replace_node( 'e4', '<section><h2>Accepted</h2></section>' ) );
		self::assertInstanceOf( WP_Error::class, $scanner->replace_node( 's0', '<section>Replacement</section>' ) );
		self::assertSame( '<?php the_title(); ?>', $scanner->get_node_source( 'p1' ) );
	}

	public function test_preview_markers_do_not_replace_literal_source_tokens(): void {
		$source = '<p title="IMAJINER_TEXT_MARKER_0">A &amp; B</p><!-- IMAJINER_TEXT_MARKER_0 --><?php echo esc_html( "IMAJINER_TEXT_MARKER_0" ); ?>';
		$scanner = new Imajiner_Template_Scanner( $source, array( 'require_sections' => false ) );
		$preview = $scanner->get_instrumented_source();
		self::assertStringContainsString( 'title="IMAJINER_TEXT_MARKER_0"', $preview );
		self::assertStringContainsString( '<!-- IMAJINER_TEXT_MARKER_0 -->', $preview );
		self::assertStringContainsString( '<!--imj-text:t0-->A &amp; B<!--/imj-text:t0-->', $preview );
		self::assertSame( $scanner->get_php_sources(), ( new Imajiner_Template_Scanner( $preview ) )->get_php_sources() );
	}

	public function test_text_edit_markers_do_not_replace_literal_attribute_values(): void {
		$token = "\u{E000}imj-text:0\u{E000}";
		$source = '<p title="' . $token . '">Before</p><a href="#">Other</a>';
		$scanner = new Imajiner_Template_Scanner( $source, array( 'require_sections' => false ) );
		self::assertSame( str_replace( '>Before<', '>After<', $source ), $scanner->apply_changes( array( array( 'type' => 'text', 'id' => 't0', 'value' => 'After' ) ) ) );
	}

	public function test_text_edit_markers_do_not_replace_simultaneous_attribute_edits(): void {
		$scanner = new Imajiner_Template_Scanner( '<p>Before</p>', array( 'require_sections' => false ) );
		$changes = array(
			array( 'type' => 'text', 'id' => 't0', 'value' => 'IMAJINER_TEXT_MARKER__0' ),
			array( 'type' => 'attr', 'id' => 'e0', 'name' => 'title', 'value' => 'IMAJINER_TEXT_MARKER_0' ),
		);
		self::assertSame( '<p title="IMAJINER_TEXT_MARKER_0">IMAJINER_TEXT_MARKER__0</p>', $scanner->apply_changes( $changes ) );
	}

	public function test_many_preview_text_markers_keep_original_text_and_node_ids(): void {
		$source = '';
		for ( $i = 0; $i < 130; ++$i ) { $source .= '<p title="Item ' . $i . '">Item &amp; ' . $i . '</p>'; }
		$scanner = new Imajiner_Template_Scanner( $source, array( 'require_sections' => false ) );
		$preview = $scanner->get_instrumented_source();
		for ( $i = 0; $i < 130; ++$i ) {
			self::assertStringContainsString( 'data-imj-id="e' . $i . '"', $preview );
			self::assertStringContainsString( '<!--imj-text:t' . $i . '-->Item &amp; ' . $i . '<!--/imj-text:t' . $i . '-->', $preview );
		}
	}

	public function test_preview_cache_invalidates_when_scanner_instrumentation_changes(): void {
		$_GET[ Imajiner_Preview::TEMPLATE_VAR ] = $this->key;
		$_GET[ Imajiner_Preview::QUERY_VAR ] = wp_create_nonce( Imajiner_Preview::QUERY_VAR . '_' . $this->key );
		$directory = new ReflectionMethod( Imajiner_Preview::class, 'cache_dir' );
		$directory->setAccessible( true );
		$dir = $directory->invoke( null );
		$prefix = substr( md5( $this->path ), 0, 8 ) . '-' . basename( $this->path, '.php' );
		$legacy = $dir . $prefix . '-' . md5( $this->files['php'] . IMAJINER_EDITOR_VERSION ) . '.php';
		$preview = null;
		try {
			file_put_contents( $legacy, '<?php /* Stale instrumentation. */ ?>' );
			$build = new ReflectionMethod( Imajiner_Preview::class, 'build' );
			$build->setAccessible( true );
			$preview = $build->invoke( null, $this->path );
			self::assertIsString( $preview );
			self::assertNotSame( $legacy, $preview );
			self::assertStringContainsString( '<!--imj-text:t0-->Hello <!--/imj-text:t0-->', file_get_contents( $preview ) );
			self::assertSame( $preview, $build->invoke( null, $this->path ) );
		} finally {
			foreach ( array( $legacy, $preview ) as $file ) { if ( is_string( $file ) && file_exists( $file ) ) { unlink( $file ); } }
			unset( $_GET[ Imajiner_Preview::TEMPLATE_VAR ], $_GET[ Imajiner_Preview::QUERY_VAR ] );
		}
	}

	public function test_removing_duplicate_css_declarations_keeps_unrelated_source(): void {
		$selector = '.imj-' . $this->key . ' .hero:hover';
		$source = $selector . ' { color: red; margin: 0; color: blue; }' . "\n" . '.outside { color: green; }';
		$css = new Imajiner_Css_Editor( $source );
		self::assertTrue( $css->set( $selector, 'color', null ) );
		self::assertSame( $selector . ' {  margin: 0;  }' . "\n" . '.outside { color: green; }', $css->get_css() );
		self::assertArrayNotHasKey( 'color', $css->get_style_rules( '.imj-' . $this->key )[0]['values'] );
	}

	public function test_duplicate_css_removal_replays_and_saves_in_compound_media_context(): void {
		$scope = '.imj-' . $this->key;
		$selector = $scope . ' .hero:hover, ' . $scope . ' a:focus-visible';
		$before = $this->files;
		$this->files['css'] .= "\n@media screen and (min-width: 800px) {\n" . $selector . ' { color: red; margin: 0; color: blue; }' . "\n}\n" . $scope . ' .other { color: green; }';
		self::assertTrue( Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $before ), $this->files, 'Test duplicate CSS declarations' ) );
		$changes = array( array( 'type' => 'style', 'class' => 'hero', 'selector' => $selector, 'media' => 'screen and (min-width: 800px)', 'property' => 'color', 'value' => null ) );
		$expected = $this->files;
		$expected['css'] = str_replace( 'color: red; margin: 0; color: blue;', ' margin: 0; ', $this->files['css'] );
		self::assertSame( $expected, $this->staged( $this->request( 'stage', array( 'changes' => $changes ) ) ) );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		self::assertSame( $this->files, $this->staged( $this->request( 'stage' ) ) );
		self::assertSame( $expected, $this->staged( $this->request( 'stage', array( 'changes' => $changes ) ) ) );
		self::assertSame( 200, $this->request( 'save', array( 'changes' => $changes ) )->get_status() );
		self::assertSame( $expected, Imajiner_Template_Store::read( $this->path ) );
	}

	/** @dataProvider unsafe_markup */
	public function test_proposals_reject_unsafe_literal_markup( $markup ): void {
		$scanner = new Imajiner_Template_Scanner( $this->files['php'] );
		self::assertInstanceOf( WP_Error::class, $scanner->replace_node( 'e4', $markup ) );
	}

	public function unsafe_markup(): array {
		return array( array( '<section><?php echo "Injected"; ?></section>' ), array( '<section onclick="alert(1)">Unsafe</section>' ), array( '<section data-imj-id="e1">Forged</section>' ), array( '<!--imj-php:0-->' ), array( '<script>alert(1)</script>' ), array( '<section><h2>Unclosed</section>' ) );
	}

	public function test_source_changes_are_allowlisted_and_escaped(): void {
		$scanner = new Imajiner_Template_Scanner( $this->files['php'] );
		$changed = $scanner->change_source( 'p1', 'custom-field', 'headline' );
		self::assertSame( str_replace( '<?php the_title(); ?>', "<?php echo esc_html( get_post_meta( get_the_ID(), 'headline', true ) ); ?>", $this->files['php'] ), $changed );
		self::assertSame( str_replace( '<?php the_title(); ?>', '<?php echo esc_html( get_the_title() ); ?>', $this->files['php'] ), ( new Imajiner_Template_Scanner( $changed ) )->change_source( 'p1', 'title' ) );
		self::assertInstanceOf( WP_Error::class, $scanner->change_source( 'p0', 'title' ) );
		self::assertInstanceOf( WP_Error::class, $scanner->change_source( 'p1', 'custom-field', "x'); system('bad" ) );
		self::assertInstanceOf( WP_Error::class, $scanner->change_source( 'p1', 'arbitrary-php' ) );
		$response = $this->request( 'stage', array( 'changes' => array( array( 'type' => 'source', 'id' => 'p1', 'source' => 'custom-field', 'field' => 'headline' ) ) ) );
		self::assertSame( $changed, $this->staged( $response )['php'] );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_class_removal_has_no_trailing_whitespace(): void {
		$scanner = new Imajiner_Template_Scanner( '<p class="hero">Text</p>', array( 'require_sections' => false ) );
		self::assertSame( '<p>Text</p>', $scanner->apply_changes( array( array( 'type' => 'attr', 'id' => 'e0', 'name' => 'class', 'value' => null ) ) ) );
	}

	public function test_compound_min_width_and_pseudo_state_edits_preserve_other_rules(): void {
		$scope = '.imj-' . $this->key;
		$selector = "$scope .hero:hover, $scope .hero:focus-visible";
		$untouched = "$scope .other { margin: 2px; }";
		$css = new Imajiner_Css_Editor( "@media (min-width: 800px) { $selector { color: red; padding: 1px; } }\n$untouched" );
		self::assertTrue( $css->has_rule( $selector, '(min-width:800px)' ) );
		self::assertCount( 2, $css->get_style_rules( $scope ) );
		self::assertTrue( $css->set( $selector, 'color', 'blue', '(min-width:800px)' ) );
		self::assertStringContainsString( "$selector { color: blue; padding: 1px; }", $css->get_css() );
		self::assertStringContainsString( $untouched, $css->get_css() );
	}

	public function test_library_stages_markup_and_scoped_css_without_writing(): void {
		foreach ( array( 'hero', 'features', 'cta' ) as $name ) {
			$response = $this->request( 'stage', array( 'changes' => array( array( 'type' => 'library', 'name' => $name ) ) ) );
			$files = $this->staged( $response );
			self::assertStringContainsString( 'class="imj-library-' . $name . '"', $files['php'] );
			self::assertStringStartsWith( $this->files['css'], $files['css'] );
			self::assertTrue( Imajiner_Css_Editor::validate_scope( $files['css'], '.imj-' . $this->key ) );
			self::assertSame( ( new Imajiner_Template_Scanner( $this->files['php'] ) )->get_php_sources(), ( new Imajiner_Template_Scanner( $files['php'] ) )->get_php_sources() );
		}
		self::assertSame( 400, $this->request( 'stage', array( 'changes' => array( array( 'type' => 'library', 'name' => 'unknown' ) ) ) )->get_status() );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_proposal_is_staged_then_saved_through_revision_store(): void {
		$changes = array( array( 'type' => 'proposal', 'id' => 'e4', 'php' => '<section><p>Accepted proposal</p></section>', 'css' => '.imj-' . $this->key . ' p { color: blue; }' ) );
		$files = $this->staged( $this->request( 'stage', array( 'changes' => $changes ) ) );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		self::assertSame( 200, $this->request( 'save', array( 'changes' => $changes ) )->get_status() );
		self::assertSame( $files, Imajiner_Template_Store::read( $this->path ) );
		$revisions = Imajiner_Template_Store::get_revisions( $this->path );
		self::assertCount( 1, $revisions );
		$diff = $this->request( 'revisions/' . $revisions[0]['id'] . '/diff', array(), 'GET' );
		self::assertSame( 200, $diff->get_status() );
		self::assertStringContainsString( 'Accepted proposal', $diff->get_data()['php'] );
		self::assertSame( 409, $this->request( 'save', array( 'changes' => $changes ) )->get_status() );
	}

	public function test_unscoped_css_and_forged_rule_contexts_are_rejected(): void {
		$changes = array( array( 'type' => 'proposal', 'id' => 'e4', 'php' => '<section>Safe</section>', 'css' => 'body { color: red; }' ) );
		self::assertSame( 400, $this->request( 'stage', array( 'changes' => $changes ) )->get_status() );
		self::assertSame( 400, $this->request( 'save', array( 'changes' => $changes ) )->get_status() );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		$scope = '.imj-' . $this->key;
		$css = new Imajiner_Css_Editor( "body { color: red; } @media screen and (min-width: 800px) { $scope .hero:hover { color: blue; } }" );
		$rules = $css->get_style_rules( $scope );
		self::assertCount( 1, $rules );
		self::assertSame( 'screen and (min-width: 800px)', $rules[0]['media'] );
		self::assertSame( 400, $this->request( 'stage', array( 'changes' => array( array( 'type' => 'style', 'class' => 'hero', 'property' => 'color', 'value' => 'blue', 'selector' => 'body', 'media' => '' ) ) ) )->get_status() );
		self::assertSame( 400, $this->request( 'stage', array( 'changes' => array( array( 'type' => 'style', 'class' => 'hero', 'property' => 'color', 'value' => 'blue', 'state' => ':not(*)' ) ) ) )->get_status() );
		self::assertSame( 400, $this->request( 'stage', array( 'changes' => array( array( 'type' => 'proposal', 'id' => array(), 'php' => '<p>Safe</p>' ) ) ) )->get_status() );
	}

	public function test_locks_distinguish_same_user_sessions_without_storing_session_tokens(): void {
		$cookie_name = LOGGED_IN_COOKIE;
		$previous = isset( $_COOKIE[ $cookie_name ] ) ? $_COOKIE[ $cookie_name ] : null;
		$manager = WP_Session_Tokens::get_instance( $this->user );
		$expiry = time() + 300;
		$first = $manager->create( $expiry );
		$second = $manager->create( $expiry );
		try {
			$_COOKIE[ $cookie_name ] = wp_generate_auth_cookie( $this->user, $expiry, 'logged_in', $first );
			self::assertIsArray( Imajiner_Editor_Locks::acquire( $this->key ) );
			$stored = wp_json_encode( get_option( 'imajiner_lock_' . md5( get_stylesheet() . ':' . $this->key ) ) );
			self::assertStringNotContainsString( $first, $stored );
			$_COOKIE[ $cookie_name ] = wp_generate_auth_cookie( $this->user, $expiry, 'logged_in', $second );
			self::assertInstanceOf( WP_Error::class, Imajiner_Editor_Locks::acquire( $this->key ) );
			Imajiner_Editor_Locks::release( $this->key );
			self::assertInstanceOf( WP_Error::class, Imajiner_Editor_Locks::acquire( $this->key ) );
			$_COOKIE[ $cookie_name ] = wp_generate_auth_cookie( $this->user, $expiry, 'logged_in', $first );
			Imajiner_Editor_Locks::release( $this->key );
			$_COOKIE[ $cookie_name ] = wp_generate_auth_cookie( $this->user, $expiry, 'logged_in', $second );
			self::assertIsArray( Imajiner_Editor_Locks::acquire( $this->key ) );
			Imajiner_Editor_Locks::release( $this->key );
		} finally {
			$manager->destroy_all();
			if ( null === $previous ) { unset( $_COOKIE[ $cookie_name ] ); } else { $_COOKIE[ $cookie_name ] = $previous; }
		}
	}

	public function test_zero_change_stage_replays_original_after_structural_undo(): void {
		$changes = array( array( 'type' => 'structure', 'operation' => array( 'type' => 'duplicate', 'id' => 'e4' ) ), array( 'type' => 'batch', 'changes' => array( array( 'type' => 'text', 'id' => 't6', 'value' => 'Duplicate edited' ) ) ) );
		$files = $this->staged( $this->request( 'stage', array( 'changes' => $changes ) ) );
		self::assertStringContainsString( '<p>Static</p></section>\n<section><p>Duplicate edited</p>', str_replace( "\n", '\n', $files['php'] ) );
		self::assertSame( $this->files, $this->staged( $this->request( 'stage' ) ) );
		self::assertSame( $files, $this->staged( $this->request( 'stage', array( 'changes' => $changes ) ) ) );
	}

	public function test_lock_ownership_release_expiry_and_write_enforcement(): void {
		self::assertSame( 200, $this->request( 'lock' )->get_status() );
		wp_set_current_user( $this->other );
		$changes = array( array( 'type' => 'text', 'id' => 't0', 'value' => 'Other' ) );
		self::assertSame( 423, $this->request( 'stage', array( 'changes' => $changes ) )->get_status() );
		self::assertSame( 423, $this->request( 'save', array( 'changes' => $changes ) )->get_status() );
		self::assertSame( 423, $this->request( 'revisions/1/restore' )->get_status() );
		self::assertTrue( Imajiner_Editor_Locks::release( $this->key ) );
		self::assertInstanceOf( WP_Error::class, Imajiner_Editor_Locks::acquire( $this->key ) );
		wp_set_current_user( $this->user );
		self::assertSame( 200, $this->request( 'lock', array( 'action' => 'release' ) )->get_status() );
		wp_set_current_user( $this->other );
		self::assertIsArray( Imajiner_Editor_Locks::acquire( $this->key ) );
		$option = 'imajiner_lock_' . md5( get_stylesheet() . ':' . $this->key );
		$lock = get_option( $option ); $lock['expires'] = time() - 1; update_option( $option, $lock );
		wp_set_current_user( $this->user );
		self::assertIsArray( Imajiner_Editor_Locks::acquire( $this->key ) );
		self::assertSame( 400, $this->request( 'lock', array( 'action' => 'invalid' ) )->get_status() );
	}

	public function test_breakpoints_persist_and_filter_remains_authoritative(): void {
		$points = array( 'desktop' => array( 'label' => 'Desktop', 'media' => '', 'width' => 1280 ), 'wide' => array( 'label' => 'Wide', 'media' => '(min-width: 900px)', 'width' => 1000 ) );
		$request = new WP_REST_Request( 'POST', '/imajiner/v1/editor/breakpoints' );
		$request->set_body_params( array( 'breakpoints' => $points ) );
		self::assertSame( 200, rest_do_request( $request )->get_status() );
		self::assertSame( $points, Imajiner_Editor::breakpoints() );
		$filter = function ( $values ) { $values['wide']['width'] = 1100; return $values; };
		add_filter( 'imajiner_editor_breakpoints', $filter );
		self::assertSame( 1100, Imajiner_Editor::breakpoints()['wide']['width'] );
		remove_filter( 'imajiner_editor_breakpoints', $filter );
		self::assertInstanceOf( WP_Error::class, Imajiner_Editor::validate_breakpoints( array( 'bad' => array( 'label' => 'Bad', 'media' => '<script>', 'width' => 1 ) ) ) );
	}
}
