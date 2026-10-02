<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-imajiner-site-setup.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

final class WP_Filesystem_imajiner_theme_failure extends WP_Filesystem_Direct {
	public function put_contents( $file, $contents, $mode = false ) {
		if ( 0 === strpos( $contents, "# This site's Imajiner child theme" ) ) {
			parent::put_contents( dirname( $file ) . '/concurrent-content.txt', 'Keep unrelated content.', $mode );
			return false;
		}
		return parent::put_contents( $file, $contents, $mode );
	}
}

final class ThemeSetupTest extends TestCase {
	private static $admin;
	private static $subscriber;
	private $slug;
	private $original_theme;
	private $themes = array();
	private $menus = array();

	public static function setUpBeforeClass(): void {
		$_SERVER['SERVER_NAME'] = 'localhost';
		if ( ! class_exists( 'Imajiner_Filesystem' ) ) {
			throw new RuntimeException( 'Load the shared Imajiner_Filesystem service before running setup tests.' );
		}
		$suffix = wp_generate_password( 12, false );
		self::$admin = wp_insert_user( array( 'user_login' => 'imj-theme-admin-' . $suffix, 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
		self::$subscriber = wp_insert_user( array( 'user_login' => 'imj-theme-reader-' . $suffix, 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	}

	public static function tearDownAfterClass(): void {
		wp_delete_user( self::$admin );
		wp_delete_user( self::$subscriber );
	}

	protected function setUp(): void {
		$this->slug = 'test-' . substr( wp_generate_uuid4(), 0, 8 );
		$this->original_theme = get_stylesheet();
		wp_set_current_user( self::$admin );
		$this->themes = array();
		$this->menus = array();
	}

	protected function tearDown(): void {
		switch_theme( $this->original_theme );
		foreach ( $this->menus as $menu ) {
			wp_delete_nav_menu( $menu );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		foreach ( $this->themes as $theme ) {
			$path = get_theme_root( 'imajiner' ) . '/' . $theme;
			if ( is_dir( $path ) ) {
				$wp_filesystem->delete( $path, true );
			}
			delete_option( 'theme_mods_' . $theme );
			delete_option( 'imajiner_child_setup_' . md5( $path ) );
		}
		wp_clean_themes_cache();
		wp_set_current_user( 0 );
	}

	private function create( $suffix = '', $name = '' ) {
		$slug = $this->slug . $suffix;
		$this->themes[] = 'imajiner-' . $slug;
		return Imajiner_Site_Setup::create( $slug, $name );
	}

	public function test_scaffold_is_discoverable_independent_and_never_activates_or_copies_demo_content(): void {
		$demo = get_theme_root() . '/imajiner-child/imajiner/page-example.php';
		$hash = hash_file( 'sha256', $demo );
		$theme = $this->create( '', 'Temporary theme' );
		self::assertIsString( $theme );
		self::assertSame( $this->original_theme, get_stylesheet() );
		self::assertSame( 'imajiner', wp_get_theme( $theme )->get_template() );
		self::assertSame( 'Temporary theme', wp_get_theme( $theme )->get( 'Name' ) );
		$path = wp_get_theme( $theme )->get_stylesheet_directory();
		self::assertFileExists( $path . '/assets/css/design-tokens.css' );
		self::assertFileExists( $path . '/imajiner/parts/css/.gitkeep' );
		self::assertFileDoesNotExist( $path . '/.git' );
		self::assertFileDoesNotExist( $path . '/imajiner/page-example.php' );
		self::assertSame( array(), glob( $path . '/imajiner/*.php' ) );
		self::assertSame( $hash, hash_file( 'sha256', $demo ) );
		self::assertSame( "\x89PNG\r\n\x1a\n", substr( file_get_contents( $path . '/screenshot.png' ), 0, 8 ) );
		$other = $this->create( '-other' );
		self::assertIsString( $other );
		self::assertNotSame( $theme, $other );
		self::assertSame( 'imajiner', wp_get_theme( $other )->get_template() );
	}

	/** @dataProvider invalid_slugs */
	public function test_unsafe_or_nonportable_slugs_are_rejected( $slug ): void {
		$result = Imajiner_Site_Setup::create( $slug );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'imajiner_setup_slug', $result->get_error_code() );
	}

	public function invalid_slugs(): array {
		return array_map( static function ( $slug ) { return array( $slug ); }, array( '', '../escape', 'a/b', 'a\\b', 'UPPER', 'two words', '-start', 'end-', 'café', str_repeat( 'a', 49 ) ) );
	}

	public function test_existing_theme_is_never_overwritten(): void {
		$theme = $this->create();
		self::assertIsString( $theme );
		$path = wp_get_theme( $theme )->get_stylesheet_directory() . '/style.css';
		$original = file_get_contents( $path );
		$result = Imajiner_Site_Setup::create( $this->slug, 'Replacement' );
		self::assertSame( 'imajiner_setup_exists', $result->get_error_code() );
		self::assertSame( $original, file_get_contents( $path ) );
		$demo = Imajiner_Site_Setup::create( 'child' );
		self::assertSame( 'imajiner_setup_exists', $demo->get_error_code() );
	}

	public function test_permissions_and_file_modification_policy_are_enforced(): void {
		wp_set_current_user( self::$subscriber );
		self::assertSame( 'imajiner_setup_forbidden', $this->create()->get_error_code() );
		wp_set_current_user( self::$admin );
		add_filter( 'file_mod_allowed', '__return_false' );
		try {
			self::assertSame( 'imajiner_setup_forbidden', $this->create()->get_error_code() );
		} finally {
			remove_filter( 'file_mod_allowed', '__return_false' );
		}
		self::assertDirectoryDoesNotExist( get_theme_root() . '/imajiner-' . $this->slug );
	}

	public function test_name_cannot_inject_theme_headers_or_close_the_css_comment(): void {
		$theme = $this->create( '', "Test */\nTemplate: unwanted\n/*" );
		self::assertIsString( $theme );
		self::assertSame( 'imajiner', wp_get_theme( $theme )->get( 'Template' ) );
		self::assertSame( 'Test Template: unwanted', wp_get_theme( $theme )->get( 'Name' ) );
	}

	public function test_creation_lock_blocks_parallel_setup_and_recovers_expired_lock(): void {
		$path = get_theme_root( 'imajiner' ) . '/imajiner-' . $this->slug;
		$lock = 'imajiner_child_setup_' . md5( $path );
		$this->themes[] = 'imajiner-' . $this->slug;
		add_option( $lock, time(), '', false );
		self::assertSame( 'imajiner_setup_busy', Imajiner_Site_Setup::create( $this->slug )->get_error_code() );
		update_option( $lock, time() - 301 );
		self::assertIsString( Imajiner_Site_Setup::create( $this->slug ) );
		self::assertFalse( get_option( $lock ) );
	}

	public function test_filesystem_initialization_failure_creates_no_theme(): void {
		$filter = static function () { return 'not-a-transport'; };
		add_filter( 'filesystem_method', $filter );
		try {
			self::assertInstanceOf( WP_Error::class, $this->create() );
			self::assertDirectoryDoesNotExist( get_theme_root() . '/imajiner-' . $this->slug );
		} finally {
			remove_filter( 'filesystem_method', $filter );
		}
	}

	public function test_persistent_tokens_load_after_child_styles_and_in_block_editor_with_mtime(): void {
		$theme = $this->create();
		self::assertIsString( $theme );
		switch_theme( $theme );
		$path = imajiner_design_token_file();
		self::assertSame( get_stylesheet_directory() . '/assets/css/design-tokens.css', $path );
		$mtime = (string) filemtime( $path );
		wp_dequeue_style( 'imajiner-design-tokens' );
		wp_deregister_style( 'imajiner-design-tokens' );
		imajiner_enqueue_assets();
		$style = wp_styles()->registered['imajiner-design-tokens'];
		self::assertSame( array( 'imajiner-base', 'imajiner-child' ), $style->deps );
		self::assertStringContainsString( 'ver=' . $mtime, $style->src );
		self::assertContains( $path, imajiner_design_token_sources() );
		$settings = apply_filters( 'block_editor_settings_all', array( 'styles' => array() ), null );
		self::assertStringContainsString( 'assets/css/design-tokens.css?ver=' . $mtime, end( $settings['styles'] )['css'] );
		self::assertGreaterThan( 0, did_action( 'imajiner_design_tokens_loaded' ) );
		unlink( $path );
		self::assertSame( '', imajiner_design_token_url() );
		self::assertSame( array( 'styles' => array() ), imajiner_editor_design_tokens( array( 'styles' => array() ) ) );
	}

	public function test_write_failure_removes_attempted_files_but_preserves_unrelated_directory_content(): void {
		$filter = static function () { return 'imajiner_theme_failure'; };
		add_filter( 'filesystem_method', $filter );
		try {
			$result = $this->create();
			self::assertInstanceOf( WP_Error::class, $result );
			self::assertContains( 'imajiner_setup_incomplete', $result->get_error_codes() );
			$path = get_theme_root( 'imajiner' ) . '/imajiner-' . $this->slug;
			self::assertFileDoesNotExist( $path . '/style.css' );
			self::assertFileDoesNotExist( $path . '/functions.php' );
			self::assertSame( 'Keep unrelated content.', file_get_contents( $path . '/concurrent-content.txt' ) );
			self::assertFalse( get_option( 'imajiner_child_setup_' . md5( $path ) ) );
			self::assertSame( $this->original_theme, get_stylesheet() );
		} finally {
			remove_filter( 'filesystem_method', $filter );
		}
	}

	public function test_theme_json_presets_reference_frontend_tokens_and_real_png_previews(): void {
		foreach ( array( 'imajiner', 'imajiner-child' ) as $theme ) {
			$path = wp_get_theme( $theme )->get_stylesheet_directory();
			$json = json_decode( file_get_contents( $path . '/theme.json' ), true );
			self::assertSame( 3, $json['version'] );
			self::assertSame( 'var(--imj-color-primary)', $json['settings']['color']['palette'][4]['color'] );
			self::assertSame( 'var(--imj-font-body)', $json['styles']['typography']['fontFamily'] );
			self::assertSame( 'var(--imj-space-1)', $json['settings']['spacing']['spacingSizes'][0]['size'] );
			self::assertSame( array( 1200, 900 ), array_slice( getimagesize( $path . '/screenshot.png' ), 0, 2 ) );
		}
		self::assertTrue( current_theme_supports( 'editor-styles' ) );
		self::assertStringContainsString( '--imj-color-primary', WP_Theme_JSON_Resolver::get_theme_data()->get_stylesheet() );
	}

	public function test_default_header_outputs_progressive_toggle_only_when_menu_is_assigned(): void {
		$theme = $this->create();
		self::assertIsString( $theme );
		switch_theme( $theme );
		$menu = wp_create_nav_menu( 'Temporary menu ' . $this->slug );
		self::assertIsInt( $menu );
		$this->menus[] = $menu;
		$parent = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Parent item', 'menu-item-url' => home_url( '/' ), 'menu-item-status' => 'publish' ) );
		wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Child item', 'menu-item-parent-id' => $parent, 'menu-item-url' => home_url( '/child/' ), 'menu-item-status' => 'publish' ) );
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu ) );
		ob_start();
		include get_template_directory() . '/header.php';
		$header = ob_get_clean();
		self::assertStringContainsString( 'aria-expanded="false" aria-controls="imajiner-primary-menu" hidden', $header );
		self::assertStringContainsString( 'id="imajiner-primary-menu"', $header );
		self::assertStringContainsString( 'Child item', $header );
		self::assertStringContainsString( 'class="sub-menu"', $header );
		set_theme_mod( 'nav_menu_locations', array() );
		ob_start();
		include get_template_directory() . '/header.php';
		self::assertStringNotContainsString( 'data-imajiner-navigation', ob_get_clean() );
	}
}
