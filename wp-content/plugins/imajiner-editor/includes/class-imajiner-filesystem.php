<?php
/** Bounded WordPress filesystem access. @package Imajiner_Editor */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

/** Direct moves must not use WordPress's delete/copy fallback. */
class Imajiner_Filesystem_Direct extends WP_Filesystem_Direct {
	public function move( $source, $destination, $overwrite = false ) {
		if ( ! $overwrite && $this->exists( $destination ) ) {
			return false;
		}
		return @rename( $source, $destination );
	}
}

class Imajiner_Filesystem {
	private static $filesystem;
	private static $context;

	/** Establish the configured method; never downgrade a remote method. */
	public static function init() {
		$context = get_stylesheet() . '|' . get_current_user_id() . '|' . wp_get_session_token();
		if ( self::$filesystem && self::$context === $context ) {
			return true;
		}
		self::$filesystem = null;
		self::$context    = $context;
		$credentials = class_exists( 'Imajiner_Filesystem_Settings' ) ? Imajiner_Filesystem_Settings::credentials() : array();
		$method = get_filesystem_method( $credentials, get_stylesheet_directory() );
		if ( ! in_array( $method, array( 'direct', 'ftpext', 'ftpsockets', 'ssh2' ), true ) ) {
			return self::error( 'imajiner_filesystem_method', __( 'This filesystem method is not supported.', 'imajiner-editor' ) );
		}
		if ( 'direct' !== $method && ! $credentials ) {
			foreach ( array( 'hostname' => 'FTP_HOST', 'username' => 'FTP_USER', 'password' => 'FTP_PASS', 'public_key' => 'FTP_PUBKEY', 'private_key' => 'FTP_PRIKEY' ) as $key => $constant ) {
				if ( defined( $constant ) ) {
					$credentials[ $key ] = constant( $constant );
				}
			}
			if ( defined( 'FTP_SSL' ) && FTP_SSL ) {
				$credentials['connection_type'] = 'ftps';
			}
		}
		if ( 'direct' !== $method && empty( $credentials ) ) {
			return self::error( 'imajiner_filesystem_credentials', __( 'Connect the filesystem in Imajiner File Access, then retry.', 'imajiner-editor' ), 503 );
		}
		if ( ! WP_Filesystem( $credentials, get_stylesheet_directory() ) ) {
			return self::error( 'imajiner_filesystem_credentials', __( 'The filesystem connection failed. Reconnect in Imajiner File Access, then retry.', 'imajiner-editor' ), 503 );
		}
		global $wp_filesystem;
		if ( $wp_filesystem->method !== $method ) {
			return self::error( 'imajiner_filesystem_method', __( 'The filesystem method changed. Reconnect before editing.', 'imajiner-editor' ) );
		}
		self::$filesystem = 'direct' === $method ? new Imajiner_Filesystem_Direct( array() ) : $wp_filesystem;
		return true;
	}

	public static function reset() {
		self::$filesystem = null;
		self::$context    = null;
	}

	public static function can_write() {
		if ( ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ! current_user_can( 'edit_themes' ) ) {
			return self::error( 'imajiner_forbidden', __( 'You are not allowed to edit theme files.', 'imajiner-editor' ), 403 );
		}
		return true;
	}

	/** Validate even nonexistent paths, including every existing ancestor. */
	public static function validate_path( $path ) {
		$root = untrailingslashit( wp_normalize_path( get_stylesheet_directory() ) );
		if ( ! is_child_theme() || ! is_string( $path ) || preg_match( '#[\x00-\x1f\x7f\\\\]|(^|/)\.{1,2}(/|$)|://#', $path ) ) {
			return self::error( 'imajiner_invalid_path', __( 'Use a file inside the active child theme.', 'imajiner-editor' ), 400 );
		}
		$path = wp_normalize_path( $path );
		if ( 0 !== strpos( $path, $root . '/' ) || false !== strpos( $path, '//' ) ) {
			return self::error( 'imajiner_invalid_path', __( 'Use a file inside the active child theme.', 'imajiner-editor' ), 400 );
		}
		$cursor = $path;
		while ( strlen( $cursor ) >= strlen( $root ) ) {
			clearstatcache( true, $cursor );
			if ( is_link( $cursor ) ) {
				return self::error( 'imajiner_symlink', __( 'Symbolic links cannot be edited.', 'imajiner-editor' ), 400 );
			}
			$real = realpath( $cursor );
			$real_root = realpath( $root );
			if ( false !== $real && ( false === $real_root || ( $real !== $real_root && 0 !== strpos( wp_normalize_path( $real ), wp_normalize_path( $real_root ) . '/' ) ) ) ) {
				return self::error( 'imajiner_invalid_path', __( 'Use a file inside the active child theme.', 'imajiner-editor' ), 400 );
			}
			if ( $cursor === $root ) {
				break;
			}
			$cursor = dirname( $cursor );
		}
		return true;
	}

	private static function map( $path ) {
		$valid = self::validate_path( $path );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$ready = self::init();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$root = untrailingslashit( wp_normalize_path( get_stylesheet_directory() ) );
		$remote = self::$filesystem->find_folder( $root );
		if ( ! is_string( $remote ) || '' === $remote || '/' === $remote ) {
			return self::error( 'imajiner_filesystem_mapping', __( 'The child theme could not be located on the filesystem.', 'imajiner-editor' ) );
		}
		$mapped = untrailingslashit( $remote ) . substr( wp_normalize_path( $path ), strlen( $root ) );
		if ( 'direct' !== self::$filesystem->method ) {
			$valid = self::verify_remote_path( $mapped );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		return $mapped;
	}

	private static function verify_remote_path( $path ) {
		if ( '/' !== substr( $path, 0, 1 ) || preg_match( '#(^|/)\.{1,2}(/|$)|[\\\\\x00-\x1f]#', $path ) ) {
			return self::error( 'imajiner_filesystem_mapping', __( 'The filesystem path could not be verified.', 'imajiner-editor' ) );
		}
		$cursor = '';
		foreach ( explode( '/', trim( $path, '/' ) ) as $segment ) {
			$list = self::$filesystem->dirlist( $cursor ?: '/', true, false );
			if ( ! is_array( $list ) ) {
				return self::error( 'imajiner_filesystem_mapping', __( 'The filesystem path could not be verified.', 'imajiner-editor' ) );
			}
			if ( ! isset( $list[ $segment ] ) ) {
				if ( self::$filesystem->exists( $cursor . '/' . $segment ) ) {
					return self::error( 'imajiner_filesystem_mapping', __( 'The filesystem listing is incomplete.', 'imajiner-editor' ) );
				}
				break;
			}
			$entry = $list[ $segment ];
			if ( ! isset( $entry['type'] ) || ! in_array( $entry['type'], array( 'f', 'd' ), true ) || ! empty( $entry['islink'] ) || ( isset( $entry['perms'] ) && 'l' === substr( $entry['perms'], 0, 1 ) ) ) {
				return self::error( 'imajiner_symlink', __( 'Symbolic links cannot be edited.', 'imajiner-editor' ), 400 );
			}
			$cursor .= '/' . $segment;
			if ( self::$filesystem instanceof WP_Filesystem_SSH2 ) {
				$stat = ssh2_sftp_lstat( self::$filesystem->sftp_link, $cursor );
				if ( ! $stat || ( $stat['mode'] & 0170000 ) === 0120000 ) {
					return self::error( 'imajiner_symlink', __( 'The SSH filesystem path could not be verified without symbolic links.', 'imajiner-editor' ), 400 );
				}
			}
		}
		return true;
	}

	public static function check_path( $path ) {
		$mapped = self::map( $path );
		return is_wp_error( $mapped ) ? $mapped : true;
	}

	public static function read( $path ) {
		$file = self::map( $path );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$source = self::$filesystem->get_contents( $file );
		return false === $source ? self::error( 'imajiner_unreadable', __( 'The file could not be read.', 'imajiner-editor' ) ) : $source;
	}

	public static function exists( $path ) {
		$file = self::map( $path );
		return ! is_wp_error( $file ) && self::$filesystem->exists( $file );
	}

	public static function mkdir( $path ) {
		$allowed = self::can_write();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( $path === untrailingslashit( get_stylesheet_directory() ) ) {
			return self::init();
		}
		$file = self::map( $path );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( self::$filesystem->is_dir( $file ) ) {
			return true;
		}
		if ( ! self::$filesystem->is_dir( dirname( $file ) ) ) {
			$parent = self::mkdir( dirname( $path ) );
			if ( is_wp_error( $parent ) ) {
				return $parent;
			}
		}
		return self::$filesystem->mkdir( $file, FS_CHMOD_DIR ) ? true : self::error( 'imajiner_not_writable', __( 'The folder could not be created.', 'imajiner-editor' ) );
	}

	public static function write( $path, $contents ) {
		return self::put( $path, $contents );
	}

	private static function put( $path, $contents, $mode_override = null ) {
		$allowed = self::can_write();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$file = self::map( $path );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! is_string( $contents ) || self::$filesystem->is_dir( $file ) ) {
			return self::error( 'imajiner_write_failed', __( 'The file could not be written.', 'imajiner-editor' ) );
		}
		$folder = self::mkdir( dirname( $path ) );
		if ( is_wp_error( $folder ) ) {
			return $folder;
		}
		$existed = self::$filesystem->exists( $file );
		$before = $existed ? self::$filesystem->get_contents( $file ) : '';
		if ( false === $before || ( $existed && ! self::$filesystem->is_writable( $file ) ) || ! self::$filesystem->is_writable( dirname( $file ) ) ) {
			return self::error( 'imajiner_not_writable', __( 'The file or its folder is not writable.', 'imajiner-editor' ) );
		}
		$mode = FS_CHMOD_FILE;
		if ( null !== $mode_override ) {
			$mode = $mode_override;
		} elseif ( $existed ) {
			$permissions = self::permissions( $file );
			if ( is_wp_error( $permissions ) ) {
				return $permissions;
			}
			$mode = $permissions;
		}
		$staging = self::staging_directory();
		if ( is_wp_error( $staging ) ) {
			return $staging;
		}
		$temp = trailingslashit( $staging ) . '.imj-' . wp_generate_uuid4() . '.tmp';
		if ( ! self::$filesystem->put_contents( $temp, $contents, 0600 ) || self::$filesystem->get_contents( $temp ) !== $contents ) {
			self::$filesystem->delete( $temp, false, 'f' );
			return self::error( 'imajiner_write_failed', __( 'The staged file could not be verified.', 'imajiner-editor' ) );
		}
		if ( ! self::$filesystem->chmod( $temp, $mode ) || self::permissions( $temp ) !== $mode ) {
			self::$filesystem->delete( $temp, false, 'f' );
			return self::error( 'imajiner_not_writable', __( 'File permissions could not be preserved.', 'imajiner-editor' ) );
		}
		$moved = self::$filesystem->move( $temp, $file, true );
		$verified = $moved && self::$filesystem->get_contents( $file ) === $contents && self::permissions( $file ) === $mode;
		self::$filesystem->delete( $temp, false, 'f' );
		if ( ! $verified ) {
			// Remote methods may delete/copy before returning failure.
			if ( ! $existed ) {
				$restored = ! self::$filesystem->exists( $file ) || self::$filesystem->delete( $file, false, 'f' );
			} elseif ( 'direct' === self::$filesystem->method ) {
				$restored = self::$filesystem->get_contents( $file ) === $before && self::permissions( $file ) === $mode;
				if ( ! $restored && $moved ) {
					$restored = self::restore_direct( $file, $before, $mode, $staging );
				}
			} else {
				$restored = self::$filesystem->put_contents( $file, $before, $mode ) && self::$filesystem->get_contents( $file ) === $before && self::permissions( $file ) === $mode;
			}
			self::invalidate( $path );
			return self::error( $restored ? 'imajiner_write_failed' : 'imajiner_rollback_failed', $restored ? __( 'The file could not be replaced. Its previous contents were restored.', 'imajiner-editor' ) : __( 'The write and recovery failed. Restore from revision history after reconnecting the filesystem.', 'imajiner-editor' ) );
		}
		self::invalidate( $path );
		return true;
	}

	private static function restore_direct( $file, $contents, $mode, $staging ) {
		$temp = trailingslashit( $staging ) . '.imj-' . wp_generate_uuid4() . '.tmp';
		$restored = self::$filesystem->put_contents( $temp, $contents, 0600 )
			&& self::$filesystem->get_contents( $temp ) === $contents
			&& self::$filesystem->chmod( $temp, $mode )
			&& self::permissions( $temp ) === $mode
			&& self::$filesystem->move( $temp, $file, true )
			&& self::$filesystem->get_contents( $file ) === $contents
			&& self::permissions( $file ) === $mode;
		self::$filesystem->delete( $temp, false, 'f' );
		return $restored;
	}

	public static function delete( $path ) {
		$allowed = self::can_write();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$file = self::map( $path );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$result = ! self::$filesystem->exists( $file ) || self::$filesystem->delete( $file, false );
		self::invalidate( $path );
		return $result ? true : self::error( 'imajiner_delete_failed', __( 'The file could not be deleted.', 'imajiner-editor' ) );
	}

	public static function move( $from, $to, $overwrite = false ) {
		$allowed = self::can_write();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$source = self::map( $from );
		$target = self::map( $to );
		if ( is_wp_error( $source ) || is_wp_error( $target ) ) {
			return is_wp_error( $source ) ? $source : $target;
		}
		if ( $source === $target && self::$filesystem->is_file( $source ) ) {
			return true;
		}
		if ( ! self::$filesystem->is_file( $source ) || ( self::$filesystem->exists( $target ) && ! $overwrite ) ) {
			return self::error( 'imajiner_move_failed', __( 'The file cannot be moved to that destination.', 'imajiner-editor' ) );
		}
		// Use the verified write/recovery path for non-atomic remote moves.
		if ( 'direct' !== self::$filesystem->method ) {
			$source_mode = self::permissions( $source );
			$target_mode = self::$filesystem->exists( $target ) ? self::permissions( $target ) : null;
			if ( is_wp_error( $source_mode ) || is_wp_error( $target_mode ) ) {
				return is_wp_error( $source_mode ) ? $source_mode : $target_mode;
			}
			$before = self::$filesystem->exists( $target ) ? self::read( $to ) : null;
			if ( is_wp_error( $before ) ) {
				return $before;
			}
			$data = self::read( $from );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$result = self::put( $to, $data, $source_mode );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result = self::delete( $from );
			if ( is_wp_error( $result ) ) {
				$recovery = null === $before ? self::delete( $to ) : self::put( $to, $before, $target_mode );
				$source_recovery = self::$filesystem->get_contents( $source ) === $data ? true : self::put( $from, $data, $source_mode );
				if ( is_wp_error( $recovery ) || is_wp_error( $source_recovery ) ) {
					return self::error( 'imajiner_rollback_failed', __( 'The move and recovery failed. Reconnect the filesystem before retrying.', 'imajiner-editor' ) );
				}
			}
			return $result;
		}
		$result = self::$filesystem->move( $source, $target, $overwrite );
		self::invalidate( $from );
		self::invalidate( $to );
		return $result ? true : self::error( 'imajiner_move_failed', __( 'The file could not be moved.', 'imajiner-editor' ) );
	}

	private static function invalidate( $path ) {
		clearstatcache( true, $path );
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true );
		}
	}

	private static function staging_directory() {
		$local = defined( 'IMAJINER_FILESYSTEM_TEMP_DIR' ) ? IMAJINER_FILESYSTEM_TEMP_DIR : get_temp_dir();
		$real = realpath( $local );
		if ( false === $real || is_link( $local ) ) {
			return self::error( 'imajiner_staging_unavailable', __( 'Configure a private filesystem staging folder outside the public site.', 'imajiner-editor' ), 503 );
		}
		$uploads = wp_upload_dir( null, false );
		foreach ( array( ABSPATH, get_theme_root(), $uploads['basedir'] ) as $public ) {
			$public = realpath( $public );
			if ( false !== $public && ( $real === $public || 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $public ) ) ) ) ) {
				return self::error( 'imajiner_staging_unavailable', __( 'The filesystem staging folder must be outside the public site.', 'imajiner-editor' ), 503 );
			}
		}
		if ( 'direct' !== self::$filesystem->method && ! defined( 'IMAJINER_FILESYSTEM_TEMP_DIR' ) ) {
			return self::error( 'imajiner_staging_unavailable', __( 'Configure IMAJINER_FILESYSTEM_TEMP_DIR as a private folder accessible through FTP or SSH, then retry.', 'imajiner-editor' ), 503 );
		}
		$remote = self::$filesystem->find_folder( $local );
		if ( ! $remote || ! self::$filesystem->is_dir( $remote ) || ! self::$filesystem->is_writable( $remote ) ) {
			return self::error( 'imajiner_staging_unavailable', __( 'The private filesystem staging folder is unavailable.', 'imajiner-editor' ), 503 );
		}
		if ( 'direct' !== self::$filesystem->method ) {
			$valid = self::verify_remote_path( $remote );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		$site = self::$filesystem->abspath();
		if ( ! $site || $remote === $site || 0 === strpos( trailingslashit( $remote ), trailingslashit( $site ) ) ) {
			return self::error( 'imajiner_staging_unavailable', __( 'The filesystem staging folder must be outside the public site.', 'imajiner-editor' ), 503 );
		}
		return $remote;
	}

	private static function permissions( $file ) {
		if ( 'direct' === self::$filesystem->method ) {
			clearstatcache( true, $file );
			$mode = self::$filesystem->getchmod( $file );
		} else {
			$list = self::$filesystem->dirlist( dirname( $file ), true, false );
			$mode = is_array( $list ) && isset( $list[ basename( $file ) ]['permsn'] ) ? $list[ basename( $file ) ]['permsn'] : '';
		}
		return is_string( $mode ) && preg_match( '/^[0-7]{3,4}$/D', $mode ) ? intval( $mode, 8 ) & 0777 : self::error( 'imajiner_not_writable', __( 'File permissions could not be verified.', 'imajiner-editor' ) );
	}

	public static function get_permissions( $path ) {
		$file = self::map( $path );
		return is_wp_error( $file ) ? $file : self::permissions( $file );
	}

	public static function set_permissions( $path, $mode ) {
		$allowed = self::can_write();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$file = self::map( $path );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! is_int( $mode ) || $mode < 0 || $mode > 0777 || ! self::$filesystem->is_file( $file ) || ! self::$filesystem->chmod( $file, $mode ) || self::permissions( $file ) !== $mode ) {
			return self::error( 'imajiner_not_writable', __( 'File permissions could not be preserved.', 'imajiner-editor' ) );
		}
		return true;
	}

	private static function error( $code, $message, $status = 500 ) {
		return new WP_Error( $code, $message, array( 'status' => $status, 'settings_url' => admin_url( 'options-general.php?page=imajiner-file-access' ) ) );
	}
}
