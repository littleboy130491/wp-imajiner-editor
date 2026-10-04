<?php
/** Isolated storage and transport-failure regressions; no external connections. */
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-imajiner-filesystem.php';
require_once dirname( __DIR__ ) . '/includes/class-imajiner-filesystem-credentials.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

/** Fault-injected WP transport, backed by disposable files, not an FTP server. */
class Imajiner_Storage_Test_Transport extends Imajiner_Atomic_Direct_Filesystem {
	public $root;
	public $fail;
	public $link_name;
	public $link_type = 'l';
	public $corrupt_move;
	public $moves = array();
	public function wp_content_dir() { return trailingslashit( $this->root ); }
	private function failing( $operation, $path ) {
		return $this->fail && call_user_func( $this->fail, $operation, $path );
	}
	public function put_contents( $file, $contents, $mode = false ) {
		return $this->failing( 'write', $file ) ? false : parent::put_contents( $file, $contents, $mode );
	}
	public function move( $source, $destination, $overwrite = false ) {
		$this->moves[] = array( $source, $destination, $overwrite );
		$result = $this->failing( 'move', $destination ) ? false : parent::move( $source, $destination, $overwrite );
		if ( $result && $this->corrupt_move === $destination ) {
			$this->corrupt_move = null;
			parent::put_contents( $destination, 'corrupt disposable transfer' );
		}
		return $result;
	}
	public function delete( $file, $recursive = false, $type = false ) {
		return $this->failing( 'delete', $file ) ? false : parent::delete( $file, $recursive, $type );
	}
	public function dirlist( $path, $include_hidden = true, $recursive = false ) {
		if ( $this->failing( 'list', $path ) ) { return false; }
		$list = parent::dirlist( $path, $include_hidden, $recursive );
		if ( $this->link_name && is_array( $list ) && isset( $list[ $this->link_name ] ) ) {
			$list[ $this->link_name ]['type'] = $this->link_type;
			$list[ $this->link_name ]['islink'] = true;
		}
		return $list;
	}
}

final class StorageSecurityTest extends TestCase {
	private static $admin;
	private static $editor;
	private $path;
	private $files;
	private $cleanup = array();
	private $directories = array();
	private $preferences;
	private $ai_preferences;

	public static function setUpBeforeClass(): void {
		$suffix = wp_generate_uuid4();
		self::$admin = wp_insert_user( array( 'user_login' => 'storage-admin-' . $suffix, 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
		self::$editor = wp_insert_user( array( 'user_login' => 'storage-editor-' . $suffix, 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
	}
	public static function tearDownAfterClass(): void {
		wp_delete_user( self::$admin );
		wp_delete_user( self::$editor );
	}
	protected function setUp(): void {
		wp_set_current_user( self::$admin );
		$this->reset_client();
		$this->preferences = get_option( Imajiner_Filesystem_Credentials::CLEANUP_OPTION, null );
		$this->ai_preferences = get_option( 'imajiner_editor_ai', null );
		$this->path = get_stylesheet_directory() . '/imajiner/storage-' . strtolower( wp_generate_password( 12, false ) ) . '.php';
		$this->files = array( 'php' => "<?php\n/** Template Name: Disposable storage fixture */\n?>\n<section>Isolated fixture</section>", 'css' => '.fixture { color: var(--imj-color-primary); }' );
		$this->track_pair( $this->path );
		self::assertTrue( Imajiner_Template_Store::create( $this->path, $this->files ) );
	}
	protected function tearDown(): void {
		remove_filter( 'filesystem_method', array( $this, 'remote_method' ) );
		$this->reset_client();
		foreach ( $this->cleanup as $file ) {
			if ( file_exists( $file ) || is_link( $file ) ) {
				if ( ! is_link( $file ) ) { @chmod( $file, 0644 ); }
				unlink( $file );
			}
		}
		foreach ( $this->directories as $directory ) {
			$fs = new WP_Filesystem_Direct( null );
			$fs->delete( $directory, true );
		}
		foreach ( get_posts( array( 'post_type' => Imajiner_Template_Store::REVISION_POST_TYPE, 'post_status' => 'any', 'author' => self::$admin, 'numberposts' => -1 ) ) as $post ) {
			wp_delete_post( $post->ID, true );
		}
		Imajiner_Filesystem_Credentials::forget( self::$admin );
		if ( null === $this->preferences ) {
			delete_option( Imajiner_Filesystem_Credentials::CLEANUP_OPTION );
		} else {
			update_option( Imajiner_Filesystem_Credentials::CLEANUP_OPTION, $this->preferences, false );
		}
		if ( null === $this->ai_preferences ) { delete_option( 'imajiner_editor_ai' ); } else { update_option( 'imajiner_editor_ai', $this->ai_preferences, false ); }
		wp_clear_scheduled_hook( 'imajiner_fs_expire', array( self::$admin ) );
		wp_set_current_user( 0 );
	}
	private function track_pair( $path ) {
		$this->cleanup[] = $path;
		$this->cleanup[] = Imajiner_Template_Store::css_path( $path );
	}
	private function reset_client( $client = null ) {
		foreach ( array( 'client' => $client, 'context' => get_current_user_id() . '|' . get_stylesheet() . '|' . wp_get_session_token() ) as $name => $value ) {
			$property = new ReflectionProperty( Imajiner_Filesystem::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}
	private function transport( $remote = false ) {
		$fs = new Imajiner_Storage_Test_Transport( null );
		if ( $remote ) {
			$uploads = wp_upload_dir();
			$directory = $uploads['basedir'] . '/storage-transport-' . wp_generate_uuid4();
			$this->directories[] = $directory;
			$fs->root = $directory . '/content';
			wp_mkdir_p( $fs->root . '/themes/' . get_stylesheet() . '/imajiner/css' );
			$fs->method = 'ftpext';
		} else {
			$fs->root = WP_CONTENT_DIR;
		}
		$this->reset_client( $fs );
		return $fs;
	}

	public function test_pair_revisions_hash_syntax_and_permissions(): void {
		chmod( $this->path, 0640 );
		$new = array( 'php' => str_replace( 'Isolated fixture', 'Changed fixture', $this->files['php'] ), 'css' => '.fixture { color: blue; }' );
		self::assertTrue( Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->files ), $new, 'Disposable snapshot' ) );
		self::assertSame( $new, Imajiner_Template_Store::read( $this->path ) );
		self::assertSame( 0640, fileperms( $this->path ) & 0777 );
		$revisions = Imajiner_Template_Store::get_revisions( $this->path );
		self::assertCount( 1, $revisions );
		self::assertSame( $this->files, Imajiner_Template_Store::get_revision_files( $this->path, $revisions[0]['id'] ) );
		$stale = Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->files ), $this->files, 'stale' );
		self::assertSame( 409, $stale->get_error_data()['status'] );
		$invalid = $new;
		$invalid['php'] = '<?php if (';
		self::assertSame( 'imajiner_syntax_error', Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $new ), $invalid, 'invalid' )->get_error_code() );
		self::assertSame( $new, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_pair_failure_rolls_back_css_and_create_leaves_no_orphan(): void {
		$fs = $this->transport();
		$failed = false;
		$fs->fail = function ( $operation, $path ) use ( &$failed ) {
			if ( ! $failed && 'move' === $operation && $path === $this->path ) { $failed = true; return true; }
			return false;
		};
		$new = array( 'php' => $this->files['php'] . '\n', 'css' => '.fixture { color: green; }' );
		self::assertInstanceOf( WP_Error::class, Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->files ), $new, 'failure' ) );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		$new_path = str_replace( '.php', '-new.php', $this->path );
		$this->track_pair( $new_path );
		$fs->fail = function ( $operation, $path ) use ( $new_path ) { return 'move' === $operation && $path === $new_path; };
		self::assertInstanceOf( WP_Error::class, Imajiner_Template_Store::create( $new_path, $this->files ) );
		self::assertFileDoesNotExist( $new_path );
		self::assertFileDoesNotExist( Imajiner_Template_Store::css_path( $new_path ) );
		self::assertSame( array(), glob( dirname( $this->path ) . '/.imj-*' ) );
	}

	public function test_read_only_file_and_directory_refuse_changes(): void {
		chmod( $this->path, 0444 );
		$new = $this->files;
		$new['php'] .= '\n';
		self::assertSame( 'imajiner_not_writable', Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->files ), $new, 'read only' )->get_error_code() );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		chmod( $this->path, 0644 );
		$directory = get_stylesheet_directory() . '/imajiner/storage-dir-' . wp_generate_uuid4();
		$this->directories[] = $directory;
		mkdir( $directory, 0555 );
		self::assertInstanceOf( WP_Error::class, Imajiner_Filesystem::write( $directory . '/tokens.css', 'x' ) );
		chmod( $directory, 0755 );
	}

	public function test_traversal_parent_paths_and_symlink_escape_are_rejected(): void {
		$outside = ABSPATH . 'storage-outside-' . wp_generate_uuid4() . '.php';
		$this->cleanup[] = $outside;
		file_put_contents( $outside, '<?php /* disposable */' );
		$link = str_replace( '.php', '-link.php', $this->path );
		$this->cleanup[] = $link;
		symlink( $outside, $link );
		$paths = array( $link, dirname( $this->path ) . '/../style.php', get_template_directory() . '/imajiner/escape.php', $this->path . "\0", $this->path . '/../../escape.php' );
		foreach ( $paths as $path ) {
			self::assertInstanceOf( WP_Error::class, Imajiner_Template_Store::create( $path, $this->files ) );
			self::assertInstanceOf( WP_Error::class, Imajiner_Filesystem::delete( $path ) );
		}
		$directory = dirname( $this->path ) . '/storage-link-' . wp_generate_uuid4();
		$this->cleanup[] = $directory;
		symlink( dirname( $outside ), $directory );
		self::assertInstanceOf( WP_Error::class, Imajiner_Filesystem::write( $directory . '/escape.css', 'x' ) );
		self::assertSame( '<?php /* disposable */', file_get_contents( $outside ) );
	}

	public function test_remote_mapping_write_move_and_failure_restore(): void {
		$fs = $this->transport( true );
		$remote_path = $fs->root . substr( $this->path, strlen( WP_CONTENT_DIR ) );
		self::assertTrue( Imajiner_Filesystem::write( $this->path, 'remote initial' ) );
		self::assertSame( 'remote initial', file_get_contents( $remote_path ) );
		self::assertSame( $this->files['php'], file_get_contents( $this->path ) );
		$failed = false;
		$fs->fail = function ( $operation, $path ) use ( &$failed, $remote_path ) {
			if ( ! $failed && 'move' === $operation && $path === $remote_path ) { $failed = true; return true; }
			return false;
		};
		self::assertInstanceOf( WP_Error::class, Imajiner_Filesystem::write( $this->path, 'remote changed' ) );
		self::assertSame( 'remote initial', Imajiner_Filesystem::read( $this->path ) );
		$fs->fail = null;
		self::assertTrue( Imajiner_Filesystem::write( $this->path, 'remote changed' ) );
		self::assertSame( 'remote changed', Imajiner_Filesystem::read( $this->path ) );
		foreach ( $fs->moves as $move ) { self::assertFalse( $move[2], 'Remote moves must not use delete-first overwrite.' ); }
		self::assertSame( array(), glob( dirname( $remote_path ) . '/.imj-*' ) );
		$fs->link_name = basename( $remote_path );
		self::assertSame( 'imajiner_path', Imajiner_Filesystem::read( $this->path )->get_error_code() );
		$fs->link_type = 'f';
		self::assertSame( 'imajiner_path', Imajiner_Filesystem::read( $this->path )->get_error_code(), 'FTP sockets exposes islink separately from type.' );
	}

	public function test_remote_corrupt_transfer_restores_original_and_unknown_metadata_fails_closed(): void {
		$fs = $this->transport( true );
		$remote = $fs->root . substr( $this->path, strlen( WP_CONTENT_DIR ) );
		self::assertTrue( Imajiner_Filesystem::write( $this->path, 'original disposable transfer' ) );
		$fs->corrupt_move = $remote;
		self::assertSame( 'imajiner_write_failed', Imajiner_Filesystem::write( $this->path, 'new disposable transfer' )->get_error_code() );
		self::assertSame( 'original disposable transfer', Imajiner_Filesystem::read( $this->path ) );
		self::assertSame( array(), glob( dirname( $remote ) . '/.imj-*' ) );
		$fs->fail = function ( $operation ) { return 'list' === $operation; };
		self::assertInstanceOf( WP_Error::class, Imajiner_Filesystem::exists_checked( $this->path ) );
		$new_path = str_replace( '.php', '-blocked.php', $this->path );
		self::assertInstanceOf( WP_Error::class, Imajiner_Template_Store::create( $new_path, $this->files ) );
		self::assertSame( 'original disposable transfer', file_get_contents( $remote ) );
		$fs->fail = null;
		$fs->method = 'ssh2';
		self::assertSame( 'imajiner_remote_link_check', Imajiner_Filesystem::read( $this->path )->get_error_code(), 'SSH must fail without no-follow SFTP metadata.' );
	}

	public function remote_method() { return 'ftpext'; }
	public function test_missing_remote_credentials_has_actionable_error_without_direct_fallback(): void {
		$this->reset_client();
		add_filter( 'filesystem_method', array( $this, 'remote_method' ) );
		$result = Imajiner_Template_Store::read( $this->path );
		self::assertSame( 'imajiner_filesystem_credentials', $result->get_error_code() );
		self::assertSame( 503, $result->get_error_data()['status'] );
		self::assertStringContainsString( 'page=imajiner-filesystem', $result->get_error_data()['settings_url'] );
		self::assertSame( $this->files['php'], file_get_contents( $this->path ) );
	}

	public function test_rename_delete_collisions_stale_hashes_and_history(): void {
		$new = str_replace( '.php', '-renamed.php', $this->path );
		$this->track_pair( $new );
		$hash = Imajiner_Template_Store::hash( $this->files );
		self::assertSame( 409, Imajiner_Template_Store::delete( $this->path, str_repeat( '0', 32 ) )->get_error_data()['status'] );
		file_put_contents( Imajiner_Template_Store::css_path( $new ), 'collision' );
		self::assertSame( 'imajiner_exists', Imajiner_Template_Store::rename( $this->path, $new, $hash )->get_error_code() );
		unlink( Imajiner_Template_Store::css_path( $new ) );
		chmod( $this->path, 0640 );
		chmod( Imajiner_Template_Store::css_path( $this->path ), 0600 );
		self::assertTrue( Imajiner_Template_Store::rename( $this->path, $new, $hash ) );
		self::assertSame( 0640, fileperms( $new ) & 0777 );
		self::assertSame( 0600, fileperms( Imajiner_Template_Store::css_path( $new ) ) & 0777 );
		self::assertFileDoesNotExist( $this->path );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $new ) );
		self::assertCount( 1, Imajiner_Template_Store::get_revisions( $new ) );
		self::assertTrue( Imajiner_Template_Store::delete( $new, $hash ) );
		self::assertFileDoesNotExist( $new );
		self::assertFileDoesNotExist( Imajiner_Template_Store::css_path( $new ) );
		self::assertCount( 2, Imajiner_Template_Store::get_revisions( $new ) );
	}

	public function test_database_lock_prevents_concurrent_plugin_write(): void {
		$key = '_imajiner_store_lock_' . md5( $this->path );
		add_option( $key, array( 'token' => 'isolated-lock', 'expires' => time() + 300 ), '', false );
		try {
			$result = Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->files ), $this->files, 'lock' );
			self::assertSame( 423, $result->get_error_data()['status'] );
		} finally { delete_option( $key ); }
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_failed_rename_restores_bytes_and_permissions_and_failed_delete_restores_pair(): void {
		$new = str_replace( '.php', '-failed.php', $this->path );
		$this->track_pair( $new );
		chmod( Imajiner_Template_Store::css_path( $this->path ), 0600 );
		$fs = $this->transport();
		$fs->fail = function ( $operation, $path ) use ( $new ) { return 'move' === $operation && $path === $new; };
		self::assertInstanceOf( WP_Error::class, Imajiner_Template_Store::rename( $this->path, $new, Imajiner_Template_Store::hash( $this->files ) ) );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		self::assertSame( 0600, fileperms( Imajiner_Template_Store::css_path( $this->path ) ) & 0777 );
		self::assertFileDoesNotExist( $new );
		self::assertFileDoesNotExist( Imajiner_Template_Store::css_path( $new ) );
		$css = Imajiner_Template_Store::css_path( $this->path );
		chmod( $this->path, 0600 );
		$fs->fail = function ( $operation, $path ) use ( $css ) { return 'delete' === $operation && $path === $css; };
		self::assertInstanceOf( WP_Error::class, Imajiner_Template_Store::delete( $this->path, Imajiner_Template_Store::hash( $this->files ) ) );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		self::assertSame( 0600, fileperms( $this->path ) & 0777 );
	}

	public function test_fixed_design_token_target_uses_stale_hash_and_rejects_php(): void {
		$path = get_stylesheet_directory() . '/imajiner/design-tokens.css';
		$original = is_file( $path ) ? file_get_contents( $path ) : null;
		$mode = null === $original ? null : fileperms( $path ) & 0777;
		try {
			$css = ':root { --imj-storage-fixture: 1rem; }';
			self::assertTrue( Imajiner_Template_Store::write_design_tokens( $css, hash( 'sha256', $original ?: '' ) ) );
			self::assertSame( $css, file_get_contents( $path ) );
			self::assertSame( 409, Imajiner_Template_Store::write_design_tokens( ':root {}', str_repeat( '0', 64 ) )->get_error_data()['status'] );
			self::assertSame( 'imajiner_tokens', Imajiner_Template_Store::write_design_tokens( '<?php echo 1;', hash( 'sha256', $css ) )->get_error_code() );
			self::assertSame( $css, file_get_contents( $path ) );
		} finally {
			if ( null === $original ) { @unlink( $path ); } else { file_put_contents( $path, $original ); chmod( $path, $mode ); }
		}
	}

	public function test_cleanup_preference_requires_both_administrator_and_valid_nonce(): void {
		$post = $_POST;
		$request = $_REQUEST;
		$method = $_SERVER['REQUEST_METHOD'];
		$handler = static function () {
			return static function ( $message, $title, $args ) { throw new RuntimeException( 'Blocked request', isset( $args['response'] ) ? $args['response'] : 403 ); };
		};
		add_filter( 'wp_die_handler', $handler );
		$editor = get_user_by( 'id', self::$editor );
		try {
			delete_option( Imajiner_Filesystem_Credentials::CLEANUP_OPTION );
			$_SERVER['REQUEST_METHOD'] = 'POST';
			foreach ( array( 'editor', 'theme-editor', 'invalid-nonce' ) as $scenario ) {
				wp_set_current_user( 'invalid-nonce' === $scenario ? self::$admin : self::$editor );
				if ( 'theme-editor' === $scenario ) { $editor->add_cap( 'edit_themes' ); }
				$_POST = array( 'imajiner_cleanup' => '1', 'remove_data' => '1', '_wpnonce' => 'invalid-nonce' === $scenario ? 'invalid' : wp_create_nonce( 'imajiner_cleanup' ) );
				$_REQUEST = $_POST;
				try { Imajiner_Filesystem_Credentials::screen(); self::fail( 'The settings update must be blocked.' ); }
				catch ( RuntimeException $error ) { self::assertSame( 403, $error->getCode() ); }
				self::assertFalse( (bool) get_option( Imajiner_Filesystem_Credentials::CLEANUP_OPTION, false ) );
			}
			wp_set_current_user( self::$admin );
			$_POST['_wpnonce'] = wp_create_nonce( 'imajiner_cleanup' );
			$_REQUEST = $_POST;
			ob_start();
			try { Imajiner_Filesystem_Credentials::screen(); } finally { $html = ob_get_clean(); }
			self::assertTrue( (bool) get_option( Imajiner_Filesystem_Credentials::CLEANUP_OPTION ) );
			self::assertStringContainsString( 'Cleanup preference saved.', $html );
		} finally {
			$editor->remove_cap( 'edit_themes' );
			remove_filter( 'wp_die_handler', $handler );
			$_POST = $post;
			$_REQUEST = $request;
			$_SERVER['REQUEST_METHOD'] = $method;
		}
	}

	public function test_credentials_are_encrypted_session_theme_user_and_expiry_bound(): void {
		$stored = array( 'cipher' => Imajiner_Secrets::encrypt( wp_json_encode( array( 'connection_type' => 'ssh', 'unknown_field' => 'isolated-value' ) ) ), 'expires' => time() + 30, 'theme' => get_stylesheet(), 'session' => hash( 'sha256', wp_get_session_token() ) );
		self::assertStringNotContainsString( 'connection_type', $stored['cipher'] );
		update_user_meta( self::$admin, Imajiner_Filesystem_Credentials::META, $stored );
		self::assertSame( array( 'connection_type' => 'ssh' ), Imajiner_Filesystem_Credentials::credentials() );
		wp_set_current_user( self::$editor );
		self::assertSame( array(), Imajiner_Filesystem_Credentials::credentials() );
		wp_set_current_user( self::$admin );
		foreach ( array( 'expires' => time() - 1, 'theme' => 'other-child', 'session' => hash( 'sha256', 'other-session' ) ) as $field => $value ) {
			$invalid = $stored;
			$invalid[ $field ] = $value;
			update_user_meta( self::$admin, Imajiner_Filesystem_Credentials::META, $invalid );
			self::assertSame( array(), Imajiner_Filesystem_Credentials::credentials() );
			self::assertSame( '', get_user_meta( self::$admin, Imajiner_Filesystem_Credentials::META, true ) );
		}
		update_user_meta( self::$admin, Imajiner_Filesystem_Credentials::META, array( 'expires' => 'malformed' ) );
		self::assertSame( array(), Imajiner_Filesystem_Credentials::credentials() );
		update_user_meta( self::$admin, Imajiner_Filesystem_Credentials::META, $stored );
		Imajiner_Filesystem_Credentials::expire( self::$admin );
		self::assertSame( $stored, get_user_meta( self::$admin, Imajiner_Filesystem_Credentials::META, true ), 'A renewed login credential must survive an earlier expiry event.' );
		$stored['expires'] = time() - 1;
		update_user_meta( self::$admin, Imajiner_Filesystem_Credentials::META, $stored );
		Imajiner_Filesystem_Credentials::expire( self::$admin );
		self::assertSame( '', get_user_meta( self::$admin, Imajiner_Filesystem_Credentials::META, true ) );
	}

	private function subprocess( $source ) {
		$prefix = "define('DISABLE_WP_CRON',true); require " . var_export( ABSPATH . 'wp-load.php', true ) . "; require_once " . var_export( dirname( __DIR__ ) . '/includes/class-imajiner-filesystem.php', true ) . ';';
		$output = array();
		exec( escapeshellarg( PHP_BINARY ) . ' -d mysqli.default_socket=/var/run/mysqld/mysqld.sock -r ' . escapeshellarg( $prefix . $source ), $output, $status );
		self::assertSame( 0, $status );
		return implode( "\n", $output );
	}
	public function test_disallow_file_edit_blocks_store_and_rest_capability(): void {
		$source = 'define("DISALLOW_FILE_EDIT",true); wp_set_current_user(' . self::$admin . '); $result=Imajiner_Template_Store::delete(' . var_export( $this->path, true ) . ',' . var_export( Imajiner_Template_Store::hash( $this->files ), true ) . '); echo $result->get_error_code()."|".(Imajiner_Rest::can_edit()?"allowed":"denied");';
		self::assertSame( 'imajiner_file_edit_disabled|denied', $this->subprocess( $source ) );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_uninstall_defaults_keep_and_opt_in_removes_only_owned_cache_and_revisions(): void {
		$new = $this->files;
		$new['css'] .= '\n';
		self::assertTrue( Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->files ), $new, 'uninstall fixture' ) );
		$revision = Imajiner_Template_Store::get_revisions( $this->path )[0]['id'];
		$uploads = wp_upload_dir();
		$dir = $uploads['basedir'] . '/imajiner/preview';
		self::assertTrue( Imajiner_Filesystem::mkdir( $dir ) );
		$cache = $dir . '/' . substr( md5( $this->path ), 0, 8 ) . '-storage-' . md5( 'isolated' ) . '.php';
		$unknown = $dir . '/storage-unknown-' . wp_generate_uuid4() . '.php';
		$this->cleanup[] = $cache;
		$this->cleanup[] = $unknown;
		$link = $dir . '/abcdef01-storage-link-' . md5( wp_generate_uuid4() ) . '.php';
		$this->cleanup[] = $link;
		symlink( $this->path, $link );
		$guards = array();
		foreach ( array( 'index.php' => "<?php defined('ABSPATH') || exit;", '.htaccess' => 'Deny from all' ) as $name => $source ) {
			$file = $dir . '/' . $name;
			if ( ! is_file( $file ) ) { self::assertTrue( Imajiner_Filesystem::write( $file, $source ) ); $this->cleanup[] = $file; }
			$guards[ $file ] = file_get_contents( $file );
		}
		self::assertTrue( Imajiner_Filesystem::write( $cache, "<?php defined('ABSPATH') || exit;" ) );
		self::assertTrue( Imajiner_Filesystem::write( $unknown, "<?php /* unknown fixture */" ) );
		$uninstall = 'define("WP_UNINSTALL_PLUGIN",true); require ' . var_export( dirname( __DIR__ ) . '/uninstall.php', true ) . '; echo "uninstalled";';
		delete_option( Imajiner_Filesystem_Credentials::CLEANUP_OPTION );
		update_user_meta( self::$admin, Imajiner_Filesystem_Credentials::META, array( 'cipher' => Imajiner_Secrets::encrypt( '{"connection_type":"ssh"}' ), 'expires' => time() + 300, 'theme' => get_stylesheet(), 'session' => hash( 'sha256', wp_get_session_token() ) ) );
		wp_schedule_single_event( time() + 600, 'imajiner_fs_expire', array( self::$admin ) );
		self::assertSame( 'uninstalled', $this->subprocess( $uninstall ) );
		wp_cache_delete( self::$admin, 'user_meta' );
		wp_cache_delete( 'cron', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		self::assertSame( '', get_user_meta( self::$admin, Imajiner_Filesystem_Credentials::META, true ) );
		self::assertFalse( wp_next_scheduled( 'imajiner_fs_expire', array( self::$admin ) ) );
		self::assertFileExists( $cache );
		self::assertNotNull( get_post( $revision ) );
		update_option( Imajiner_Filesystem_Credentials::CLEANUP_OPTION, true, false );
		self::assertSame( 'uninstalled', $this->subprocess( $uninstall ) );
		clean_post_cache( $revision );
		self::assertNull( get_post( $revision ) );
		self::assertFileDoesNotExist( $cache );
		self::assertFileExists( $unknown );
		self::assertTrue( is_link( $link ) );
		foreach ( $guards as $file => $source ) { self::assertSame( $source, file_get_contents( $file ) ); }
		self::assertSame( $new, Imajiner_Template_Store::read( $this->path ) );
	}
}
