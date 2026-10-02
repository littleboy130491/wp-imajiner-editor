<?php

use PHPUnit\Framework\TestCase;

if ( '1' !== getenv( 'IMAJINER_TEST_SITE' ) ) {
	throw new RuntimeException( 'Run only on a disposable WordPress site with IMAJINER_TEST_SITE=1.' );
}
$_SERVER['SERVER_NAME'] = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'imajiner-management.test';
$_SERVER['SERVER_PORT'] = '80';
if ( ! defined( 'ABSPATH' ) ) {
	$_SERVER['HTTP_HOST']      = 'imajiner-management.test';
	$_SERVER['SERVER_NAME']    = 'imajiner-management.test';
	$_SERVER['SERVER_PORT']    = '80';
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['REQUEST_URI']    = '/';
	require ( getenv( 'WP_TEST_ROOT' ) ?: dirname( __DIR__, 4 ) ) . '/wp-load.php';
}
require_once ABSPATH . 'wp-admin/includes/user.php';

final class TemplateManagementTest extends TestCase {
	private $user;
	private $prefix;
	private $paths = array();
	private $posts = array();
	private $terms = array();
	private $options = array();
	private $query;
	private $main_query;
	private $styles;
	private $front;
	private $blog;
	private $book;
	private $term;

	protected function setUp(): void {
		self::assertTrue( is_child_theme() && Imajiner_Editor::theme_ready() );
		self::assertTrue( class_exists( 'Imajiner_Filesystem' ) && method_exists( 'Imajiner_Template_Store', 'snapshot' ), 'Load the shared filesystem and revision snapshot integration before running this suite.' );
		self::assertTrue( Imajiner_Filesystem::init() );
		$this->prefix = 'imj-management-' . substr( wp_generate_uuid4(), 0, 8 );
		$this->user   = wp_insert_user( array( 'user_login' => $this->prefix, 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
		wp_set_current_user( $this->user );
		$this->query      = $GLOBALS['wp_query'];
		$this->main_query = $GLOBALS['wp_the_query'];
		$this->styles     = wp_styles();
		$GLOBALS['wp_styles'] = new WP_Styles();
		foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts' ) as $option ) {
			$this->options[ $option ] = get_option( $option );
		}
		register_post_type( 'imj_test_book', array( 'public' => true, 'has_archive' => true, 'label' => 'Test books', 'supports' => array( 'title', 'page-attributes' ) ) );
		register_taxonomy( 'imj_test_genre', 'imj_test_book', array( 'public' => true, 'label' => 'Test genres' ) );
		$this->front = $this->post( 'page' );
		$this->blog  = $this->post( 'page' );
		$this->book  = $this->post( 'imj_test_book' );
		$term = wp_insert_term( $this->prefix, 'imj_test_genre', array( 'slug' => $this->prefix ) );
		self::assertIsArray( $term );
		$this->term    = get_term( $term['term_id'], 'imj_test_genre' );
		$this->terms[] = $this->term->term_id;
		wp_set_object_terms( $this->book, array( $this->term->term_id ), 'imj_test_genre' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->front );
		update_option( 'page_for_posts', $this->blog );
		add_filter( 'pre_http_request', array( $this, 'deny_http' ), 999, 3 );
	}

	public function deny_http() {
		return new WP_Error( 'imajiner_test_network', 'Network calls are not permitted by these tests.' );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'deny_http' ), 999 );
		foreach ( $this->paths as $path ) {
			foreach ( Imajiner_Template_Store::get_revisions( $path ) as $revision ) {
				wp_delete_post( $revision['id'], true );
			}
			foreach ( array( $path, Imajiner_Template_Store::css_path( $path ) ) as $file ) {
				if ( is_link( $file ) || is_file( $file ) ) {
					unlink( $file );
				}
			}
		}
		foreach ( $this->posts as $post ) {
			wp_delete_post( $post, true );
		}
		foreach ( $this->terms as $term ) {
			wp_delete_term( $term, 'imj_test_genre' );
		}
		unregister_taxonomy( 'imj_test_genre' );
		unregister_post_type( 'imj_test_book' );
		foreach ( $this->options as $option => $value ) {
			update_option( $option, $value );
		}
		wp_delete_user( $this->user );
		wp_set_current_user( 0 );
		$GLOBALS['wp_query']     = $this->query;
		$GLOBALS['wp_the_query'] = $this->main_query;
		$GLOBALS['wp_styles']    = $this->styles;
		wp_clean_themes_cache( false );
		$_POST = array();
		unset( $_GET[ Imajiner_Preview::QUERY_VAR ], $_GET[ Imajiner_Preview::TEMPLATE_VAR ] );
	}

	private function post( $type ) {
		$id = wp_insert_post( array( 'post_title' => $this->prefix, 'post_type' => $type, 'post_status' => 'publish' ) );
		$this->posts[] = $id;
		return $id;
	}

	private function query( array $args ) {
		$GLOBALS['wp_query']     = new WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['wp_query']->query( $args );
		$GLOBALS['post']         = $GLOBALS['wp_query']->post;
	}

	private function fixture( $suffix, $locations = '', $part = false ) {
		$slug = $this->prefix . '-' . $suffix;
		$key  = ( $part ? 'parts/' : '' ) . $slug;
		$path = get_stylesheet_directory() . '/imajiner/' . $key . '.php';
		$php  = $part ? "<?php\n/**\n * Part Name: $slug\n * Part Location: $locations\n */\n?>\n<section class=\"banner\">Part fixture</section>" : "<?php\n/**\n * Template Name: $slug\n * Template Post Type: page, post, imj_test_book\n * Imajiner Location: $locations\n */\nget_header();\n?>\n<!-- imj:section name=\"content\" -->\n<section class=\"hero\">Test fixture</section>\n<!-- /imj:section -->\n<?php get_footer();";
		$css  = ( $part ? '.imj-part-' : '.imj-' ) . $slug . ' .hero { color: var(--imj-color-primary); }';
		self::assertTrue( Imajiner_Template_Store::create( $path, array( 'php' => $php, 'css' => $css ) ) );
		$this->paths[] = $path;
		return array( 'key' => $key, 'slug' => $slug, 'path' => $path, 'php' => $php, 'css' => $css );
	}

	private function hash( $fixture ) {
		return Imajiner_Template_Store::hash( Imajiner_Template_Store::read( $fixture['path'] ) );
	}

	public function testRenameKeepsSourceLocationsStylesAssignmentsAndRevision(): void {
		$fixture = $this->fixture( 'single', 'single:imj_test_book' );
		update_post_meta( $this->book, '_wp_page_template', 'imajiner/' . $fixture['key'] . '.php' );
		$slug = $this->prefix . '-renamed';
		$path = get_stylesheet_directory() . '/imajiner/' . $slug . '.php';
		$this->paths[] = $path;
		self::assertTrue( Imajiner_Template_Manager::manage( $fixture['key'], $this->hash( $fixture ), 'rename', $slug, true ) );
		self::assertFileDoesNotExist( $fixture['path'] );
		self::assertFileDoesNotExist( Imajiner_Template_Store::css_path( $fixture['path'] ) );
		self::assertSame( $fixture['php'], file_get_contents( $path ) );
		$scanner = new Imajiner_Template_Scanner( file_get_contents( $path ) );
		self::assertTrue( $scanner->is_lossless() );
		self::assertSame( str_replace( $fixture['slug'], $slug, $fixture['css'] ), file_get_contents( Imajiner_Template_Store::css_path( $path ) ) );
		self::assertSame( 'imajiner/' . $slug . '.php', get_page_template_slug( $this->book ) );
		self::assertSame( $slug, imajiner_location_assignments()['single:imj_test_book'] );
		$revisions = Imajiner_Template_Store::get_revisions( $path );
		self::assertCount( 1, $revisions );
		self::assertSame( array( 'php' => $fixture['php'], 'css' => $fixture['css'] ), Imajiner_Template_Store::get_revision_files( $path, $revisions[0]['id'] ) );
	}

	public function testDeleteClearsAssignmentsAndKeepsRecoverablePair(): void {
		$fixture = $this->fixture( 'delete', 'archive:imj_test_book' );
		update_post_meta( $this->book, '_wp_page_template', 'imajiner/' . $fixture['key'] . '.php' );
		self::assertTrue( Imajiner_Template_Manager::manage( $fixture['key'], $this->hash( $fixture ), 'delete', '', true ) );
		self::assertFileDoesNotExist( $fixture['path'] );
		self::assertFileDoesNotExist( Imajiner_Template_Store::css_path( $fixture['path'] ) );
		self::assertSame( '', get_page_template_slug( $this->book ) );
		self::assertArrayNotHasKey( 'archive:imj_test_book', imajiner_location_assignments() );
		$revision = Imajiner_Template_Store::get_revisions( $fixture['path'] )[0];
		self::assertSame( array( 'php' => $fixture['php'], 'css' => $fixture['css'] ), Imajiner_Template_Store::get_revision_files( $fixture['path'], $revision['id'] ) );
	}

	public function testConfirmationStaleCssAndCollisionsNeverChangeFiles(): void {
		$fixture = $this->fixture( 'stale' );
		$hash    = $this->hash( $fixture );
		self::assertSame( 'imajiner_confirmation', Imajiner_Template_Manager::manage( $fixture['key'], $hash, 'delete' )->get_error_code() );
		self::assertTrue( Imajiner_Filesystem::write( Imajiner_Template_Store::css_path( $fixture['path'] ), $fixture['css'] . "\n" ) );
		self::assertSame( 'imajiner_conflict', Imajiner_Template_Manager::manage( $fixture['key'], $hash, 'delete', '', true )->get_error_code() );
		$other = $this->fixture( 'collision' );
		self::assertSame( 'imajiner_exists', Imajiner_Template_Manager::manage( $fixture['key'], $this->hash( $fixture ), 'rename', $other['slug'], true )->get_error_code() );
		$orphan = $this->prefix . '-orphan';
		$path   = get_stylesheet_directory() . '/imajiner/' . $orphan . '.php';
		$this->paths[] = $path;
		self::assertTrue( Imajiner_Filesystem::write( Imajiner_Template_Store::css_path( $path ), 'orphan stylesheet' ) );
		self::assertSame( 'imajiner_exists', Imajiner_Template_Manager::manage( $fixture['key'], $this->hash( $fixture ), 'rename', $orphan, true )->get_error_code() );
		self::assertSame( $fixture['php'], file_get_contents( $fixture['path'] ) );
		self::assertCount( 0, Imajiner_Template_Store::get_revisions( $fixture['path'] ) );
	}

	public function testTraversalSymlinksParentAndCapabilityAreRejected(): void {
		$fixture = $this->fixture( 'safe' );
		foreach ( array( '../functions', 'parts/../../header', '/etc/passwd', 'safe.php', 'parts/not_safe' ) as $key ) {
			self::assertSame( 'imajiner_path', Imajiner_Template_Manager::path( $key )->get_error_code() );
		}
		$linked = $this->prefix . '-linked';
		$path   = get_stylesheet_directory() . '/imajiner/' . $linked . '.php';
		$this->paths[] = $path;
		self::assertTrue( symlink( $fixture['path'], $path ) );
		self::assertSame( 'imajiner_path', Imajiner_Template_Manager::manage( $linked, $this->hash( $fixture ), 'delete', '', true )->get_error_code() );
		$css = Imajiner_Template_Store::css_path( $path );
		self::assertTrue( unlink( $path ) );
		self::assertTrue( Imajiner_Filesystem::write( $path, $fixture['php'] ) );
		self::assertTrue( symlink( Imajiner_Template_Store::css_path( $fixture['path'] ), $css ) );
		self::assertSame( 'imajiner_path', Imajiner_Template_Manager::path( $linked )->get_error_code() );
		$parent_slug = $this->prefix . '-parent';
		$parent_path = get_template_directory() . '/imajiner/' . $parent_slug . '.php';
		self::assertTrue( wp_mkdir_p( dirname( $parent_path ) ) );
		self::assertTrue( Imajiner_Template_Store::create( $parent_path, array( 'php' => $fixture['php'], 'css' => $fixture['css'] ) ) );
		$this->paths[] = $parent_path;
		self::assertSame( $parent_path, imajiner_get_templates()[ $parent_slug ]['file'] );
		self::assertSame( 'imajiner_missing', Imajiner_Template_Manager::manage( $parent_slug, $this->hash( $fixture ), 'delete', '', true )->get_error_code() );
		self::assertFileExists( $parent_path );
		$directory_slug = $this->prefix . '-directory';
		$directory_path = get_stylesheet_directory() . '/imajiner/' . $directory_slug . '.php';
		self::assertTrue( mkdir( $directory_path ) );
		try {
			self::assertSame( 'imajiner_path', Imajiner_Template_Manager::path( $directory_slug )->get_error_code() );
		} finally {
			rmdir( $directory_path );
		}
		wp_set_current_user( 0 );
		self::assertSame( 'imajiner_forbidden', Imajiner_Template_Manager::manage( $fixture['key'], $this->hash( $fixture ), 'delete', '', true )->get_error_code() );
		wp_set_current_user( $this->user );
		self::assertFileExists( $fixture['path'] );
	}

	public function testInvalidNonceBlocksAdminHandler(): void {
		$fixture = $this->fixture( 'nonce' );
		$_POST   = array( 'template' => $fixture['key'], 'hash' => $this->hash( $fixture ), 'operation' => 'delete', 'confirm' => '1', '_wpnonce' => 'invalid' );
		$_REQUEST = $_POST;
		$die = function () { return function () { throw new RuntimeException( 'nonce rejected' ); }; };
		add_filter( 'wp_die_handler', $die );
		try {
			Imajiner_Template_Manager::handle();
			self::fail( 'The invalid nonce was accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'nonce rejected', $error->getMessage() );
		} finally {
			remove_filter( 'wp_die_handler', $die );
			$_REQUEST = array();
		}
		self::assertFileExists( $fixture['path'] );
	}

	public function testRegisteredCptTaxonomyAndTermResolveMostSpecificTemplates(): void {
		$single  = $this->fixture( 'single', 'single:imj_test_book' );
		$archive = $this->fixture( 'archive', 'archive:imj_test_book' );
		$tax     = $this->fixture( 'taxonomy', 'taxonomy:imj_test_genre' );
		$term    = $this->fixture( 'term', 'term:imj_test_genre:' . $this->term->slug );
		$all     = $this->fixture( 'general', 'single, archive' );
		$locations = imajiner_template_locations();
		foreach ( array( 'single:imj_test_book', 'archive:imj_test_book', 'taxonomy:imj_test_genre', 'term:imj_test_genre:' . $this->term->slug, 'front-page', 'home' ) as $key ) {
			self::assertArrayHasKey( $key, $locations );
		}
		$this->query( array( 'post_type' => 'imj_test_book', 'p' => $this->book ) );
		self::assertSame( $single['path'], imajiner_template_include( 'default.php' ) );
		update_post_meta( $this->book, '_wp_page_template', 'imajiner/' . $all['key'] . '.php' );
		self::assertSame( 'chosen.php', imajiner_template_include( 'chosen.php' ) );
		delete_post_meta( $this->book, '_wp_page_template' );
		$this->query( array( 'post_type' => 'imj_test_book' ) );
		self::assertTrue( is_post_type_archive( 'imj_test_book' ) );
		self::assertSame( $archive['path'], imajiner_template_include( 'default.php' ) );
		$this->query( array( 'imj_test_genre' => $this->term->slug ) );
		self::assertTrue( is_tax( 'imj_test_genre' ) );
		self::assertSame( array( 'term:imj_test_genre:' . $this->term->slug, 'taxonomy:imj_test_genre', 'archive' ), imajiner_request_locations() );
		self::assertSame( $term['path'], imajiner_template_include( 'default.php' ) );
		self::assertTrue( Imajiner_Template_Manager::manage( $term['key'], $this->hash( $term ), 'delete', '', true ) );
		self::assertSame( $tax['path'], imajiner_template_include( 'default.php' ) );
	}

	public function testFrontPagePostsPageAndLatestPostsHaveDistinctPrecedence(): void {
		$front = $this->fixture( 'front', 'front-page' );
		$home  = $this->fixture( 'home', 'home' );
		$this->fixture( 'page', 'single:page' );
		$this->fixture( 'post-archive', 'archive:post' );
		$this->query( array( 'page_id' => $this->front ) );
		self::assertTrue( is_front_page() );
		self::assertSame( $front['path'], imajiner_template_include( 'default.php' ) );
		$this->query( array( 'page_id' => $this->blog ) );
		self::assertTrue( is_home() );
		self::assertSame( array( 'home', 'archive:post', 'archive' ), imajiner_request_locations() );
		self::assertSame( $home['path'], imajiner_template_include( 'default.php' ) );
		update_option( 'show_on_front', 'posts' );
		$this->query( array() );
		self::assertTrue( is_front_page() && is_home() );
		self::assertSame( array( 'front-page', 'home', 'archive:post', 'archive' ), imajiner_request_locations() );
		self::assertSame( $front['path'], imajiner_template_include( 'default.php' ) );
	}

	public function testConditionsArePortableRevisionedAndApplyToHookAndExplicitRendering(): void {
		$fixture = $this->fixture( 'conditional', 'tha_footer_before', true );
		$hash    = $this->hash( $fixture );
		$settings = array( 'post_types' => array( 'page' ), 'include' => array( 'single:page' ), 'exclude' => array( 'front-page', 'home' ) );
		self::assertTrue( Imajiner_Builder::update_part_conditions( $fixture['key'], $hash, $settings ) );
		$part = imajiner_get_parts()[ $fixture['slug'] ];
		foreach ( $settings as $field => $values ) {
			self::assertSame( $values, $part[ $field ] );
		}
		self::assertCount( 1, Imajiner_Template_Store::get_revisions( $fixture['path'] ) );
		self::assertSame( 'imajiner_conflict', Imajiner_Builder::update_part_conditions( $fixture['key'], $hash, array() )->get_error_code() );
		self::assertSame( 'imajiner_conditions', Imajiner_Builder::update_part_conditions( $fixture['key'], $this->hash( $fixture ), array( 'include' => array( '../unsafe' ) ) )->get_error_code() );
		$this->query( array( 'page_id' => $this->front ) );
		ob_start();
		self::assertFalse( imajiner_part( $fixture['slug'] ) );
		do_action( 'tha_footer_before' );
		self::assertSame( '', ob_get_clean() );
		self::assertFalse( wp_style_is( 'imajiner-part-' . $fixture['slug'], 'enqueued' ) );
		$page = $this->post( 'page' );
		$this->query( array( 'page_id' => $page ) );
		ob_start();
		do_action( 'tha_footer_before' );
		$output = ob_get_clean();
		self::assertStringContainsString( 'imj-part-' . $fixture['slug'], $output );
		self::assertTrue( wp_style_is( 'imajiner-part-' . $fixture['slug'], 'enqueued' ) );
		$this->query( array( 'post_type' => 'imj_test_book', 'p' => $this->book ) );
		self::assertFalse( imajiner_part_visible( $part ) );
	}

	public function testHeaderFooterFallbackAndLateExplicitCssOutput(): void {
		$header = $this->fixture( 'header', 'header', true );
		$footer = $this->fixture( 'footer', 'footer', true );
		$late   = $this->fixture( 'late', '', true );
		$unused = $this->fixture( 'unused', '', true );
		foreach ( array( $header, $footer ) as $part ) {
			self::assertTrue( Imajiner_Builder::update_part_conditions( $part['key'], $this->hash( $part ), array( 'exclude' => array( 'front-page' ) ) ) );
		}
		$this->query( array( 'page_id' => $this->front ) );
		ob_start();
		get_header();
		get_footer();
		$output = ob_get_clean();
		self::assertStringContainsString( 'class="site-header"', $output );
		self::assertStringContainsString( 'class="site-footer"', $output );
		self::assertStringNotContainsString( 'imj-part-' . $header['slug'], $output );
		self::assertStringNotContainsString( 'imj-part-' . $footer['slug'], $output );
		self::assertStringNotContainsString( $unused['slug'] . '.css', $output );
		ob_start();
		self::assertTrue( imajiner_part( $late['slug'] ) );
		self::assertTrue( imajiner_part( $late['slug'] ) );
		$late_output = ob_get_clean();
		self::assertSame( 1, substr_count( $late_output, $late['slug'] . '.css' ) );
		self::assertStringContainsString( 'rel=\'stylesheet\'', $late_output );
		self::assertStringNotContainsString( $unused['slug'] . '.css', $late_output );
	}

	public function testRenamedPartKeepsExplicitCallsWorkingWithoutPhpRewrites(): void {
		$fixture = $this->fixture( 'part', '', true );
		$slug    = $this->prefix . '-part-renamed';
		$path    = get_stylesheet_directory() . '/imajiner/parts/' . $slug . '.php';
		$this->paths[] = $path;
		self::assertTrue( Imajiner_Template_Manager::manage( $fixture['key'], $this->hash( $fixture ), 'rename', $slug, true ) );
		self::assertSame( array( $fixture['slug'] ), imajiner_get_parts()[ $slug ]['aliases'] );
		ob_start();
		self::assertTrue( imajiner_part( $fixture['slug'] ) );
		$output = ob_get_clean();
		self::assertStringContainsString( 'imj-part-' . $slug, $output );
		self::assertStringNotContainsString( 'imj-part-' . $fixture['slug'] . '"', $output );
		self::assertStringContainsString( '.imj-part-' . $slug, file_get_contents( Imajiner_Template_Store::css_path( $path ) ) );
		self::assertCount( 1, Imajiner_Template_Store::get_revisions( $path ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testAuthenticatedPreviewShowsExcludedPartAndLoadsItsActualCss(): void {
		$fixture = $this->fixture( 'preview', '', true );
		self::assertTrue( Imajiner_Builder::update_part_conditions( $fixture['key'], $this->hash( $fixture ), array( 'exclude' => array( 'front-page' ) ) ) );
		$_GET[ Imajiner_Preview::QUERY_VAR ]    = wp_create_nonce( Imajiner_Preview::QUERY_VAR . '_' . $fixture['key'] );
		$_GET[ Imajiner_Preview::TEMPLATE_VAR ] = $fixture['key'];
		$this->query( array( 'page_id' => $this->front ) );
		self::assertSame( $fixture['key'], Imajiner_Preview::current()['key'] );
		ob_start();
		include IMAJINER_EDITOR_DIR . 'views/part-preview.php';
		$output = ob_get_clean();
		self::assertStringContainsString( 'imj-part-' . $fixture['slug'], $output );
		self::assertStringContainsString( $fixture['slug'] . '.css', $output );
		self::assertStringContainsString( 'data-imj-id=', $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testStagedPartPreviewDoesNotReloadTheOriginalStylesheet(): void {
		$fixture = $this->fixture( 'staged-preview', '', true );
		self::assertTrue( Imajiner_Builder::update_part_conditions( $fixture['key'], $this->hash( $fixture ), array( 'exclude' => array( 'front-page' ) ) ) );
		$files = Imajiner_Template_Store::read( $fixture['path'] );
		$staged = array( 'php' => str_replace( 'Part fixture', 'Staged fixture', $files['php'] ), 'css' => '.imj-part-' . $fixture['slug'] . ' .banner { color: var(--imj-color-secondary); }' );
		$id = wp_generate_uuid4();
		$transient = 'imajiner_stage_' . $this->user . '_' . $id;
		set_transient( $transient, array( 'key' => $fixture['key'], 'stylesheet' => get_stylesheet(), 'hash' => Imajiner_Template_Store::hash( $files ), 'files' => $staged ), MINUTE_IN_SECONDS );
		$_GET[ Imajiner_Preview::QUERY_VAR ] = wp_create_nonce( Imajiner_Preview::QUERY_VAR . '_' . $fixture['key'] );
		$_GET[ Imajiner_Preview::TEMPLATE_VAR ] = $fixture['key'];
		$_GET['imajiner_stage'] = $id;
		$this->query( array( 'page_id' => $this->front ) );
		try {
			ob_start();
			include IMAJINER_EDITOR_DIR . 'views/part-preview.php';
			$output = ob_get_clean();
			self::assertStringContainsString( 'Staged fixture', $output );
			self::assertStringContainsString( $staged['css'], $output );
			self::assertStringNotContainsString( $fixture['slug'] . '.css', $output );
			self::assertFalse( wp_style_is( 'imajiner-part-' . $fixture['slug'], 'enqueued' ) );
		} finally {
			delete_transient( $transient );
			unset( $_GET['imajiner_stage'] );
		}
	}
}
