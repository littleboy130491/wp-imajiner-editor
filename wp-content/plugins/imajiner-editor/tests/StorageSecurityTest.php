<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-imajiner-filesystem.php';
require_once dirname( __DIR__ ) . '/includes/class-imajiner-filesystem-settings.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

if ( ! defined( 'IMAJINER_FILESYSTEM_TEMP_DIR' ) ) {
	define( 'IMAJINER_FILESYSTEM_TEMP_DIR', sys_get_temp_dir() );
}

/** Controlled WordPress transport; no remote service or credentials. */
class Imajiner_Storage_Test_Transport extends WP_Filesystem_Base {
	public $method = 'ftpext';
	public $root = '/remote/themes/child';
	public $files = array();
	public $directories = array( '/remote', '/remote/themes', '/remote/themes/child', '/remote/private' );
	public $modes = array();
	public $denied = array();
	public $links = array();
	public $operations = array();
	public $fail_target = '';
	public $fail_count = 0;
	public $erase_on_failure = false;
	public $short_write = false;
	public $fail_mapping = false;
	public $fail_listing = false;
	public $fail_chmod = false;
	public $fail_delete = '';
	public $fail_write_target = '';

	public function find_folder( $folder ) {
		if ( $this->fail_mapping ) {
			return false;
		}
		return trailingslashit( $folder === IMAJINER_FILESYSTEM_TEMP_DIR ? '/remote/private' : $this->root );
	}
	public function abspath() { return '/remote/public/'; }
	public function exists( $path ) { return isset( $this->files[ $path ] ) || $this->is_dir( $path ); }
	public function is_dir( $path ) { return in_array( untrailingslashit( $path ), $this->directories, true ); }
	public function is_file( $path ) { return isset( $this->files[ $path ] ); }
	public function is_writable( $path ) { return ! in_array( untrailingslashit( $path ), $this->denied, true ); }
	public function get_contents( $path ) { return $this->files[ $path ] ?? false; }
	public function getchmod( $path ) { return decoct( $this->modes[ $path ] ?? 0644 ); }
	public function chmod( $path, $mode = false, $recursive = false ) {
		$this->modes[ $path ] = $mode;
		return ! $this->fail_chmod;
	}
	public function mkdir( $path, $chmod = false, $chown = false, $chgrp = false ) {
		$this->directories[] = untrailingslashit( $path );
		return true;
	}
	public function put_contents( $path, $contents, $mode = false ) {
		$this->operations[] = array( 'write', $path );
		if ( $path === $this->fail_write_target ) {
			return false;
		}
		$this->files[ $path ] = $this->short_write ? substr( $contents, 0, 1 ) : $contents;
		$this->modes[ $path ] = $mode;
		return true;
	}
	public function move( $source, $destination, $overwrite = false ) {
		$this->operations[] = array( 'move', $source, $destination );
		if ( $destination === $this->fail_target && $this->fail_count > 0 ) {
			--$this->fail_count;
			if ( $this->erase_on_failure ) {
				unset( $this->files[ $destination ] );
			}
			return false;
		}
		if ( ! isset( $this->files[ $source ] ) || ( ! $overwrite && $this->exists( $destination ) ) ) {
			return false;
		}
		$this->files[ $destination ] = $this->files[ $source ];
		$this->modes[ $destination ] = $this->modes[ $source ];
		unset( $this->files[ $source ] );
		return true;
	}
	public function delete( $path, $recursive = false, $type = false ) {
		$this->operations[] = array( 'delete', $path );
		if ( $path === $this->fail_delete ) {
			return false;
		}
		unset( $this->files[ $path ] );
		return true;
	}
	public function dirlist( $path, $include_hidden = true, $recursive = false ) {
		if ( $this->fail_listing ) {
			return false;
		}
		$list = array();
		foreach ( array_merge( $this->directories, array_keys( $this->files ), array_keys( $this->links ) ) as $entry ) {
			if ( dirname( $entry ) === ( '/' === $path ? '/' : untrailingslashit( $path ) ) ) {
				$list[ basename( $entry ) ] = array( 'type' => $this->links[ $entry ] ?? ( $this->is_dir( $entry ) ? 'd' : 'f' ), 'permsn' => $this->getchmod( $entry ) );
			}
		}
		return $list;
	}
}

final class StorageSecurityTest extends TestCase {
	private $user;
	private $other;
	private $path;
	private $files;
	private $cleanup = array();
	private $retention;
	private $credential_keys = array();

	protected function setUp(): void {
		$suffix = substr( wp_generate_uuid4(), 0, 8 );
		$this->user = wp_insert_user( array( 'user_login' => 'imj-storage-' . $suffix, 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
		$this->other = wp_insert_user( array( 'user_login' => 'imj-storage-other-' . $suffix, 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		wp_set_current_user( $this->user );
		Imajiner_Filesystem::reset();
		$this->path = get_stylesheet_directory() . '/imajiner/imj-storage-' . $suffix . '.php';
		$this->files = array( 'php' => "<?php\n/** Template Name: Storage fixture */\nget_header(); ?>\n<!-- imj:section name=\"hero\" -->\n<section class=\"hero\"><h2>Fixture</h2></section>\n<!-- /imj:section -->\n<?php get_footer(); ?>", 'css' => '.imj-storage .hero { color: red; }' );
		$this->retention = get_option( Imajiner_Filesystem_Settings::RETENTION_OPTION, null );
		$this->track( $this->path );
		self::assertTrue( Imajiner_Template_Store::create( $this->path, $this->files ) );
	}

	private function track( $path ): void {
		$this->cleanup[] = $path;
		$this->cleanup[] = Imajiner_Template_Store::css_path( $path );
	}

	protected function tearDown(): void {
		wp_set_current_user( $this->user );
		Imajiner_Filesystem::reset();
		foreach ( array_reverse( $this->cleanup ) as $file ) {
			if ( ! is_link( $file ) ) {
				foreach ( Imajiner_Template_Store::get_revisions( $file ) as $revision ) {
					wp_delete_post( $revision['id'], true );
				}
			}
			if ( is_link( $file ) || is_file( $file ) ) {
				if ( ! is_link( $file ) ) {
					chmod( $file, 0644 );
				}
				unlink( $file );
			} elseif ( is_dir( $file ) ) {
				rmdir( $file );
			}
		}
		foreach ( $this->credential_keys as $key ) {
			delete_transient( $key );
		}
		if ( null === $this->retention ) {
			delete_option( Imajiner_Filesystem_Settings::RETENTION_OPTION );
		} else {
			update_option( Imajiner_Filesystem_Settings::RETENTION_OPTION, $this->retention, false );
		}
		wp_delete_user( $this->other );
		wp_delete_user( $this->user );
		wp_set_current_user( 0 );
	}

	private function remote(): Imajiner_Storage_Test_Transport {
		self::assertTrue( Imajiner_Filesystem::init() );
		$remote = new Imajiner_Storage_Test_Transport();
		$remote->directories[] = $remote->root . '/imajiner';
		$remote->directories[] = $remote->root . '/imajiner/css';
		$remote->files[ $this->mapped( $remote, $this->path ) ] = $this->files['php'];
		$remote->files[ $this->mapped( $remote, Imajiner_Template_Store::css_path( $this->path ) ) ] = $this->files['css'];
		$property = new ReflectionProperty( Imajiner_Filesystem::class, 'filesystem' );
		$property->setAccessible( true );
		$property->setValue( null, $remote );
		return $remote;
	}

	private function mapped( Imajiner_Storage_Test_Transport $remote, $path ): string {
		return $remote->root . substr( $path, strlen( get_stylesheet_directory() ) );
	}

	public function test_pair_save_preserves_permissions_and_private_revision_bytes(): void {
		chmod( $this->path, 0640 );
		$new = array( 'php' => str_replace( 'Fixture', 'Updated', $this->files['php'] ), 'css' => '.imj-storage .hero { color: blue; }' );
		self::assertTrue( Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->files ), $new, 'Storage update' ) );
		self::assertSame( $new, Imajiner_Template_Store::read( $this->path ) );
		clearstatcache( true, $this->path );
		self::assertSame( 0640, fileperms( $this->path ) & 0777 );
		$revisions = Imajiner_Template_Store::get_revisions( $this->path );
		self::assertCount( 1, $revisions );
		self::assertSame( 'private', get_post_status( $revisions[0]['id'] ) );
		self::assertSame( $this->files, Imajiner_Template_Store::get_revision_files( $this->path, $revisions[0]['id'] ) );
	}

	public function test_css_only_staleness_and_invalid_php_do_not_write_or_create_revisions(): void {
		file_put_contents( Imajiner_Template_Store::css_path( $this->path ), '/* external change */' );
		$result = Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->files ), $this->files, 'Stale' );
		self::assertSame( 'imajiner_conflict', $result->get_error_code() );
		$current = Imajiner_Template_Store::read( $this->path );
		$new = $current;
		$new['php'] = '<?php function broken( {';
		$result = Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $current ), $new, 'Invalid' );
		self::assertSame( 'imajiner_syntax_error', $result->get_error_code() );
		self::assertSame( $current, Imajiner_Template_Store::read( $this->path ) );
		self::assertSame( array(), Imajiner_Template_Store::get_revisions( $this->path ) );
	}

	public function test_subscriber_cannot_mutate_files_or_read_revision_source(): void {
		$new = $this->files;
		$new['css'] = '/* changed */';
		self::assertTrue( Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->files ), $new, 'Revision' ) );
		$id = Imajiner_Template_Store::get_revisions( $this->path )[0]['id'];
		wp_set_current_user( $this->other );
		foreach ( array( Imajiner_Filesystem::write( $this->path, '' ), Imajiner_Filesystem::delete( $this->path ), Imajiner_Filesystem::mkdir( dirname( $this->path ) . '/forbidden' ), Imajiner_Filesystem::move( $this->path, $this->path . '.new' ), Imajiner_Template_Store::delete( $this->path, Imajiner_Template_Store::hash( $new ) ), Imajiner_Template_Store::get_revision_files( $this->path, $id ) ) as $result ) {
			self::assertSame( 'imajiner_forbidden', $result->get_error_code() );
		}
		self::assertSame( array(), Imajiner_Template_Store::get_revisions( $this->path ) );
		self::assertSame( $new, Imajiner_Template_Store::read( $this->path ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disallow_file_edit_blocks_even_administrator_mutations(): void {
		define( 'DISALLOW_FILE_EDIT', true );
		foreach ( array( Imajiner_Filesystem::write( $this->path, '' ), Imajiner_Template_Store::write_css( Imajiner_Template_Store::css_path( $this->path ), md5( $this->files['css'] ), '' ), Imajiner_Template_Store::rename( $this->path, str_replace( '.php', '-disabled.php', $this->path ), Imajiner_Template_Store::hash( $this->files ) ), Imajiner_Filesystem_Settings::save_retention( true, wp_create_nonce( 'imajiner_file_settings' ) ) ) as $result ) {
			self::assertSame( 'imajiner_forbidden', $result->get_error_code() );
		}
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_traversal_parent_and_wrappers_are_rejected_before_file_access(): void {
		$paths = array( dirname( $this->path ) . '/../outside.php', get_template_directory() . '/imajiner/outside.php', get_stylesheet_directory() . '-sibling/imajiner/outside.php', 'php://filter/resource=' . $this->path, $this->path . "\0", dirname( $this->path ) . '/./outside.php', str_replace( '/', '\\', $this->path ) );
		foreach ( $paths as $path ) {
			self::assertSame( 'imajiner_invalid_path', Imajiner_Template_Store::create( $path, $this->files )->get_error_code() );
			self::assertSame( 'imajiner_invalid_path', Imajiner_Template_Store::rename( $this->path, $path, Imajiner_Template_Store::hash( $this->files ) )->get_error_code() );
			self::assertSame( 'imajiner_invalid_path', Imajiner_Filesystem::delete( $path )->get_error_code() );
		}
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_broken_file_symlink_and_css_directory_symlink_are_rejected(): void {
		$link = str_replace( '.php', '-link.php', $this->path );
		$this->track( $link );
		symlink( dirname( ABSPATH ) . '/nonexistent-' . wp_generate_uuid4(), $link );
		self::assertSame( 'imajiner_symlink', Imajiner_Template_Store::create( $link, $this->files )->get_error_code() );
		$css = Imajiner_Template_Store::css_path( $this->path );
		unlink( $css );
		symlink( $this->path, $css );
		self::assertSame( 'imajiner_symlink', Imajiner_Template_Store::read( $this->path )->get_error_code() );
		$directory = get_stylesheet_directory() . '/imajiner/imj-link-' . substr( wp_generate_uuid4(), 0, 8 );
		$this->cleanup[] = $directory;
		symlink( get_template_directory(), $directory );
		self::assertSame( 'imajiner_symlink', Imajiner_Filesystem::write( $directory . '/outside.css', '' )->get_error_code() );
		self::assertSame( $this->files['php'], file_get_contents( $this->path ) );
	}

	public function test_existing_orphan_css_is_not_overwritten_by_create(): void {
		$path = str_replace( '.php', '-orphan.php', $this->path );
		$this->track( $path );
		$css = Imajiner_Template_Store::css_path( $path );
		file_put_contents( $css, '/* orphan fixture */' );
		self::assertSame( 'imajiner_exists', Imajiner_Template_Store::create( $path, $this->files )->get_error_code() );
		self::assertSame( '/* orphan fixture */', file_get_contents( $css ) );
		self::assertFileDoesNotExist( $path );
	}

	public function test_non_writable_target_preserves_both_files(): void {
		chmod( $this->path, 0444 );
		$new = array( 'php' => $this->files['php'] . '\n', 'css' => '/* changed */' );
		$result = Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->files ), $new, 'Permission' );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_design_css_is_child_only_versioned_and_stale_checked(): void {
		$path = get_stylesheet_directory() . '/imj-tokens-' . substr( wp_generate_uuid4(), 0, 8 ) . '.css';
		$this->cleanup[] = $path;
		$css = ':root { --imj-color-primary: #123456; }';
		self::assertTrue( Imajiner_Template_Store::write_css( $path, md5( '' ), $css ) );
		$revision = Imajiner_Template_Store::get_revisions( $path )[0]['id'];
		self::assertSame( array( 'php' => '', 'css' => '' ), Imajiner_Template_Store::get_revision_files( $path, $revision ) );
		self::assertSame( 'imajiner_conflict', Imajiner_Template_Store::write_css( $path, md5( '' ), '' )->get_error_code() );
		self::assertSame( 'imajiner_invalid_path', Imajiner_Template_Store::write_css( get_template_directory() . '/style.css', md5( '' ), $css )->get_error_code() );
		self::assertSame( 'imajiner_invalid_path', Imajiner_Template_Store::write_css( $this->path, md5( '' ), $css )->get_error_code() );
		self::assertSame( $css, Imajiner_Filesystem::read( $path ) );
	}

	public function test_rename_and_delete_keep_snapshots_and_refuse_stale_hashes(): void {
		$new = str_replace( '.php', '-renamed.php', $this->path );
		$this->track( $new );
		self::assertSame( 'imajiner_conflict', Imajiner_Template_Store::rename( $this->path, $new, md5( '' ) )->get_error_code() );
		self::assertTrue( Imajiner_Template_Store::rename( $this->path, $new, Imajiner_Template_Store::hash( $this->files ) ) );
		self::assertFileDoesNotExist( $this->path );
		self::assertFileDoesNotExist( Imajiner_Template_Store::css_path( $this->path ) );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $new ) );
		self::assertCount( 1, Imajiner_Template_Store::get_revisions( $this->path ) );
		self::assertSame( 'imajiner_conflict', Imajiner_Template_Store::delete( $new, md5( '' ) )->get_error_code() );
		self::assertTrue( Imajiner_Template_Store::delete( $new, Imajiner_Template_Store::hash( $this->files ) ) );
		self::assertFileDoesNotExist( $new );
		$id = Imajiner_Template_Store::get_revisions( $new )[0]['id'];
		self::assertSame( $this->files, Imajiner_Template_Store::get_revision_files( $new, $id ) );
	}

	public function force_ftp( $method ) { return 'ftpext'; }
	public function test_missing_remote_credentials_returns_settings_link_and_never_falls_back(): void {
		Imajiner_Filesystem::reset();
		add_filter( 'filesystem_method', array( $this, 'force_ftp' ) );
		try {
			$result = Imajiner_Filesystem::write( $this->path, '' );
			self::assertSame( 'imajiner_filesystem_credentials', $result->get_error_code() );
			self::assertSame( 503, $result->get_error_data()['status'] );
			self::assertStringContainsString( 'page=imajiner-file-access', $result->get_error_data()['settings_url'] );
			self::assertSame( $this->files['php'], file_get_contents( $this->path ) );
		} finally {
			remove_filter( 'filesystem_method', array( $this, 'force_ftp' ) );
		}
	}

	public function test_remote_mapping_and_symlinks_fail_without_mutation(): void {
		$remote = $this->remote();
		$remote->fail_mapping = true;
		self::assertSame( 'imajiner_filesystem_mapping', Imajiner_Template_Store::read( $this->path )->get_error_code() );
		$remote->fail_mapping = false;
		$remote->links[ $remote->root . '/imajiner/css' ] = 'l';
		self::assertSame( 'imajiner_symlink', Imajiner_Template_Store::read( $this->path )->get_error_code() );
		self::assertSame( array(), $remote->operations );
	}

	public function test_remote_second_promotion_failure_restores_php_css_and_retains_revision(): void {
		$remote = $this->remote();
		$remote->fail_target = $this->mapped( $remote, $this->path );
		$remote->fail_count = 1;
		$remote->erase_on_failure = true;
		$new = array( 'php' => str_replace( 'Fixture', 'Changed', $this->files['php'] ), 'css' => '/* changed CSS */' );
		$result = Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->files ), $new, 'Remote failure' );
		self::assertSame( 'imajiner_write_failed', $result->get_error_code() );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		$id = Imajiner_Template_Store::get_revisions( $this->path )[0]['id'];
		self::assertSame( $this->files, Imajiner_Template_Store::get_revision_files( $this->path, $id ) );
		foreach ( array_keys( $remote->files ) as $path ) {
			self::assertStringNotContainsString( '/remote/private/.imj-', $path );
		}
		self::assertSame( $this->files['php'], file_get_contents( $this->path ) );
	}

	public function test_remote_short_stage_and_chmod_failure_never_promote_partial_source(): void {
		$remote = $this->remote();
		$remote->short_write = true;
		$result = Imajiner_Filesystem::write( $this->path, 'replacement' );
		self::assertSame( 'imajiner_write_failed', $result->get_error_code() );
		self::assertSame( $this->files['php'], Imajiner_Filesystem::read( $this->path ) );
		$remote->short_write = false;
		$remote->fail_chmod = true;
		self::assertSame( 'imajiner_not_writable', Imajiner_Filesystem::write( $this->path, 'replacement' )->get_error_code() );
		foreach ( $remote->operations as $operation ) {
			self::assertNotSame( 'move', $operation[0] );
		}
		self::assertCount( 2, $remote->files );
	}

	public function test_remote_new_template_failure_removes_new_css_and_leaves_existing_fixture(): void {
		$remote = $this->remote();
		$path = str_replace( '.php', '-new.php', $this->path );
		$remote->fail_target = $this->mapped( $remote, $path );
		$remote->fail_count = 1;
		self::assertSame( 'imajiner_write_failed', Imajiner_Template_Store::create( $path, $this->files )->get_error_code() );
		self::assertFalse( Imajiner_Filesystem::exists( $path ) );
		self::assertFalse( Imajiner_Filesystem::exists( Imajiner_Template_Store::css_path( $path ) ) );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
	}

	public function test_remote_ancestor_and_staging_links_are_rejected(): void {
		$remote = $this->remote();
		$remote->links['/remote/themes'] = 'l';
		self::assertSame( 'imajiner_symlink', Imajiner_Filesystem::write( $this->path, '' )->get_error_code() );
		unset( $remote->links['/remote/themes'] );
		$remote->links['/remote/private'] = 'l';
		self::assertSame( 'imajiner_symlink', Imajiner_Filesystem::write( $this->path, '' )->get_error_code() );
		self::assertSame( array(), $remote->operations );
	}

	public function test_remote_move_recovers_destination_on_delete_failure_and_preserves_modes(): void {
		$remote = $this->remote();
		$source = $this->mapped( $remote, $this->path );
		$destination = str_replace( '.php', '-moved.php', $this->path );
		$target = $this->mapped( $remote, $destination );
		$remote->modes[ $source ] = 0640;
		$remote->files[ $target ] = 'previous destination';
		$remote->modes[ $target ] = 0600;
		$remote->fail_delete = $source;
		self::assertSame( 'imajiner_delete_failed', Imajiner_Filesystem::move( $this->path, $destination, true )->get_error_code() );
		self::assertSame( $this->files['php'], $remote->files[ $source ] );
		self::assertSame( 'previous destination', $remote->files[ $target ] );
		self::assertSame( 0600, $remote->modes[ $target ] );
		$remote->fail_delete = '';
		self::assertTrue( Imajiner_Filesystem::move( $this->path, $this->path, true ) );
		self::assertTrue( Imajiner_Filesystem::move( $this->path, $destination, true ) );
		self::assertFalse( isset( $remote->files[ $source ] ) );
		self::assertSame( $this->files['php'], $remote->files[ $target ] );
		self::assertSame( 0640, $remote->modes[ $target ] );
	}

	public function test_remote_delete_failure_restores_deleted_php_with_original_permissions(): void {
		$remote = $this->remote();
		$remote->modes[ $this->mapped( $remote, $this->path ) ] = 0640;
		$remote->fail_delete = $this->mapped( $remote, Imajiner_Template_Store::css_path( $this->path ) );
		self::assertSame( 'imajiner_delete_failed', Imajiner_Template_Store::delete( $this->path, Imajiner_Template_Store::hash( $this->files ) )->get_error_code() );
		self::assertSame( $this->files, Imajiner_Template_Store::read( $this->path ) );
		self::assertSame( 0640, Imajiner_Filesystem::get_permissions( $this->path ) );
	}

	public function test_rename_preserves_php_and_css_permissions(): void {
		$destination = str_replace( '.php', '-renamed.php', $this->path );
		$this->track( $destination );
		chmod( $this->path, 0640 );
		chmod( Imajiner_Template_Store::css_path( $this->path ), 0600 );
		self::assertTrue( Imajiner_Template_Store::rename( $this->path, $destination, Imajiner_Template_Store::hash( $this->files ) ) );
		self::assertSame( 0640, Imajiner_Filesystem::get_permissions( $destination ) );
		self::assertSame( 0600, Imajiner_Filesystem::get_permissions( Imajiner_Template_Store::css_path( $destination ) ) );
	}

	public function test_unavailable_remote_recovery_reports_failure_and_keeps_private_revision(): void {
		$remote = $this->remote();
		$remote->fail_target = $this->mapped( $remote, $this->path );
		$remote->fail_count = 100;
		$remote->fail_write_target = $remote->fail_target;
		$remote->erase_on_failure = true;
		$new = array( 'php' => $this->files['php'] . "\n", 'css' => '/* changed */' );
		self::assertSame( 'imajiner_rollback_failed', Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->files ), $new, 'Failure' )->get_error_code() );
		$id = Imajiner_Template_Store::get_revisions( $this->path )[0]['id'];
		self::assertSame( 'private', get_post_status( $id ) );
		self::assertSame( $this->files, Imajiner_Template_Store::get_revision_files( $this->path, $id ) );
		self::assertSame( $this->files['css'], Imajiner_Filesystem::read( Imajiner_Template_Store::css_path( $this->path ) ) );
	}

	public function test_uninstall_retains_history_by_default_and_bounds_opt_in_deletion(): void {
		$uploads = wp_upload_dir();
		$directory = $uploads['basedir'] . '/imajiner/preview';
		wp_mkdir_p( $directory );
		$prefix = substr( wp_generate_uuid4(), 0, 8 );
		$cache = $directory . '/' . $prefix . '-storage-fixture-' . md5( $this->path ) . '.php';
		$link = $directory . '/' . $prefix . '-storage-link-' . md5( $this->path ) . '.php';
		$unknown = $directory . '/' . $prefix . '-storage-unknown.php';
		array_push( $this->cleanup, $cache, $link, $unknown );
		file_put_contents( $cache, '<?php defined( "ABSPATH" ) || exit;' );
		file_put_contents( $unknown, '<?php defined( "ABSPATH" ) || exit;' );
		symlink( $this->path, $link );
		$new = array( 'php' => $this->files['php'], 'css' => '/* changed */' );
		self::assertTrue( Imajiner_Template_Store::write( $this->path, Imajiner_Template_Store::hash( $this->files ), $new, 'Uninstall fixture' ) );
		$id = Imajiner_Template_Store::get_revisions( $this->path )[0]['id'];
		$settings = get_option( 'imajiner_editor_ai', null );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'imajiner-editor/imajiner-editor.php' );
		}
		try {
			delete_option( Imajiner_Filesystem_Settings::RETENTION_OPTION );
			$this->run_uninstall();
			self::assertSame( 'private', get_post_status( $id ) );
			self::assertFileExists( $cache );
			update_option( Imajiner_Filesystem_Settings::RETENTION_OPTION, true, false );
			$this->run_uninstall();
			self::assertFalse( get_post_status( $id ) );
			self::assertFileDoesNotExist( $cache );
			self::assertFileExists( $unknown );
			self::assertTrue( is_link( $link ) );
			self::assertSame( $new, Imajiner_Template_Store::read( $this->path ) );
		} finally {
			if ( null !== $settings ) {
				update_option( 'imajiner_editor_ai', $settings, false );
			}
		}
	}

	private function run_uninstall(): void {
		include dirname( __DIR__ ) . '/uninstall.php';
	}

	public function test_credential_ciphertext_is_user_scoped_and_expiry_is_respected(): void {
		$key = 'imajiner_fs_' . $this->user . '_' . substr( hash( 'sha256', wp_get_session_token() . '|' . get_stylesheet() ), 0, 32 );
		$this->credential_keys[] = $key;
		$data = array( 'hostname' => 'fixture.invalid', 'username' => 'fixture', 'password' => wp_generate_password( 30 ) );
		$cipher = Imajiner_Secrets::encrypt( wp_json_encode( $data ) );
		set_transient( $key, $cipher, 600 );
		self::assertStringNotContainsString( $data['password'], get_option( '_transient_' . $key ) );
		self::assertSame( $data, Imajiner_Filesystem_Settings::credentials() );
		wp_set_current_user( $this->other );
		self::assertSame( array(), Imajiner_Filesystem_Settings::credentials() );
		wp_set_current_user( $this->user );
		update_option( '_transient_timeout_' . $key, time() - 1, false );
		self::assertSame( array(), Imajiner_Filesystem_Settings::credentials() );
	}

	public function test_retention_requires_nonce_and_capabilities_and_defaults_to_keep(): void {
		delete_option( Imajiner_Filesystem_Settings::RETENTION_OPTION );
		self::assertFalse( get_option( Imajiner_Filesystem_Settings::RETENTION_OPTION, false ) );
		self::assertSame( 'imajiner_forbidden', Imajiner_Filesystem_Settings::save_retention( true, 'invalid' )->get_error_code() );
		self::assertTrue( Imajiner_Filesystem_Settings::save_retention( true, wp_create_nonce( 'imajiner_file_settings' ) ) );
		self::assertTrue( get_option( Imajiner_Filesystem_Settings::RETENTION_OPTION ) );
		wp_set_current_user( $this->other );
		self::assertSame( 'imajiner_forbidden', Imajiner_Filesystem_Settings::save_retention( false, wp_create_nonce( 'imajiner_file_settings' ) )->get_error_code() );
	}

	public function test_remote_settings_render_core_nonce_and_never_render_saved_password(): void {
		$post = $_POST;
		$method = $_SERVER['REQUEST_METHOD'];
		$_POST = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$key = 'imajiner_fs_' . $this->user . '_' . substr( hash( 'sha256', wp_get_session_token() . '|' . get_stylesheet() ), 0, 32 );
		$this->credential_keys[] = $key;
		$password = wp_generate_password( 32 );
		set_transient( $key, Imajiner_Secrets::encrypt( wp_json_encode( array( 'password' => $password ) ) ), 600 );
		add_filter( 'filesystem_method', array( $this, 'force_ftp' ) );
		ob_start();
		try {
			Imajiner_Filesystem_Settings::screen();
			$html = ob_get_contents();
			self::assertStringContainsString( 'name="_fs_nonce"', $html );
			self::assertStringContainsString( 'name="_imajiner_fs_nonce"', $html );
			self::assertStringContainsString( 'name="remove_history"', $html );
			self::assertStringNotContainsString( $password, $html );
		} finally {
			ob_end_clean();
			remove_filter( 'filesystem_method', array( $this, 'force_ftp' ) );
			$_POST = $post;
			$_SERVER['REQUEST_METHOD'] = $method;
		}
	}
}
