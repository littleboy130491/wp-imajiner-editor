<?php

use PHPUnit\Framework\TestCase;

require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-section-library.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-editor-locks.php';

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
	private $token = 'editor-session-00000001';

	protected function setUp(): void {
		$suffix = wp_generate_uuid4();
		$this->user = wp_insert_user( array( 'user_login' => 'imj-features-' . $suffix, 'user_pass' => $suffix, 'role' => 'administrator' ) );
		$this->other = wp_insert_user( array( 'user_login' => 'imj-features-other-' . $suffix, 'user_pass' => $suffix, 'role' => 'administrator' ) );
		wp_set_current_user( $this->user );
		$this->key = 'imj-features-' . substr( $suffix, 0, 8 );
		$this->path = get_stylesheet_directory() . '/imajiner/' . $this->key . '.php';
		$this->files = array(
			'php' => "<?php\n/** Template Name: {$this->key} */\nget_header(); ?>\n<!-- imj:section name=\"hero\" -->\n<section class=\"hero\"><h2>Heading</h2><p>Start <strong>bold</strong> and <a href=\"/contact/\">link</a> end <?php the_title(); ?></p><div class=\"box\"><p>Other</p></div></section>\n<!-- /imj:section -->\n<?php get_footer(); ?>",
			'css' => '.imj-' . $this->key . ' .hero { color: red; }',
		);
		self::assertTrue( Imajiner_Template_Store::create( $this->path, $this->files ) );
		wp_clean_themes_cache( false );
		$this->breakpoints = get_option( 'imajiner_editor_breakpoints', null );
	}

	protected function tearDown(): void {
		wp_set_current_user( $this->user );
		foreach ( $this->stages as $stage ) {
			delete_transient( 'imajiner_stage_' . $this->user . '_' . $stage );
		}
		delete_option( 'imajiner_lock_' . md5( get_stylesheet() . '|' . $this->key ) );
		foreach ( Imajiner_Template_Store::get_revisions( $this->path ) as $revision ) {
			wp_delete_post( $revision['id'], true );
		}
		unlink( $this->path );
		unlink( Imajiner_Template_Store::css_path( $this->path ) );
		if ( null === $this->breakpoints ) {
			delete_option( 'imajiner_editor_breakpoints' );
		} else {
			update_option( 'imajiner_editor_breakpoints', $this->breakpoints );
		}
		wp_clean_themes_cache( false );
		wp_delete_user( $this->user );
		wp_delete_user( $this->other );
	}

	private function request( $method, $suffix, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/imajiner/v1/templates/' . $this->key . $suffix );
		$request->set_body_params( array_merge( array( 'hash' => Imajiner_Template_Store::hash( $this->files ), 'lock' => $this->token ), $body ) );
		$response = rest_do_request( $request );
		if ( 200 === $response->get_status() && isset( $response->get_data()['stage'] ) ) {
			$this->stages[] = $response->get_data()['stage'];
		}
		return $response;
	}

	private function stage_files( WP_REST_Response $response ): array {
		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return Imajiner_Preview::staged_files( Imajiner_Editor::get_template( $this->key ), $response->get_data()['stage'] );
	}

	public function test_mixed_text_markers_and_edits_preserve_markup_and_php(): void {
		$scanner = new Imajiner_Template_Scanner( $this->files['php'] );
		$preview = $scanner->get_instrumented_source();
		self::assertStringContainsString( '<!--imj-text:t1-->Start <!--/imj-text--><strong', $preview );
		self::assertStringContainsString( '><!--imj-text:t2-->bold<!--/imj-text--></strong>', $preview );
		self::assertStringContainsString( '<?php the_title(); ?>', $preview );
		$updated = $scanner->apply_changes( array( array( 'type' => 'text', 'id' => 't1', 'value' => 'New <start>' ) ) );
		self::assertSame( str_replace( 'Start ', 'New &lt;start&gt; ', $this->files['php'] ), $updated );
		self::assertSame( $scanner->get_php_sources(), ( new Imajiner_Template_Scanner( $updated ) )->get_php_sources() );
	}

	public function test_selected_replacement_is_exact_and_rejects_php_or_unsafe_markup(): void {
		$scanner = new Imajiner_Template_Scanner( $this->files['php'] );
		self::assertSame( '<h2>Heading</h2>', $scanner->get_node_source( 'e1' ) );
		self::assertSame( str_replace( '<h2>Heading</h2>', '<h1 class="new">Changed</h1>', $this->files['php'] ), $scanner->replace_node( 'e1', '<h1 class="new">Changed</h1>' ) );
		foreach ( array( '<?php echo "x"; ?>', '<script>alert(1)</script>', '<p onclick="alert(1)">Bad</p>', '<p><!--imj-php:0--></p>', '<p data-imj-id="e0">Bad</p>', '<p>Unclosed', '<p>One</p><p>Two</p>' ) as $markup ) {
			self::assertInstanceOf( WP_Error::class, $scanner->replace_node( 'e1', $markup ) );
		}
		self::assertInstanceOf( WP_Error::class, $scanner->replace_node( 's0', '<section>Changed</section>' ) );
	}

	public function test_static_section_replacement_preserves_unusual_section_markers(): void {
		$source = '<?php get_header(); ?>' . "\n<!--imj:section   name=\"test\"--><section><p>Old</p></section><!-- /imj:section -->\n" . '<?php get_footer(); ?>';
		$scanner = new Imajiner_Template_Scanner( $source );
		$updated = $scanner->replace_node( 's0', '<section><p>New</p></section>' );
		self::assertIsString( $updated );
		self::assertStringStartsWith( '<?php get_header(); ?>' . "\n<!--imj:section   name=\"test\"-->", $updated );
		self::assertStringEndsWith( "\n<?php get_footer(); ?>", $updated );
		self::assertSame( $scanner->get_php_sources(), ( new Imajiner_Template_Scanner( $updated ) )->get_php_sources() );
	}

	public function test_allowlisted_sources_escape_values_and_reject_arbitrary_php(): void {
		$scanner = new Imajiner_Template_Scanner( $this->files['php'] );
		$updated = $scanner->change_source( array( 'id' => 'p1', 'source' => 'custom-field', 'field' => 'price' ) );
		$expected = "<?php echo esc_html( get_post_meta( get_the_ID(), 'price', true ) ); ?>";
		self::assertSame( str_replace( '<?php the_title(); ?>', $expected, $this->files['php'] ), $updated );
		self::assertIsString( ( new Imajiner_Template_Scanner( $updated ) )->change_source( array( 'id' => 'p1', 'source' => 'title' ) ) );
		self::assertStringContainsString( '<?php echo esc_html( get_the_excerpt() ); ?>', $scanner->change_source( array( 'id' => 'p1', 'source' => 'excerpt' ) ) );
		foreach ( array( "<?php echo get_field( 'price' ); ?>", "<?php echo esc_html( get_field( 'price' ) ); ?>" ) as $field_source ) {
			$field = new Imajiner_Template_Scanner( '<p>' . $field_source . '</p>', array( 'require_sections' => false ) );
			self::assertSame( '<p><?php echo esc_html( get_the_title() ); ?></p>', $field->change_source( array( 'id' => 'p0', 'source' => 'title' ) ) );
		}
		foreach ( array( array( 'id' => 'p0', 'source' => 'title' ), array( 'id' => 'p1', 'source' => 'raw', 'php' => '<?php exit; ?>' ), array( 'id' => 'p1', 'source' => 'custom-field', 'field' => "x'); exit; //" ) ) as $change ) {
			self::assertInstanceOf( WP_Error::class, $scanner->change_source( $change ) );
		}
		$opaque = new Imajiner_Template_Scanner( '<p><?php echo custom_helper(); ?></p>', array( 'require_sections' => false ) );
		self::assertInstanceOf( WP_Error::class, $opaque->change_source( array( 'id' => 'p0', 'source' => 'title' ) ) );
	}

	/** @dataProvider library_sections */
	public function test_library_stages_and_saves_both_files( $section ): void {
		$changes = array( array( 'type' => 'structure', 'operation' => array( 'type' => 'insert', 'target' => 'root', 'position' => 'inside', 'starter' => 'library-' . $section ) ) );
		$staged = $this->request( 'POST', '/stage', array( 'changes' => $changes ) );
		$files = $this->stage_files( $staged );
		self::assertStringContainsString( 'class="imj-library-' . $section . '"', $files['php'] );
		self::assertStringContainsString( '.imj-' . $this->key . ' .imj-library-' . $section, $files['css'] );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		self::assertSame( ( new Imajiner_Template_Scanner( $this->files['php'] ) )->get_php_sources(), ( new Imajiner_Template_Scanner( $files['php'] ) )->get_php_sources() );
		self::assertSame( 200, $this->request( 'POST', '/save', array( 'changes' => $changes ) )->get_status() );
		self::assertSame( $files, Imajiner_Template_Store::read( $this->path ) );
		self::assertSame( $this->files, Imajiner_Template_Store::get_revision_files( $this->path, Imajiner_Template_Store::get_revisions( $this->path )[0]['id'] ) );
	}

	public function library_sections(): array {
		return array( array( 'hero' ), array( 'features' ), array( 'cta' ) );
	}

	public function test_selected_proposal_stages_without_a_raw_write_and_rejects_php(): void {
		$changes = array( array( 'type' => 'proposal', 'id' => 'e1', 'php' => '<h1 class="new">Proposed</h1>', 'css' => '.imj-' . $this->key . ' .new { color: blue; }' ) );
		$files = $this->stage_files( $this->request( 'POST', '/stage', array( 'changes' => $changes ) ) );
		self::assertSame( str_replace( '<h2>Heading</h2>', '<h1 class="new">Proposed</h1>', $this->files['php'] ), $files['php'] );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		$changes[0]['php'] = '<?php echo "forbidden"; ?>';
		self::assertSame( 400, $this->request( 'POST', '/save', array( 'changes' => $changes ) )->get_status() );
		$changes[0]['php'] = '<h1>Safe</h1>';
		$changes[0]['css'] = 'body { color: red; }';
		self::assertSame( 400, $this->request( 'POST', '/stage', array( 'changes' => $changes ) )->get_status() );
	}

	public function test_compound_rules_media_and_pseudo_states_leave_unrelated_css_exact(): void {
		$scope = '.imj-' . $this->key;
		$selector = $scope . ' .hero > h2:hover, ' . $scope . ' .hero a:focus-visible';
		$source = "/* keep */\n@media screen and (min-width: 800px) and (orientation: landscape) {\n  {$selector} { color : red; padding: 1px; }\n}\n.unrelated { color: green; }";
		$css = new Imajiner_Css_Editor( $source );
		self::assertCount( 1, $css->get_editable_rules( $scope ) );
		self::assertTrue( $css->set( $selector, 'color', 'blue', 'screen and ( min-width : 800px ) and ( orientation: landscape )' ) );
		self::assertSame( str_replace( 'red', 'blue', $source ), $css->get_css() );
		$pseudo = new Imajiner_Css_Editor( $scope . ' .hero:hover { color: blue; }' . $scope . ' .hero:focus-visible { outline: 2px solid; }' );
		$styles = $pseudo->get_class_styles( $scope, array( 'desktop' => '' ) );
		self::assertSame( 'blue', $styles['desktop']['hero:hover']['color'] );
		self::assertSame( '2px solid', $styles['desktop']['hero:focus-visible']['outline'] );
		$repeated = new Imajiner_Css_Editor( $scope . ' .hero { color: red; padding: 1px; }' . $scope . ' .hero { color: blue; }' );
		$rules = $repeated->get_editable_rules( $scope );
		self::assertCount( 1, $rules );
		self::assertSame( array( 'color' => 'blue', 'padding' => '1px' ), (array) $rules[0]['declarations'] );
	}

	public function test_rest_edits_existing_selector_without_splitting_it(): void {
		$selector = '.imj-' . $this->key . ' .hero > h2:hover, .imj-' . $this->key . ' .hero a';
		$css = '@media (min-width: 800px) {' . $selector . ' { color: red; }}';
		self::assertTrue( Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->files ), array( 'php' => $this->files['php'], 'css' => $css ), 'Test setup' ) );
		$this->files['css'] = $css;
		$change = array( 'type' => 'style', 'class' => 'hero', 'property' => 'color', 'value' => 'blue', 'selector' => $selector, 'media' => '(min-width:800px)', 'device' => 'desktop' );
		$files = $this->stage_files( $this->request( 'POST', '/stage', array( 'changes' => array( $change ) ) ) );
		self::assertSame( str_replace( 'red', 'blue', $css ), $files['css'] );
		$change['selector'] = 'body';
		self::assertSame( 400, $this->request( 'POST', '/stage', array( 'changes' => array( $change ) ) )->get_status() );
	}

	public function test_locks_enforce_sessions_users_release_and_expiry(): void {
		self::assertSame( 200, $this->request( 'POST', '/lock' )->get_status() );
		$changes = array( array( 'type' => 'text', 'id' => 't0', 'value' => 'New' ) );
		foreach ( array( '/stage', '/save', '/revisions/1/restore' ) as $route ) {
			self::assertSame( 423, $this->request( 'POST', $route, array( 'changes' => $changes, 'lock' => 'editor-session-00000002' ) )->get_status() );
		}
		wp_set_current_user( $this->other );
		self::assertSame( 423, $this->request( 'POST', '/stage', array( 'changes' => $changes ) )->get_status() );
		self::assertSame( 423, $this->request( 'DELETE', '/lock' )->get_status() );
		wp_set_current_user( $this->user );
		self::assertSame( 200, $this->request( 'DELETE', '/lock' )->get_status() );
		self::assertSame( 200, $this->request( 'POST', '/lock', array( 'lock' => 'editor-session-00000002' ) )->get_status() );
		$key = 'imajiner_lock_' . md5( get_stylesheet() . '|' . $this->key );
		$lock = get_option( $key );
		$lock['expires'] = time() - 1;
		update_option( $key, $lock );
		self::assertSame( 200, $this->request( 'POST', '/stage', array( 'changes' => $changes ) )->get_status() );
		self::assertSame( 400, $this->request( 'POST', '/lock', array( 'lock' => 'bad' ) )->get_status() );
	}

	public function test_breakpoint_persistence_filter_and_invalid_conditions(): void {
		$next = array( 'desktop' => array( 'label' => 'Base', 'media' => '', 'width' => 1280 ), 'wide' => array( 'label' => 'Wide', 'media' => '(min-width: 1400px)', 'width' => 1440 ), 'print' => array( 'label' => 'Print', 'media' => 'print', 'width' => 800 ) );
		$request = new WP_REST_Request( 'POST', '/imajiner/v1/editor/breakpoints' );
		$request->set_body_params( array( 'breakpoints' => $next ) );
		self::assertSame( 200, rest_do_request( $request )->get_status() );
		self::assertSame( $next, Imajiner_Editor::breakpoints() );
		$filter = function ( $values ) { $values['wide']['width'] = 1600; return $values; };
		add_filter( 'imajiner_editor_breakpoints', $filter );
		self::assertSame( 1600, Imajiner_Editor::breakpoints()['wide']['width'] );
		remove_filter( 'imajiner_editor_breakpoints', $filter );
		$next['wide']['media'] = ') { body { color: red; }';
		self::assertInstanceOf( WP_Error::class, Imajiner_Editor::save_breakpoints( $next ) );
		self::assertSame( '(min-width: 1400px)', Imajiner_Editor::breakpoints()['wide']['media'] );
	}

	public function test_diff_is_read_only_and_restore_remains_hash_checked(): void {
		$saved = $this->request( 'POST', '/save', array( 'changes' => array( array( 'type' => 'text', 'id' => 't0', 'value' => 'Edited' ) ) ) );
		self::assertSame( 200, $saved->get_status() );
		$revision = Imajiner_Template_Store::get_revisions( $this->path )[0]['id'];
		$diff = $this->request( 'GET', '/revisions/' . $revision . '/diff' );
		self::assertSame( 200, $diff->get_status() );
		self::assertSame( $this->files, $diff->get_data()['after'] );
		self::assertStringContainsString( '<h2>Edited</h2>', $diff->get_data()['before']['php'] );
		self::assertSame( $saved->get_data()['hash'], $diff->get_data()['hash'] );
		self::assertSame( 409, $this->request( 'POST', '/revisions/' . $revision . '/restore' )->get_status() );
		self::assertSame( 200, $this->request( 'POST', '/revisions/' . $revision . '/restore', array( 'hash' => $saved->get_data()['hash'] ) )->get_status() );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_class_removal_leaves_no_whitespace_in_the_tag(): void {
		foreach ( array( '<p class="x">Text</p>', '<p class="x"   >Text</p>', '<p id="keep" class="x">Text</p>' ) as $source ) {
			$updated = ( new Imajiner_Template_Scanner( $source, array( 'require_sections' => false ) ) )->apply_changes( array( array( 'type' => 'attr', 'id' => 'e0', 'name' => 'class', 'value' => null ) ) );
			self::assertIsString( $updated );
			self::assertDoesNotMatchRegularExpression( '/\s+>/', $updated );
			self::assertStringNotContainsString( 'class=', $updated );
		}
	}
}
