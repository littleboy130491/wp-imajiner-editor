<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-imajiner-template-manager.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

/** Deterministic transport failures, without FTP credentials or remote writes. */
class WP_Filesystem_imjtemplatefault extends WP_Filesystem_Direct {
	public static $failed_moves = array();
	public static $failed_deletes = array();
	public static $failed_writes = array();

	public function move( $source, $destination, $overwrite = false ) {
		return in_array( $destination, self::$failed_moves, true ) ? false : parent::move( $source, $destination, $overwrite );
	}

	public function delete( $file, $recursive = false, $type = false ) {
		return in_array( $file, self::$failed_deletes, true ) ? false : parent::delete( $file, $recursive, $type );
	}

	public function put_contents( $file, $contents, $mode = false ) {
		return in_array( $file, self::$failed_writes, true ) ? false : parent::put_contents( $file, $contents, $mode );
	}
}

/** Real WordPress queries, template files, stylesheet output and revision storage. */
final class TemplateManagementTest extends TestCase {
	private $user;
	private $paths = array();
	private $posts = array();
	private $terms = array();
	private $options = array();
	private $query;
	private $styles;
	private $head_count;
	private $server;
	private $buffer_level;
	private $global_post;

	protected function setUp(): void {
		$this->buffer_level = ob_get_level();
		$this->server = $_SERVER;
		$_SERVER['SERVER_NAME'] = 'localhost';
		$_SERVER['SERVER_PORT'] = '8080';
		$this->user = wp_insert_user( array( 'user_login' => 'imj-manager-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
		wp_set_current_user( $this->user );
		register_post_type( 'imj_item', array( 'public' => true, 'has_archive' => true, 'label' => 'Test items' ) );
		register_taxonomy( 'imj_group', 'imj_item', array( 'public' => true, 'hierarchical' => true, 'label' => 'Test groups' ) );
		$this->query = $GLOBALS['wp_query'];
		$this->global_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$this->styles = $GLOBALS['wp_styles'];
		$this->head_count = isset( $GLOBALS['wp_actions']['wp_head'] ) ? $GLOBALS['wp_actions']['wp_head'] : null;
		$GLOBALS['wp_styles'] = new WP_Styles();
		wp_register_style( 'imajiner-base', get_template_directory_uri() . '/assets/css/base.css' );
		foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts' ) as $option ) {
			$this->options[ $option ] = get_option( $option );
		}
	}

	protected function tearDown(): void {
		while ( ob_get_level() > $this->buffer_level ) {
			ob_end_clean();
		}
		$_SERVER = $this->server;
		foreach ( array_unique( $this->paths ) as $path ) {
			foreach ( Imajiner_Template_Store::get_revisions( $path ) as $revision ) {
				wp_delete_post( $revision['id'], true );
			}
			foreach ( array( $path, Imajiner_Template_Store::css_path( $path ) ) as $file ) {
				if ( is_file( $file ) || is_link( $file ) ) {
					unlink( $file );
				}
			}
		}
		foreach ( $this->posts as $post ) {
			wp_delete_post( $post, true );
		}
		foreach ( $this->terms as $term ) {
			wp_delete_term( $term, 'imj_group' );
		}
		foreach ( $this->options as $option => $value ) {
			update_option( $option, $value );
		}
		unregister_taxonomy( 'imj_group' );
		unregister_post_type( 'imj_item' );
		wp_delete_user( $this->user );
		wp_set_current_user( 0 );
		$GLOBALS['wp_query'] = $this->query;
		$GLOBALS['wp_the_query'] = $this->query;
		$GLOBALS['post'] = $this->global_post;
		$GLOBALS['wp_styles'] = $this->styles;
		if ( null === $this->head_count ) {
			unset( $GLOBALS['wp_actions']['wp_head'] );
		} else {
			$GLOBALS['wp_actions']['wp_head'] = $this->head_count;
		}
		wp_clean_themes_cache( false );
	}

	private function fixture( $locations = '', $part = false, $extra = '' ) {
		$slug = 'imj-manager-' . substr( wp_generate_uuid4(), 0, 8 );
		$key = ( $part ? 'parts/' : '' ) . $slug;
		$path = get_stylesheet_directory() . '/imajiner/' . $key . '.php';
		$header = $part ? " * Part Name: $slug\n * Part Location: $locations" : " * Template Name: $slug\n * Template Post Type: page, post, imj_item\n * Imajiner Location: $locations";
		$php = "<?php\n/**\n$header\n$extra\n */\n" . ( $part ? '' : "get_header();\n" ) . "?>\n<!-- imj:section name=\"test\" -->\n<section class=\"test\">Fixture markup</section>\n<!-- /imj:section -->\n" . ( $part ? '' : "<?php get_footer();\n" );
		$scope = $part ? '.imj-part-' : '.imj-';
		$files = array( 'php' => $php, 'css' => $scope . $slug . ' .test { color: var(--imj-color-primary); }' );
		$this->paths[] = $path;
		$this->assertTrue( Imajiner_Template_Store::create( $path, $files ) );
		$scanner = new Imajiner_Template_Scanner( $php );
		$this->assertTrue( $scanner->is_lossless() );
		return array( 'key' => $key, 'slug' => $slug, 'path' => $path, 'files' => $files );
	}

	private function post( $type = 'imj_item' ) {
		$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Disposable template fixture ' . wp_generate_uuid4() ) );
		$this->posts[] = $id;
		return $id;
	}

	private function request( array $fixture, $operation = 'rename' ) {
		return array( 'template' => $fixture['key'], '_wpnonce' => wp_create_nonce( 'imajiner_manage_template_' . $fixture['key'] ), 'hash' => Imajiner_Template_Store::hash( $fixture['files'] ), 'confirm' => 'yes', 'operation' => $operation, 'slug' => $fixture['slug'] . '-renamed' );
	}

	private function query( array $args ) {
		$query = new WP_Query();
		$query->query( $args );
		$GLOBALS['wp_query'] = $query;
		$GLOBALS['wp_the_query'] = $query;
		$GLOBALS['post'] = $query->post;
		if ( $query->post ) {
			setup_postdata( $query->post );
		}
		return $query;
	}

	public function testRenameMovesPairAssignmentsLocationsAndRestorableHistory(): void {
		$f = $this->fixture( 'single:imj_item' );
		$id = $this->post();
		update_post_meta( $id, '_wp_page_template', 'imajiner/' . $f['key'] . '.php' );
		$r = $this->request( $f );
		$target = get_stylesheet_directory() . '/imajiner/' . $r['slug'] . '.php';
		$this->paths[] = $target;
		$this->assertTrue( Imajiner_Template_Manager::manage( $r ) );
		$this->assertFileDoesNotExist( $f['path'] );
		$this->assertFileDoesNotExist( Imajiner_Template_Store::css_path( $f['path'] ) );
		$this->assertSame( $f['files']['php'], file_get_contents( $target ) );
		$this->assertStringContainsString( '.imj-' . $r['slug'], file_get_contents( Imajiner_Template_Store::css_path( $target ) ) );
		$this->assertSame( 'imajiner/' . $r['slug'] . '.php', get_post_meta( $id, '_wp_page_template', true ) );
		$this->assertSame( $r['slug'], imajiner_location_assignments()['single:imj_item'] );
		$revisions = Imajiner_Template_Store::get_revisions( $target );
		$this->assertCount( 1, $revisions );
		$revision = Imajiner_Template_Store::get_revision_files( $target, $revisions[0]['id'] );
		$this->assertSame( $f['files']['php'], $revision['php'] );
		$this->assertStringContainsString( '.imj-' . $r['slug'], $revision['css'] );
		$current = Imajiner_Template_Store::read( $target );
		$this->assertTrue( Imajiner_Template_Store::write( $target, Imajiner_Template_Store::hash( $current ), $revision, 'Test restore' ) );
	}

	public function testDeleteRemovesBothFilesResetsAssignmentsAndKeepsRevision(): void {
		$f = $this->fixture( 'archive:imj_item' );
		$id = $this->post( 'page' );
		update_post_meta( $id, '_wp_page_template', 'imajiner/' . $f['key'] . '.php' );
		$this->assertTrue( Imajiner_Template_Manager::manage( $this->request( $f, 'delete' ) ) );
		$this->assertFileDoesNotExist( $f['path'] );
		$this->assertFileDoesNotExist( Imajiner_Template_Store::css_path( $f['path'] ) );
		$this->assertSame( 'default', get_post_meta( $id, '_wp_page_template', true ) );
		$this->assertArrayNotHasKey( 'archive:imj_item', imajiner_location_assignments() );
		$revisions = Imajiner_Template_Store::get_revisions( $f['path'] );
		$this->assertCount( 1, $revisions );
		$this->assertSame( $f['files'], Imajiner_Template_Store::get_revision_files( $f['path'], $revisions[0]['id'] ) );
	}

	public function testTransportFailuresRestorePairsAndReportFailedRollback(): void {
		$transport = function () { return 'imjtemplatefault'; };
		$previous = isset( $GLOBALS['wp_filesystem'] ) ? $GLOBALS['wp_filesystem'] : null;
		try {
			foreach ( array( 'rename', 'delete', 'rollback' ) as $operation ) {
				remove_filter( 'filesystem_method', $transport );
				WP_Filesystem_imjtemplatefault::$failed_moves = array();
				WP_Filesystem_imjtemplatefault::$failed_deletes = array();
				WP_Filesystem_imjtemplatefault::$failed_writes = array();
				$f = $this->fixture( 'single:imj_item' );
				$id = $this->post();
				update_post_meta( $id, '_wp_page_template', 'imajiner/' . $f['key'] . '.php' );
				$request = $this->request( $f, 'rename' === $operation ? 'rename' : 'delete' );
				$target = get_stylesheet_directory() . '/imajiner/' . $request['slug'] . '.php';
				$this->paths[] = $target;
				if ( 'rename' === $operation ) {
					WP_Filesystem_imjtemplatefault::$failed_moves = array( $target );
				} else {
					WP_Filesystem_imjtemplatefault::$failed_deletes = array( $f['path'] );
				}
				if ( 'rollback' === $operation ) {
					WP_Filesystem_imjtemplatefault::$failed_writes = array( Imajiner_Template_Store::css_path( $f['path'] ) );
				}
				$GLOBALS['wp_filesystem'] = new WP_Filesystem_imjtemplatefault( false );
				add_filter( 'filesystem_method', $transport );
				$result = Imajiner_Template_Manager::manage( $request );
				$this->assertInstanceOf( WP_Error::class, $result );
				$this->assertSame( $f['files']['php'], file_get_contents( $f['path'] ) );
				$this->assertSame( 'imajiner/' . $f['key'] . '.php', get_post_meta( $id, '_wp_page_template', true ) );
				$this->assertFileDoesNotExist( $target );
				$this->assertFileDoesNotExist( Imajiner_Template_Store::css_path( $target ) );
				$revisions = Imajiner_Template_Store::get_revisions( $f['path'] );
				$this->assertCount( 1, $revisions );
				$this->assertSame( $f['files'], Imajiner_Template_Store::get_revision_files( $f['path'], $revisions[0]['id'] ) );
				if ( 'rollback' === $operation ) {
					$this->assertSame( 'imajiner_rollback', $result->get_error_code() );
				} else {
					$this->assertSame( $f['files']['css'], file_get_contents( Imajiner_Template_Store::css_path( $f['path'] ) ) );
				}
			}
		} finally {
			remove_filter( 'filesystem_method', $transport );
			WP_Filesystem_imjtemplatefault::$failed_moves = array();
			WP_Filesystem_imjtemplatefault::$failed_deletes = array();
			WP_Filesystem_imjtemplatefault::$failed_writes = array();
			$GLOBALS['wp_filesystem'] = $previous;
		}
	}

	public function testPartRenameRescopesCssAndRetainsPortableConditions(): void {
		$f = $this->fixture( 'tha_footer_before', true, " * Part Post Types: page\n * Part Exclude: front, home" );
		$r = $this->request( $f );
		$target = get_stylesheet_directory() . '/imajiner/parts/' . $r['slug'] . '.php';
		$this->paths[] = $target;
		$this->assertTrue( Imajiner_Template_Manager::manage( $r ) );
		$this->assertSame( $f['files']['php'], file_get_contents( $target ) );
		$this->assertStringContainsString( '.imj-part-' . $r['slug'], file_get_contents( Imajiner_Template_Store::css_path( $target ) ) );
		$this->assertSame( array( 'front', 'home' ), imajiner_get_parts()[ $r['slug'] ]['exclude'] );
		$new = array_merge( $f, array( 'key' => 'parts/' . $r['slug'], 'slug' => $r['slug'], 'path' => $target, 'files' => Imajiner_Template_Store::read( $target ) ) );
		$this->assertTrue( Imajiner_Template_Manager::manage( $this->request( $new, 'delete' ) ) );
		$this->assertFileDoesNotExist( $target );
	}

	public function testConfirmationNoncePermissionsStaleHashAndCollisionsDoNotWrite(): void {
		$f = $this->fixture();
		$r = $this->request( $f );
		foreach ( array( 'confirm' => 'no', '_wpnonce' => 'invalid', 'hash' => str_repeat( '0', 32 ), 'slug' => '../escape' ) as $field => $value ) {
			$result = Imajiner_Template_Manager::manage( array_merge( $r, array( $field => $value ) ) );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( $f['files'], Imajiner_Template_Store::read( $f['path'] ) );
		}
		wp_set_current_user( 0 );
		$this->assertSame( 'imajiner_forbidden', Imajiner_Template_Manager::manage( $r )->get_error_code() );
		wp_set_current_user( $this->user );
		$other = $this->fixture();
		$this->assertSame( 'imajiner_exists', Imajiner_Template_Manager::manage( array_merge( $r, array( 'slug' => $other['slug'] ) ) )->get_error_code() );
		$orphan = Imajiner_Template_Store::css_path( get_stylesheet_directory() . '/imajiner/' . $r['slug'] . '.php' );
		$this->paths[] = substr( dirname( $orphan ), 0, -4 ) . '/' . basename( $orphan, '.css' ) . '.php';
		file_put_contents( $orphan, 'orphan stylesheet' );
		$this->assertSame( 'imajiner_exists', Imajiner_Template_Manager::manage( $r )->get_error_code() );
		$this->assertSame( 'orphan stylesheet', file_get_contents( $orphan ) );
		file_put_contents( Imajiner_Template_Store::css_path( $f['path'] ), 'Changed by another editor' );
		$this->assertSame( 'imajiner_conflict', Imajiner_Template_Manager::manage( $r )->get_error_code() );
		$this->assertCount( 0, Imajiner_Template_Store::get_revisions( $f['path'] ) );
	}

	public function testPathsRejectTraversalParentFilesAndSymlinkedCss(): void {
		foreach ( array( '../index', 'parts/../index', '/index', 'parts/a/b', 'parts/', 'A', 'a.php', 'a/../../x' ) as $key ) {
			$this->assertInstanceOf( WP_Error::class, Imajiner_Template_Manager::path( $key ) );
		}
		$f = $this->fixture();
		$css = Imajiner_Template_Store::css_path( $f['path'] );
		unlink( $css );
		$outside = get_template_directory() . '/style.css';
		$before = file_get_contents( $outside );
		symlink( $outside, $css );
		$this->assertSame( 'imajiner_path', Imajiner_Template_Manager::manage( $this->request( $f, 'delete' ) )->get_error_code() );
		$this->assertSame( $before, file_get_contents( $outside ) );
		$this->assertFileExists( $f['path'] );
		$parent_key = 'imj-manager-parent-' . substr( wp_generate_uuid4(), 0, 8 );
		$parent = get_template_directory() . '/imajiner/' . $parent_key . '.php';
		$this->paths[] = $parent;
		wp_mkdir_p( dirname( $parent ) );
		file_put_contents( $parent, $f['files']['php'] );
		$r = $this->request( array_merge( $f, array( 'key' => $parent_key ) ), 'delete' );
		$this->assertSame( 'imajiner_missing', Imajiner_Template_Manager::manage( $r )->get_error_code() );
		$this->assertFileExists( $parent );
	}

	public function testRegisteredCptTaxonomyTermAndMostSpecificResolution(): void {
		$id = $this->post();
		$term = wp_insert_term( 'Disposable group', 'imj_group', array( 'slug' => 'group-' . substr( wp_generate_uuid4(), 0, 8 ) ) );
		$this->terms[] = $term['term_id'];
		$object = get_term( $term['term_id'], 'imj_group' );
		wp_set_object_terms( $id, array( $object->term_id ), 'imj_group' );
		$generic = $this->fixture( 'single, archive' );
		$single = $this->fixture( 'single:imj_item' );
		$archive = $this->fixture( 'archive:imj_item' );
		$taxonomy = $this->fixture( 'taxonomy:imj_group' );
		$specific = $this->fixture( 'taxonomy:imj_group:' . $object->slug );
		$locations = imajiner_template_locations();
		foreach ( array( 'single:imj_item', 'archive:imj_item', 'taxonomy:imj_group', 'taxonomy:imj_group:' . $object->slug ) as $location ) {
			$this->assertArrayHasKey( $location, $locations );
		}
		$this->query( array( 'p' => $id, 'post_type' => 'imj_item' ) );
		$this->assertSame( array( 'single:imj_item', 'single' ), imajiner_request_locations() );
		$this->assertSame( $single['path'], imajiner_template_include( 'fallback.php' ) );
		update_post_meta( $id, '_wp_page_template', 'imajiner/' . $generic['key'] . '.php' );
		$this->assertSame( 'explicit.php', imajiner_template_include( 'explicit.php' ) );
		$this->query( array( 'post_type' => 'imj_item' ) );
		$this->assertSame( $archive['path'], imajiner_template_include( 'fallback.php' ) );
		$this->query( array( 'imj_group' => $object->slug ) );
		$this->assertSame( array( 'taxonomy:imj_group:' . $object->slug, 'taxonomy:imj_group', 'archive' ), imajiner_request_locations() );
		$this->assertSame( $specific['path'], imajiner_template_include( 'fallback.php' ) );
		unlink( $specific['path'] );
		$this->assertSame( $taxonomy['path'], imajiner_template_include( 'fallback.php' ) );
		unlink( $taxonomy['path'] );
		$this->assertSame( $generic['path'], imajiner_template_include( 'fallback.php' ) );
	}

	public function testStaticFrontBlogAndPostsOnFrontLocations(): void {
		$front_id = $this->post( 'page' );
		$blog_id = $this->post( 'page' );
		$front = $this->fixture( 'front' );
		$home = $this->fixture( 'home' );
		$this->fixture( 'single:page, archive:post, archive' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_id );
		update_option( 'page_for_posts', $blog_id );
		$this->query( array( 'page_id' => $front_id ) );
		$this->assertSame( array( 'front', 'single:page', 'single' ), imajiner_request_locations() );
		$this->assertSame( $front['path'], imajiner_template_include( 'fallback.php' ) );
		$this->query( array( 'page_id' => $blog_id ) );
		$this->assertSame( array( 'home', 'archive:post', 'archive' ), imajiner_request_locations() );
		$this->assertSame( $home['path'], imajiner_template_include( 'fallback.php' ) );
		update_option( 'show_on_front', 'posts' );
		$this->query( array() );
		$this->assertSame( array( 'front', 'home', 'archive:post', 'archive' ), imajiner_request_locations() );
		$this->assertSame( $front['path'], imajiner_template_include( 'fallback.php' ) );
	}

	public function testConditionSettingsArePortableRevisedValidatedAndStaleProtected(): void {
		$f = $this->fixture( '', true );
		$settings = array( 'location' => 'tha_footer_before', 'post_types' => array( 'imj_item', 'page' ), 'include' => array( 'single' ), 'exclude' => array( 'front', 'home' ) );
		$this->assertTrue( Imajiner_Builder::save_part_settings( $f['key'], Imajiner_Template_Store::hash( $f['files'] ), $settings ) );
		$part = imajiner_get_parts()[ $f['slug'] ];
		$this->assertSame( $settings['post_types'], $part['post_types'] );
		$this->assertSame( $settings['include'], $part['include'] );
		$this->assertSame( $settings['exclude'], $part['exclude'] );
		$this->assertCount( 1, Imajiner_Template_Store::get_revisions( $f['path'] ) );
		$this->assertSame( 'imajiner_conflict', Imajiner_Builder::save_part_settings( $f['key'], Imajiner_Template_Store::hash( $f['files'] ), $settings )->get_error_code() );
		$before = Imajiner_Template_Store::read( $f['path'] );
		$settings['include'] = array( 'front */ injected' );
		$this->assertSame( 'imajiner_condition', Imajiner_Builder::save_part_settings( $f['key'], Imajiner_Template_Store::hash( $before ), $settings )->get_error_code() );
		$this->assertSame( $before, Imajiner_Template_Store::read( $f['path'] ) );
	}

	public function testConditionsControlExplicitAndLocatedPartsWithoutEnqueuingUnusedStyles(): void {
		$page = $this->post( 'page' );
		$item = $this->post();
		$f = $this->fixture( 'tha_footer_before', true, " * Part Post Types: page\n * Part Include: single\n * Part Exclude: front, home" );
		$this->query( array( 'p' => $item, 'post_type' => 'imj_item' ) );
		ob_start();
		$this->assertFalse( imajiner_part( $f['slug'] ) );
		$this->assertFalse( imajiner_render_location( 'tha_footer_before' ) );
		$this->assertSame( '', ob_get_clean() );
		$this->assertFalse( wp_style_is( 'imajiner-part-' . $f['slug'], 'enqueued' ) );
		$this->query( array( 'page_id' => $page ) );
		ob_start();
		$this->assertTrue( imajiner_part( $f['slug'] ) );
		$this->assertStringContainsString( 'imj-part-' . $f['slug'], ob_get_clean() );
		$this->assertTrue( wp_style_is( 'imajiner-part-' . $f['slug'], 'enqueued' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page );
		$this->query( array( 'page_id' => $page ) );
		ob_start();
		$this->assertFalse( imajiner_part( $f['slug'] ) );
		$this->assertSame( '', ob_get_clean() );
	}

	public function testLateExplicitPartPrintsItsActualLinkOnlyOnceAfterHeadAndFooter(): void {
		$f = $this->fixture( '', true );
		$unused = $this->fixture( '', true );
		$this->query( array( 'page_id' => $this->post( 'page' ) ) );
		ob_start();
		wp_head();
		$head = ob_get_clean();
		$this->assertStringNotContainsString( '/parts/css/' . $f['slug'], $head );
		ob_start();
		wp_footer();
		$this->assertTrue( imajiner_part( $f['slug'] ) );
		$this->assertTrue( imajiner_part( $f['slug'] ) );
		$output = ob_get_clean();
		$this->assertSame( 1, substr_count( $output, "id='imajiner-part-" . $f['slug'] . "-css'" ) );
		$this->assertStringContainsString( '/parts/css/' . $f['slug'] . '.css', $output );
		$this->assertStringNotContainsString( '/parts/css/' . $unused['slug'] . '.css', $output );
		$this->assertSame( 2, substr_count( $output, 'class="imj-part imj-part-' . $f['slug'] . '"' ) );
	}

	public function testExcludedHeaderAndFooterFallBackToThemeMarkup(): void {
		$page = $this->post( 'page' );
		$this->fixture( 'header', true, ' * Part Exclude: single:page' );
		$this->fixture( 'footer', true, ' * Part Exclude: single:page' );
		$this->query( array( 'page_id' => $page ) );
		ob_start();
		get_header();
		get_footer();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'class="site-header"', $output );
		$this->assertStringContainsString( 'class="site-footer"', $output );
		$this->assertStringNotContainsString( 'Fixture markup', $output );
	}

	public function testManagerFormsHaveIndependentConfirmationNonceAndHashes(): void {
		$f = $this->fixture();
		$p = $this->fixture( '', true );
		ob_start();
		Imajiner_Builder::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="hashes[' . $f['key'] . ']"', $html );
		$this->assertStringContainsString( 'name="hashes[' . $p['key'] . ']"', $html );
		$this->assertStringContainsString( 'name="confirm" value="yes" required', $html );
		$this->assertStringContainsString( 'name="conditions[' . $p['slug'] . '][post_types][]"', $html );
		$dom = new DOMDocument();
		@$dom->loadHTML( $html );
		$xpath = new DOMXPath( $dom );
		$this->assertSame( 0, $xpath->query( '//form//form' )->length );
	}

	/** Fresh requests are important: the preview's current() is request-cached. */
	private function front_end_request( $code, $plugin_off = false ) {
		$prefix = '$_SERVER["HTTP_HOST"]="localhost"; $_SERVER["SERVER_NAME"]="localhost"; $_SERVER["SERVER_PORT"]="8080"; $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REQUEST_URI"]="/"; define("DISABLE_WP_CRON",true); ';
		if ( $plugin_off ) {
			$prefix .= 'putenv("IMAJINER_PLUGIN_OFF_TEST=1"); require ' . var_export( ABSPATH . 'wp-includes/plugin.php', true ) . '; add_filter("option_active_plugins","__return_empty_array"); ';
		}
		$prefix .= 'require ' . var_export( ABSPATH . 'wp-load.php', true ) . '; ';
		$pipes = array();
		$process = proc_open( array( PHP_BINARY, '-d', 'mysqli.default_socket=' . ini_get( 'mysqli.default_socket' ), '-r', $prefix . $code ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $errors );
		$this->assertSame( '', $errors );
		return $output;
	}

	public function testPartPreviewBypassesConditionsAndPrintsOriginalStylesheet(): void {
		$f = $this->fixture( '', true, ' * Part Exclude: home, front, single' );
		$code = 'wp_set_current_user(' . $this->user . '); $key=' . var_export( $f['key'], true ) . '; $_GET["imajiner_template"]=$key; $_GET["imajiner_preview"]=wp_create_nonce("imajiner_preview_".$key); $GLOBALS["wp_query"]=new WP_Query(array("post_type"=>"post")); $GLOBALS["wp_the_query"]=$GLOBALS["wp_query"]; wp_head(); imajiner_part(' . var_export( $f['slug'], true ) . ');';
		$output = $this->front_end_request( $code );
		$this->assertStringContainsString( 'data-imj-id=', $output );
		$this->assertStringContainsString( 'Fixture markup', $output );
		$this->assertStringContainsString( '/parts/css/' . $f['slug'] . '.css', $output );
	}

	public function testStagedPartPreviewDoesNotReenqueueSavedStyles(): void {
		$f = $this->fixture( '', true, ' * Part Exclude: home, front' );
		$stage_id = wp_generate_uuid4();
		$stage_key = 'imajiner_stage_' . $this->user . '_' . $stage_id;
		set_transient( $stage_key, array( 'key' => $f['key'], 'stylesheet' => get_stylesheet(), 'hash' => Imajiner_Template_Store::hash( $f['files'] ), 'files' => array( 'php' => str_replace( 'Fixture markup', 'Staged markup', $f['files']['php'] ), 'css' => '.imj-part-' . $f['slug'] . ' .test { color: var(--imj-color-secondary); }' ) ), HOUR_IN_SECONDS );
		try {
			$code = 'wp_set_current_user(' . $this->user . '); $key=' . var_export( $f['key'], true ) . '; $_GET["imajiner_template"]=$key; $_GET["imajiner_preview"]=wp_create_nonce("imajiner_preview_".$key); $_GET["imajiner_stage"]=' . var_export( $stage_id, true ) . '; $GLOBALS["wp_query"]=new WP_Query(array("post_type"=>"post")); $GLOBALS["wp_the_query"]=$GLOBALS["wp_query"]; wp_head(); imajiner_part(' . var_export( $f['slug'], true ) . ');';
			$output = $this->front_end_request( $code );
			$this->assertStringContainsString( 'Staged markup', $output );
			$this->assertStringContainsString( 'var(--imj-color-secondary)', $output );
			$this->assertStringNotContainsString( '/parts/css/' . $f['slug'] . '.css', $output );
		} finally {
			delete_transient( $stage_key );
		}
	}

	public function testPluginOffThemeRuntimeStillResolvesAndStylesParts(): void {
		$page = $this->post( 'page' );
		$f = $this->fixture( 'single:page' );
		$p = $this->fixture( 'tha_footer_before', true, ' * Part Post Types: page' );
		$code = '$GLOBALS["wp_query"]=new WP_Query(array("page_id"=>' . $page . ')); $GLOBALS["wp_the_query"]=$GLOBALS["wp_query"]; $GLOBALS["post"]=$GLOBALS["wp_query"]->post; setup_postdata($GLOBALS["post"]); echo class_exists("Imajiner_Editor") ? "PLUGIN_PRESENT" : "PLUGIN_ABSENT"; echo imajiner_template_include("fallback.php"); wp_head(); imajiner_render_location("tha_footer_before");';
		$output = $this->front_end_request( $code, true );
		$this->assertStringContainsString( 'PLUGIN_ABSENT', $output );
		$this->assertStringContainsString( $f['path'], $output );
		$this->assertStringContainsString( 'imj-part-' . $p['slug'], $output );
		$this->assertStringContainsString( '/parts/css/' . $p['slug'] . '.css', $output );
	}
}
