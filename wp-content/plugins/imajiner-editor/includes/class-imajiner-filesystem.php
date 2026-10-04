<?php
/** Bounded WordPress filesystem adapter. @package Imajiner_Editor */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-ftpext.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-ftpsockets.php';
require_once ABSPATH . 'wp-admin/includes/class-ftp.php';
require_once ABSPATH . 'wp-admin/includes/class-ftp-pure.php';

class Imajiner_FTP_Pure extends ftp_pure {
	public function rawlist( $pathname = '', $arg = '' ) {
		$list = parent::rawlist( $pathname, $arg );
		return false === $list && '' !== $arg ? parent::rawlist( $pathname ) : $list;
	}
}

class WP_Filesystem_imajiner_ftpsockets extends WP_Filesystem_ftpsockets {
	public function __construct( $options ) {
		parent::__construct( $options );
		$this->ftp = new Imajiner_FTP_Pure();
	}
}

class WP_Filesystem_imajiner_ftpext extends WP_Filesystem_FTPext {
	public function dirlist( $path = '.', $include_hidden = true, $recursive = false ) {
		$list = $this->core_listing( $path, $include_hidden, $recursive );
		return false === $list && $this->empty_directory( $path ) ? array() : $list;
	}

	protected function core_listing( $path, $include_hidden, $recursive ) {
		return parent::dirlist( $path, $include_hidden, $recursive );
	}

	/** Core treats a successful, zero-entry FTP listing as a missing directory. */
	protected function empty_directory( $path ) {
		$cwd = @ftp_pwd( $this->link );
		if ( false === $cwd || ! @ftp_chdir( $this->link, $path ) ) {
			return false;
		}
		try {
			$list = @ftp_rawlist( $this->link, '-a', false );
		} finally {
			$restored = @ftp_chdir( $this->link, $cwd );
		}
		return $restored && array() === $list;
	}
}

/** Core's direct move deletes before rename and can fall back to copy. */
class Imajiner_Atomic_Direct_Filesystem extends WP_Filesystem_Direct {
	public function move( $source, $destination, $overwrite = false ) {
		if ( ! $overwrite && $this->exists( $destination ) ) {
			return false;
		}
		return @rename( $source, $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

class Imajiner_Filesystem {
	private static $client;
	private static $context;
	private static $method;
	private static $scaffold;

	/** Scope inactive-child writes to a newly reserved client slug, never the parent. */
	public static function scaffold( $slug, $callback ) {
		if ( ! current_user_can( 'install_themes' ) || ! current_user_can( 'edit_themes' ) || ! wp_is_file_mod_allowed( 'imajiner_child_theme' ) || is_multisite() || is_wp_error( Imajiner_Site_Setup::validate_slug( $slug ) ) || self::$scaffold ) {
			return self::path_error();
		}
		$root = wp_get_theme( 'imajiner' )->get_theme_root();
		$path = wp_normalize_path( $root . '/' . $slug );
		if ( file_exists( $path ) || is_link( $path ) ) {
			return self::path_error();
		}
		self::$scaffold = $path;
		try {
			return call_user_func( $callback );
		} finally {
			self::$scaffold = null;
		}
	}

	/** Connect lazily; never downgrade a configured remote transport. */
	public static function init() {
		$credentials = class_exists( 'Imajiner_Filesystem_Credentials' ) ? Imajiner_Filesystem_Credentials::credentials() : array();
		$method = get_filesystem_method( $credentials, WP_CONTENT_DIR );
		$context = get_current_user_id() . '|' . get_stylesheet() . '|' . wp_get_session_token();
		if ( self::$client && self::$context === $context ) {
			if ( null === self::$method || self::$method === $method ) {
				return true;
			}
		}
		return self::connect( $credentials );
	}

	/** Also used by the credential screen, so it cannot bypass transport checks. */
	public static function connect( array $credentials ) {
		self::$client = null;
		self::$context = null;
		self::$method = null;
		$method = get_filesystem_method( $credentials, WP_CONTENT_DIR );
		$context = get_current_user_id() . '|' . get_stylesheet() . '|' . wp_get_session_token();
		$class = 'WP_Filesystem_' . $method;
		if ( ! in_array( $method, array( 'direct', 'ftpext', 'ftpsockets', 'ssh2' ), true ) && ! is_subclass_of( $class, 'WP_Filesystem_Base' ) ) {
			return self::error( 'imajiner_filesystem_method' );
		}
		$credentials = self::prepare_credentials( $credentials, $method );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		if ( in_array( $method, array( 'ftpext', 'ftpsockets' ), true ) ) {
			add_filter( 'filesystem_method', array( __CLASS__, 'ftp_method' ), PHP_INT_MAX );
		}
		try {
			$connected = @WP_Filesystem( $credentials, WP_CONTENT_DIR );
		} finally {
			remove_filter( 'filesystem_method', array( __CLASS__, 'ftp_method' ), PHP_INT_MAX );
		}
		if ( ! $connected ) {
			return self::error( 'imajiner_filesystem_credentials' );
		}
		global $wp_filesystem;
		if ( $wp_filesystem->method !== $method && get_class( $wp_filesystem ) !== $class ) {
			return self::error( 'imajiner_filesystem_method' );
		}
		self::$client = 'WP_Filesystem_Direct' === get_class( $wp_filesystem ) ? new Imajiner_Atomic_Direct_Filesystem( null ) : $wp_filesystem;
		self::$context = $context;
		self::$method = $method;
		return true;
	}

	public static function ftp_method( $method ) {
		return in_array( $method, array( 'ftpext', 'ftpsockets' ), true ) ? 'imajiner_' . $method : $method;
	}

	/** Normalize wp-config endpoints without silently changing encryption policy. */
	public static function prepare_credentials( array $credentials, $method ) {
		foreach ( array( 'hostname' => 'FTP_HOST', 'username' => 'FTP_USER', 'password' => 'FTP_PASS', 'public_key' => 'FTP_PUBKEY', 'private_key' => 'FTP_PRIKEY' ) as $name => $constant ) {
			if ( defined( $constant ) ) {
				$credentials[ $name ] = constant( $constant );
			}
		}
		if ( isset( $credentials['hostname'] ) ) {
			$host = $credentials['hostname'];
			if ( ! is_string( $host ) || preg_match( '/[\x00-\x20\x7f]/', $host ) ) {
				return self::error( 'imajiner_filesystem_credentials' );
			}
			if ( preg_match( '~^([a-z]+)://~i', $host, $matches ) ) {
				$scheme = strtolower( $matches[1] );
				if ( ! in_array( $scheme, array( 'ftp', 'ftps', 'ssh', 'sftp' ), true ) ) {
					return self::error( 'imajiner_filesystem_credentials' );
				}
				if ( 'direct' !== $method && ( ( in_array( $scheme, array( 'ssh', 'sftp' ), true ) && 'ssh2' !== $method ) || ( in_array( $scheme, array( 'ftp', 'ftps' ), true ) && 'ssh2' === $method ) ) ) {
					return self::error( 'imajiner_filesystem_credentials' );
				}
				if ( 'ftps' === $scheme ) {
					$credentials['connection_type'] = 'ftps';
				}
				$host = substr( $host, strlen( $matches[0] ) );
			}
			$endpoint = wp_parse_url( 'ftp://' . $host );
			if ( ! is_array( $endpoint ) || empty( $endpoint['host'] ) || isset( $endpoint['user'] ) || isset( $endpoint['pass'] ) || isset( $endpoint['path'] ) || isset( $endpoint['query'] ) || isset( $endpoint['fragment'] ) ) {
				return self::error( 'imajiner_filesystem_credentials' );
			}
			$credentials['hostname'] = trim( $endpoint['host'], '[]' );
			if ( isset( $endpoint['port'] ) ) {
				$credentials['port'] = $endpoint['port'];
			}
		}
		if ( isset( $credentials['port'] ) ) {
			$port = $credentials['port'];
			if ( ( ! is_int( $port ) && ! is_string( $port ) ) || ! preg_match( '/^[0-9]{1,5}$/D', (string) $port ) || (int) $port < 1 || (int) $port > 65535 ) {
				return self::error( 'imajiner_filesystem_credentials' );
			}
			$credentials['port'] = (int) $port;
		}
		$tls = ( defined( 'FTP_SSL' ) && FTP_SSL ) || ( isset( $credentials['connection_type'] ) && 'ftps' === $credentials['connection_type'] );
		if ( in_array( $method, array( 'ftpext', 'ftpsockets' ), true ) && $tls ) {
			if ( 'ftpext' !== $method || ! function_exists( 'ftp_ssl_connect' ) ) {
				return self::error( 'imajiner_filesystem_tls', __( 'Encrypted FTP requires the WordPress FTP extension with TLS support. The socket transport cannot send encrypted credentials.', 'imajiner-editor' ) );
			}
			$credentials['connection_type'] = 'ftps';
		}
		return $credentials;
	}

	public static function error( $code = 'imajiner_filesystem_failed', $message = null ) {
		return new WP_Error( $code, null === $message ? __( 'The filesystem operation could not be completed. Check filesystem access in Settings → Imajiner Filesystem, then retry.', 'imajiner-editor' ) : $message, array( 'status' => 503, 'settings_url' => admin_url( 'options-general.php?page=imajiner-filesystem' ) ) );
	}

	/** Validate local names before translating into a transport's content root. */
	public static function validate_path( $path, $read_only = false ) {
		if ( ! is_string( $path ) || '' === $path || preg_match( '~[\x00-\x1f\\\\]|(?:^|/)\.{1,2}(?:/|$)|://~', $path ) ) {
			return self::path_error();
		}
		$path = untrailingslashit( wp_normalize_path( $path ) );
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$child = untrailingslashit( wp_normalize_path( get_stylesheet_directory() ) );
		$uploads = wp_upload_dir( null, false );
		$preview = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . '/imajiner/preview';
		$in_child = is_child_theme() && ( $path === $child || 0 === strpos( $path, $child . '/' ) );
		$in_preview = $path === $preview || 0 === strpos( $path, $preview . '/' );
		$parent = untrailingslashit( wp_normalize_path( wp_get_theme( 'imajiner' )->get_stylesheet_directory() ) );
		$in_parent = $read_only && ( $path === $parent || 0 === strpos( $path, $parent . '/' ) );
		$in_scaffold = self::$scaffold && ( $path === self::$scaffold || 0 === strpos( $path, self::$scaffold . '/' ) );
		if ( ( ! $in_child && ! $in_preview && ! $in_parent && ! $in_scaffold ) || 0 !== strpos( $path, $content . '/' ) ) {
			return self::path_error();
		}
		// Check each ancestor, including dangling links; realpath alone misses these.
		$cursor = $path;
		while ( dirname( $cursor ) !== $cursor ) {
			if ( is_link( $cursor ) ) {
				return self::path_error();
			}
			$cursor = dirname( $cursor );
		}
		return $path;
	}

	private static function path_error() {
		return new WP_Error( 'imajiner_path', __( 'The file must be inside the active child theme or the private preview cache, without symbolic links.', 'imajiner-editor' ), array( 'status' => 400 ) );
	}

	private static function path( $path, $read_only = false ) {
		$path = self::validate_path( $path, $read_only );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$ready = self::init();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( 'direct' === self::$client->method ) {
			return $path;
		}
		$root = self::$client->wp_content_dir();
		if ( ! is_string( $root ) || '' === $root || '/' !== substr( $root, 0, 1 ) || preg_match( '~[\x00-\x1f\\\\]|(?:^|/)\.{1,2}(?:/|$)|://~', $root ) ) {
			return self::path_error();
		}
		$suffix = substr( $path, strlen( untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ) + 1 );
		$cursor = untrailingslashit( $root );
		if ( 'ssh2' === self::$client->method ) {
			$ancestor = $cursor;
			while ( '/' !== $ancestor ) {
				$valid = self::ssh_path( $ancestor );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
				$ancestor = dirname( $ancestor );
			}
		} else {
			$ancestor = $cursor;
			while ( '/' !== $ancestor ) {
				$list = self::$client->dirlist( dirname( $ancestor ), true, false );
				$name = basename( $ancestor );
				if ( ! is_array( $list ) || ! isset( $list[ $name ] ) ) {
					return self::error( 'imajiner_remote_link_check' );
				}
				if ( ! isset( $list[ $name ]['type'] ) || 'd' !== $list[ $name ]['type'] || ! empty( $list[ $name ]['islink'] ) ) {
					return self::path_error();
				}
				$ancestor = dirname( $ancestor );
			}
		}
		foreach ( explode( '/', $suffix ) as $segment ) {
			$list = self::$client->dirlist( $cursor, true, false );
			if ( false === $list ) {
				return self::error();
			}
			if ( isset( $list[ $segment ] ) && ( ! isset( $list[ $segment ]['type'] ) || ! in_array( $list[ $segment ]['type'], array( 'f', 'd' ), true ) || ! empty( $list[ $segment ]['islink'] ) ) ) {
				return self::path_error();
			}
			$cursor .= '/' . $segment;
			if ( isset( $list[ $segment ] ) && 'ssh2' === self::$client->method ) {
				$valid = self::ssh_path( $cursor );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
			}
			if ( ! isset( $list[ $segment ] ) ) {
				if ( self::$client->exists( $cursor ) ) {
					return self::error( 'imajiner_remote_link_check' );
				}
				break;
			}
			if ( ! self::$client->exists( $cursor ) ) {
				return self::error();
			}
		}
		return trailingslashit( $root ) . $suffix;
	}

	/** Core's SSH listing follows links, so inspect SFTP lstat metadata too. */
	private static function ssh_path( $path ) {
		if ( ! function_exists( 'ssh2_sftp_lstat' ) || empty( self::$client->sftp_link ) ) {
			return self::error( 'imajiner_remote_link_check' );
		}
		$stat = @ssh2_sftp_lstat( self::$client->sftp_link, $path );
		if ( ! is_array( $stat ) || ! isset( $stat['mode'] ) ) {
			return self::error( 'imajiner_remote_link_check' );
		}
		return 0120000 === ( $stat['mode'] & 0170000 ) ? self::path_error() : true;
	}

	public static function read( $path ) {
		$path = self::path( $path, true );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$result = self::$client->is_file( $path ) ? self::$client->get_contents( $path ) : false;
		return false === $result ? self::error( 'imajiner_unreadable' ) : $result;
	}

	public static function exists( $path ) {
		$result = self::exists_checked( $path );
		return ! is_wp_error( $result ) && $result;
	}

	/** Store transactions must not mistake failed mapping for a missing file. */
	public static function exists_checked( $path ) {
		$path = self::path( $path );
		return is_wp_error( $path ) ? $path : self::$client->exists( $path );
	}

	public static function permissions( $path ) {
		$path = self::path( $path );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		return self::mode( $path );
	}

	private static function mode( $path ) {
		if ( in_array( self::$client->method, array( 'ftpext', 'ftpsockets' ), true ) ) {
			$list = self::$client->dirlist( dirname( $path ), true, false );
			$mode = is_array( $list ) && isset( $list[ basename( $path ) ]['permsn'] ) ? $list[ basename( $path ) ]['permsn'] : false;
		} else {
			$mode = self::$client->getchmod( $path );
		}
		return is_string( $mode ) && preg_match( '/^[0-7]{3,4}$/D', $mode ) ? octdec( $mode ) & 0777 : self::error( 'imajiner_permissions' );
	}

	public static function mkdir( $path ) {
		$mapped = self::path( $path );
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}
		if ( self::$client->is_dir( $mapped ) ) {
			return true;
		}
		$missing = array();
		$cursor = $mapped;
		$root = 'direct' === self::$client->method ? untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) : untrailingslashit( self::$client->wp_content_dir() );
		while ( ! self::$client->is_dir( $cursor ) && 0 === strpos( $cursor, $root . '/' ) ) {
			$missing[] = $cursor;
			$cursor = dirname( $cursor );
		}
		if ( ! self::$client->is_dir( $cursor ) ) {
			return self::error( 'imajiner_not_writable' );
		}
		foreach ( array_reverse( $missing ) as $directory ) {
			if ( ! self::$client->mkdir( $directory, FS_CHMOD_DIR ) ) {
				return self::error( 'imajiner_not_writable' );
			}
		}
		return true;
	}

	/** Stage beside the target; remote replacement is recoverable, not atomic. */
	public static function write( $path, $contents, $restore_mode = null ) {
		if ( ! is_string( $contents ) ) {
			return self::error();
		}
		$mapped = self::path( $path );
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}
		$result = self::mkdir( dirname( $path ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$exists = self::$client->exists( $mapped );
		if ( ( $exists && ( ! self::$client->is_file( $mapped ) || ! self::$client->is_writable( $mapped ) ) ) || ! self::$client->is_writable( dirname( $mapped ) ) ) {
			return self::error( 'imajiner_not_writable' );
		}
		$mode = $exists ? self::permissions( $path ) : ( null === $restore_mode ? FS_CHMOD_FILE : $restore_mode );
		if ( is_wp_error( $mode ) || ! is_int( $mode ) || $mode < 0 || $mode > 0777 ) {
			return is_wp_error( $mode ) ? $mode : self::error( 'imajiner_permissions' );
		}
		$extension = 'php' === pathinfo( $path, PATHINFO_EXTENSION ) ? '.php' : '.css';
		$temp = dirname( $mapped ) . '/.imj-' . wp_generate_password( 32, false, false ) . $extension;
		$backup = dirname( $mapped ) . '/.imj-' . wp_generate_password( 32, false, false ) . $extension;
		if ( ! self::$client->put_contents( $temp, $contents, $mode ) || self::$client->get_contents( $temp ) !== $contents || ! self::$client->chmod( $temp, $mode ) || self::mode( $temp ) !== $mode ) {
			self::$client->delete( $temp );
			return self::error( 'imajiner_write_failed' );
		}
		$remote = 'direct' !== self::$client->method;
		if ( $remote && $exists && ! self::$client->move( $mapped, $backup, false ) ) {
			self::$client->delete( $temp );
			return self::error( 'imajiner_write_failed' );
		}
		$done = self::$client->move( $temp, $mapped, ! $remote && $exists );
		$installed = $done;
		if ( $done && ( self::$client->get_contents( $mapped ) !== $contents || self::mode( $mapped ) !== $mode ) ) {
			$done = false;
		}
		if ( ! $done ) {
			self::$client->delete( $temp );
			if ( $remote ) {
				if ( self::$client->exists( $mapped ) && ( ( ! $installed && self::$client->get_contents( $mapped ) !== $contents ) || ! self::$client->delete( $mapped ) ) ) {
					return self::error( 'imajiner_rollback_failed' );
				}
				if ( $exists && ! self::$client->move( $backup, $mapped, false ) ) {
					return self::error( 'imajiner_rollback_failed' );
				}
			}
			return self::error( 'imajiner_write_failed' );
		}
		if ( $remote && $exists && ! self::$client->delete( $backup ) ) {
			return self::error( 'imajiner_cleanup_failed' );
		}
		self::invalidate( $path );
		return true;
	}

	public static function delete( $path ) {
		$mapped = self::path( $path );
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}
		if ( ! self::$client->is_file( $mapped ) ) {
			return self::$client->is_dir( $mapped ) && self::$client->rmdir( $mapped, false ) ? true : self::error();
		}
		$result = self::$client->delete( $mapped, false, 'f' );
		self::invalidate( $path );
		return $result ? true : self::error();
	}

	/** A flat listing for bounded cache cleanup; never follow or recurse links. */
	public static function list_files( $path ) {
		$mapped = self::path( $path );
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}
		$list = self::$client->dirlist( $mapped, true, false );
		return false === $list ? self::error() : $list;
	}

	public static function move( $from, $to, $overwrite = false ) {
		$source = self::path( $from );
		$target = self::path( $to );
		if ( is_wp_error( $source ) || is_wp_error( $target ) ) {
			return is_wp_error( $source ) ? $source : $target;
		}
		if ( ! self::$client->is_file( $source ) || ( self::$client->exists( $target ) && ! $overwrite ) ) {
			return self::error();
		}
		if ( self::$client->exists( $target ) && 'direct' !== self::$client->method ) {
			return self::error( 'imajiner_remote_overwrite' );
		}
		$result = self::mkdir( dirname( $to ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = self::$client->move( $source, $target, $overwrite );
		self::invalidate( $from );
		self::invalidate( $to );
		return $result ? true : self::error();
	}

	private static function invalidate( $path ) {
		clearstatcache( true, $path );
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true );
		}
	}
}
