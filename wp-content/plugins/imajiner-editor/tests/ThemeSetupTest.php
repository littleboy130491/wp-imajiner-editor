<?php
/** Disposable WP integration tests for the theme and isolated client setup unit. */
use PHPUnit\Framework\TestCase;

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once dirname( __DIR__ ) . '/includes/class-imajiner-site-setup.php';
$_SERVER['SERVER_NAME'] = 'localhost';

/** The agreed filesystem contract, backed by real WP_Filesystem for isolated-unit runs. */
class Imajiner_Theme_Setup_Test_Filesystem {
	public static $driver;
	public static $fail_write = false;
	public static function init() {
		if ( ! self::$driver ) {
			self::$driver = new WP_Filesystem_Direct( null );
		}
		return true;
	}
	public static function read( $path ) {
		$content = self::$driver->get_contents( $path );
		return false === $content ? new WP_Error( 'test_read', 'Scaffold read failed.' ) : $content;
	}
	public static function write( $path, $content ) {
		if ( self::$fail_write && 'theme.json' === basename( $path ) ) {
			return new WP_Error( 'test_write', 'Injected write failure.' );
		}
		return self::$driver->put_contents( $path, $content, 0644 ) ? true : new WP_Error( 'test_write', 'Write failed.' );
	}
	public static function mkdir( $path ) {
		return self::$driver->mkdir( $path, 0755 ) ? true : new WP_Error( 'test_mkdir', 'Mkdir failed.' );
	}
	public static function exists( $path ) { return self::$driver->exists( $path ); }
	public static function delete( $path ) {
		return ! self::exists( $path ) || self::$driver->delete( $path, false ) ? true : new WP_Error( 'test_delete', 'Delete failed.' );
	}
	public static function move( $from, $to, $overwrite = false ) {
		return self::$driver->move( $from, $to, $overwrite ) ? true : new WP_Error( 'test_move', 'Move failed.' );
	}
}
if ( ! class_exists( 'Imajiner_Filesystem' ) ) {
	class_alias( 'Imajiner_Theme_Setup_Test_Filesystem', 'Imajiner_Filesystem' );
}

final class ThemeSetupTest extends TestCase {
	private $admin;
	private $subscriber;
	private $original_theme;
	private $original_user;
	private $slugs = array();
	private $reference_hash;

	protected function setUp(): void {
		$this->original_theme = get_stylesheet();
		$this->original_user  = get_current_user_id();
		$suffix = strtolower( wp_generate_password( 10, false, false ) );
		$this->admin = wp_insert_user( array( 'user_login' => 'theme-admin-' . $suffix, 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
		$this->subscriber = wp_insert_user( array( 'user_login' => 'theme-reader-' . $suffix, 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		wp_set_current_user( $this->admin );
		$this->reference_hash = hash_file( 'sha256', get_theme_root() . '/imajiner-child/imajiner/page-example.php' );
		Imajiner_Theme_Setup_Test_Filesystem::init();
	}

	protected function tearDown(): void {
		Imajiner_Theme_Setup_Test_Filesystem::$fail_write = false;
		wp_set_current_user( $this->admin );
		if ( get_stylesheet() !== $this->original_theme ) {
			switch_theme( $this->original_theme );
		}
		$driver = new WP_Filesystem_Direct( null );
		foreach ( $this->slugs as $slug ) {
			$driver->delete( get_theme_root() . '/' . $slug, true );
		}
		wp_clean_themes_cache();
		wp_delete_user( $this->subscriber );
		wp_delete_user( $this->admin );
		wp_set_current_user( $this->original_user );
		$this->assertSame( $this->reference_hash, hash_file( 'sha256', get_theme_root() . '/imajiner-child/imajiner/page-example.php' ) );
	}

	private function slug() {
		$slug = 'imj-theme-test-' . strtolower( wp_generate_password( 12, false, false ) );
		$this->slugs[] = $slug;
		return $slug;
	}

	public function testPortableSlugsRefuseTraversalReservedNamesAndSilentRewrites(): void {
		foreach ( array( '../client', '/client', 'Client', 'client--site', 'client_', '1client', 'con', 'nul', 'com1', 'lpt9', 'imajiner', 'imajiner-child', 'client.', 'client ', str_repeat( 'a', 65 ), array( 'client' ) ) as $slug ) {
			$this->assertInstanceOf( WP_Error::class, Imajiner_Site_Setup::validate_slug( $slug ) );
		}
		$this->assertTrue( Imajiner_Site_Setup::validate_slug( 'client-site-2' ) );
	}

	public function testCreationNeedsPermissionAndRejectsNonStringNames(): void {
		$slug = $this->slug();
		wp_set_current_user( $this->subscriber );
		$this->assertSame( 'imajiner_setup_forbidden', Imajiner_Site_Setup::create( $slug, 'Client' )->get_error_code() );
		$this->assertDirectoryDoesNotExist( get_theme_root() . '/' . $slug );
		wp_set_current_user( $this->admin );
		$this->assertSame( 'imajiner_setup_name', Imajiner_Site_Setup::create( $slug, array() )->get_error_code() );
		$this->assertSame( 'imajiner_setup_name', Imajiner_Site_Setup::create( $slug, '' )->get_error_code() );
	}

	public function testWordPressFileModificationPolicyBlocksSetupAndActivation(): void {
		$slug = $this->slug();
		$deny = function () { return false; };
		add_filter( 'file_mod_allowed', $deny );
		try {
			$this->assertSame( 'imajiner_setup_forbidden', Imajiner_Site_Setup::create( $slug, 'Client' )->get_error_code() );
			$this->assertSame( 'imajiner_setup_activation', Imajiner_Site_Setup::activate( $slug, true )->get_error_code() );
			$this->assertDirectoryDoesNotExist( get_theme_root() . '/' . $slug );
			$this->assertSame( $this->original_theme, get_stylesheet() );
		} finally {
			remove_filter( 'file_mod_allowed', $deny );
		}
	}

	public function testLocatedCustomHeaderBypassesDefaultNavigationInFreshWordPressRequest(): void {
		$slug = $this->slug();
		$this->assertIsArray( Imajiner_Site_Setup::create( $slug, 'Header Client' ) );
		$this->assertTrue( Imajiner_Site_Setup::activate( $slug, true ) );
		file_put_contents( get_stylesheet_directory() . '/imajiner/parts/test-header.php', "<?php\n/*\nPart Name: Disposable header\nPart Location: header\n*/\ndefined('ABSPATH') || exit;\n?><header id=\"test-custom-header\">Disposable custom header</header>" );
		$code = 'define("DISABLE_WP_CRON", true); $_SERVER["HTTP_HOST"] = "localhost"; $_SERVER["SERVER_NAME"] = "localhost"; $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REQUEST_URI"] = "/"; require ' . var_export( ABSPATH . 'wp-load.php', true ) . '; ob_start(); get_header(); $html = ob_get_clean(); if (false === strpos($html, "test-custom-header") || false !== strpos($html, "imajiner-primary-navigation") || false !== strpos($html, "class=\\"site-header\\"")) { fwrite(STDERR, "Custom header replacement failed"); exit(1); } echo "custom-header-preserved";';
		$process = proc_open( array( PHP_BINARY, '-d', 'mysqli.default_socket=' . ini_get( 'mysqli.default_socket' ), '-r', $code ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $stderr );
		$this->assertSame( 'custom-header-preserved', $stdout );
	}

	public function testCreatesDistinctCleanChildPackagesWithoutSwitchingOrCopyingSiteContent(): void {
		$first = $this->slug();
		$second = $this->slug();
		foreach ( array( $first, $second ) as $slug ) {
			$created = Imajiner_Site_Setup::create( $slug, 'Client */ Safe' );
			$this->assertIsArray( $created );
			$this->assertSame( $this->original_theme, get_stylesheet() );
			$theme = wp_get_theme( $slug );
			$this->assertFalse( $theme->errors() );
			$this->assertSame( 'imajiner', $theme->get_template() );
			$this->assertSame( 'Client  Safe', $theme->get( 'Name' ) );
			$root = $created['path'];
			foreach ( array( 'assets/css', 'imajiner/css', 'imajiner/parts/css' ) as $directory ) {
				$this->assertDirectoryExists( $root . '/' . $directory );
			}
			$files = array();
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				$files[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
			sort( $files );
			$this->assertSame( array( 'README.md', 'editor.css', 'functions.php', 'screenshot.png', 'style.css', 'theme.json' ), $files );
			$this->assertFileDoesNotExist( $root . '/imajiner/page-example.php' );
			$this->assertFileDoesNotExist( $root . '/assets/css/design-tokens.css' );
			$this->assertFileDoesNotExist( $root . '/.git' );
			token_get_all( file_get_contents( $root . '/functions.php' ), TOKEN_PARSE );
		}
	}

	public function testExistingThemeAndEvenNonThemeDirectoriesAreNeverOverwritten(): void {
		$slug = $this->slug();
		$result = Imajiner_Site_Setup::create( $slug, 'Original' );
		$this->assertIsArray( $result );
		$style = file_get_contents( $result['path'] . '/style.css' );
		$this->assertSame( 'imajiner_setup_exists', Imajiner_Site_Setup::create( $slug, 'Replacement' )->get_error_code() );
		$this->assertSame( $style, file_get_contents( $result['path'] . '/style.css' ) );
		$other = $this->slug();
		mkdir( get_theme_root() . '/' . $other );
		file_put_contents( get_theme_root() . '/' . $other . '/keep.txt', 'Keep this file' );
		$this->assertSame( 'imajiner_setup_exists', Imajiner_Site_Setup::create( $other, 'Replacement' )->get_error_code() );
		$this->assertSame( 'Keep this file', file_get_contents( get_theme_root() . '/' . $other . '/keep.txt' ) );
	}

	public function testConcurrentSetupLockRefusesCreationAndFailureCleansUpForRetry(): void {
		$slug = $this->slug();
		$path = get_theme_root() . '/' . $slug;
		$lock = 'imajiner_child_setup_' . md5( $path );
		add_option( $lock, time(), '', false );
		try {
			$this->assertSame( 'imajiner_setup_busy', Imajiner_Site_Setup::create( $slug, 'Client' )->get_error_code() );
			$this->assertDirectoryDoesNotExist( $path );
		} finally {
			delete_option( $lock );
		}
		if ( is_a( 'Imajiner_Filesystem', 'Imajiner_Theme_Setup_Test_Filesystem', true ) ) {
			Imajiner_Theme_Setup_Test_Filesystem::$fail_write = true;
			$this->assertSame( 'test_write', Imajiner_Site_Setup::create( $slug, 'Client' )->get_error_code() );
			$this->assertDirectoryDoesNotExist( $path );
			$this->assertFalse( get_option( $lock ) );
			Imajiner_Theme_Setup_Test_Filesystem::$fail_write = false;
		}
		$this->assertIsArray( Imajiner_Site_Setup::create( $slug, 'Client' ) );
	}

	public function testActivationRequiresSeparateConfirmationAndSwitchCapability(): void {
		$slug = $this->slug();
		$this->assertIsArray( Imajiner_Site_Setup::create( $slug, 'Client' ) );
		$this->assertInstanceOf( WP_Error::class, Imajiner_Site_Setup::activate( $slug ) );
		$this->assertSame( $this->original_theme, get_stylesheet() );
		wp_set_current_user( $this->subscriber );
		$this->assertInstanceOf( WP_Error::class, Imajiner_Site_Setup::activate( $slug, true ) );
		wp_set_current_user( $this->admin );
		$this->assertInstanceOf( WP_Error::class, Imajiner_Site_Setup::activate( 'imajiner', true ) );
		$this->assertTrue( Imajiner_Site_Setup::activate( $slug, true ) );
		$this->assertSame( $slug, get_stylesheet() );
	}

	public function testTokenSourcesAndFrontEndEditorCascadeUseActiveChildAndMtime(): void {
		$slug = $this->slug();
		$this->assertIsArray( Imajiner_Site_Setup::create( $slug, 'Token Client' ) );
		$this->assertTrue( Imajiner_Site_Setup::activate( $slug, true ) );
		$path = get_theme_root() . '/' . $slug . '/assets/css/design-tokens.css';
		file_put_contents( $path, ':root { --imj-color-primary: #123456; --imj-space-3: 1.125rem; }' );
		$this->assertSame( $path, imajiner_design_tokens_file() );
		$this->assertSame( array( get_template_directory() . '/assets/css/base.css', get_stylesheet_directory() . '/style.css', $path ), imajiner_design_token_sources() );
		$extra = function ( $sources ) { $sources[] = '/reader-only.css'; return $sources; };
		add_filter( 'imajiner_design_token_sources', $extra );
		$this->assertContains( '/reader-only.css', imajiner_design_token_sources() );
		remove_filter( 'imajiner_design_token_sources', $extra );
		$old_styles = $GLOBALS['wp_styles'];
		$old_screen = isset( $GLOBALS['current_screen'] ) ? $GLOBALS['current_screen'] : null;
		try {
			$GLOBALS['wp_styles'] = new WP_Styles();
			imajiner_enqueue_assets();
			$tokens = wp_styles()->registered['imajiner-design-tokens'];
			$this->assertSame( array( 'imajiner-child' ), $tokens->deps );
			$this->assertSame( (string) filemtime( $path ), $tokens->ver );
			$this->assertStringContainsString( '/' . $slug . '/assets/css/design-tokens.css', $tokens->src );
			$this->assertSame( array( 'wp-i18n' ), wp_scripts()->registered['imajiner-navigation']->deps );
			set_current_screen( 'post' );
			imajiner_enqueue_editor_design_system();
			$this->assertSame( array( 'imajiner-design-tokens' ), wp_styles()->registered['imajiner-editor-content']->deps );
			$this->assertSame( array( 'imajiner-editor-content' ), wp_styles()->registered['imajiner-child-editor']->deps );
		} finally {
			$GLOBALS['wp_styles'] = $old_styles;
			$GLOBALS['current_screen'] = $old_screen;
		}
	}

	public function testThemeJsonPresetsInheritTokenValuesAndScreenshotsAreRealPngs(): void {
		$parent = json_decode( file_get_contents( get_template_directory() . '/theme.json' ), true );
		$child = json_decode( file_get_contents( get_stylesheet_directory() . '/theme.json' ), true );
		$this->assertSame( 3, $parent['version'] );
		$this->assertSame( 3, $child['version'] );
		foreach ( $parent['settings']['color']['palette'] as $color ) {
			$this->assertStringStartsWith( 'var(--imj-', $color['color'] );
		}
		foreach ( $parent['settings']['spacing']['spacingSizes'] as $space ) {
			$this->assertStringStartsWith( 'var(--imj-space-', $space['size'] );
		}
		$merged = WP_Theme_JSON_Resolver::get_merged_data()->get_settings();
		$this->assertSame( 'var(--imj-color-primary)', $merged['color']['palette']['theme'][5]['color'] );
		$this->assertTrue( current_theme_supports( 'editor-styles' ) );
		foreach ( array( get_template_directory(), get_stylesheet_directory(), get_template_directory() . '/child-scaffold' ) as $directory ) {
			$image = getimagesize( $directory . '/screenshot.png' );
			$this->assertSame( IMAGETYPE_PNG, $image[2] );
			$this->assertSame( array( 1200, 900 ), array( $image[0], $image[1] ) );
		}
	}

	public function testDefaultHeaderIsProgressiveAndStillRespectsLocatedHeaderParts(): void {
		$menu = wp_create_nav_menu( 'Theme test ' . wp_generate_password( 8, false, false ) );
		$item = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Disposable link', 'menu-item-url' => home_url( '/disposable/' ), 'menu-item-status' => 'publish' ) );
		$old_locations = get_theme_mod( 'nav_menu_locations', array() );
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu ) );
		try {
			ob_start();
			include get_template_directory() . '/header.php';
			$html = ob_get_clean();
			$this->assertStringContainsString( 'aria-expanded="false" aria-controls="imajiner-primary-navigation" hidden', $html );
			$this->assertStringContainsString( 'id="imajiner-primary-navigation" class="site-nav" data-imajiner-navigation', $html );
			$this->assertStringContainsString( 'Disposable link', $html );
			$this->assertStringNotContainsString( 'class="site-nav" hidden', $html );
			$source = file_get_contents( get_template_directory() . '/header.php' );
			$this->assertStringContainsString( "if ( ! imajiner_render_location( 'header' ) )", $source );
		} finally {
			set_theme_mod( 'nav_menu_locations', $old_locations );
			wp_delete_nav_menu( $menu );
			wp_delete_post( $item, true );
		}
	}

	public function testInitWiresAppearanceAndSeparateNonceProtectedActions(): void {
		Imajiner_Site_Setup::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'Imajiner_Site_Setup', 'add_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_imajiner_create_child_theme', array( 'Imajiner_Site_Setup', 'handle_create' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_imajiner_activate_child_theme', array( 'Imajiner_Site_Setup', 'handle_activate' ) ) );
		ob_start();
		Imajiner_Site_Setup::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'Create child theme (without activating)', $html );
		$this->assertStringNotContainsString( 'name="confirm"', $html );
	}
}
