<?php
/** WordPress credential UI and opt-in cleanup policy. @package Imajiner_Editor */
defined( 'ABSPATH' ) || exit;

class Imajiner_Filesystem_Credentials {
	const META = '_imajiner_fs_credentials';
	const CLEANUP_OPTION = 'imajiner_editor_remove_data';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_logout', array( __CLASS__, 'forget' ) );
		add_action( 'imajiner_fs_expire', array( __CLASS__, 'expire' ) );
	}

	public static function menu() {
		add_options_page( __( 'Imajiner Filesystem', 'imajiner-editor' ), __( 'Imajiner Filesystem', 'imajiner-editor' ), 'edit_themes', 'imajiner-filesystem', array( __CLASS__, 'screen' ) );
	}

	/** Bind encrypted credentials to user, login session and current theme. */
	public static function credentials() {
		$credentials = array();
		foreach ( array( 'hostname' => 'FTP_HOST', 'username' => 'FTP_USER', 'password' => 'FTP_PASS', 'public_key' => 'FTP_PUBKEY', 'private_key' => 'FTP_PRIKEY' ) as $name => $constant ) {
			if ( defined( $constant ) ) {
				$credentials[ $name ] = constant( $constant );
			}
		}
		$stored = get_user_meta( get_current_user_id(), self::META, true );
		if ( ! $stored ) {
			return $credentials;
		}
		if ( ! is_array( $stored ) || ! isset( $stored['cipher'], $stored['expires'], $stored['theme'], $stored['session'] ) || ! is_string( $stored['cipher'] ) || ! is_string( $stored['session'] ) || ! is_numeric( $stored['expires'] ) || $stored['expires'] <= time() || $stored['theme'] !== get_stylesheet() || ! hash_equals( $stored['session'], hash( 'sha256', wp_get_session_token() ) ) || ! current_user_can( 'edit_themes' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) || ! class_exists( 'Imajiner_Secrets' ) ) {
			self::forget( get_current_user_id() );
			return $credentials;
		}
		$plain = Imajiner_Secrets::decrypt( $stored['cipher'] );
		$decoded = false === $plain ? null : json_decode( $plain, true );
		if ( ! is_array( $decoded ) ) {
			self::forget( get_current_user_id() );
			return $credentials;
		}
		$decoded = array_intersect_key( $decoded, array_fill_keys( array( 'hostname', 'username', 'password', 'public_key', 'private_key', 'connection_type', 'port' ), true ) );
		foreach ( $decoded as $value ) {
			if ( ! is_string( $value ) && ! is_int( $value ) ) {
				self::forget();
				return $credentials;
			}
		}
		return array_merge( $decoded, $credentials );
	}

	public static function forget( $user_id = 0 ) {
		delete_user_meta( $user_id ?: get_current_user_id(), self::META );
	}

	public static function expire( $user_id ) {
		$stored = get_user_meta( $user_id, self::META, true );
		if ( is_array( $stored ) && isset( $stored['expires'] ) && $stored['expires'] <= time() ) {
			self::forget( $user_id );
		}
	}

	public static function screen() {
		if ( ! current_user_can( 'edit_themes' ) || ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ) {
			wp_die( esc_html__( 'You cannot edit theme files.', 'imajiner-editor' ), '', array( 'response' => 403 ) );
		}
		nocache_headers();
		$message = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['imajiner_cleanup'] ) ) {
			check_admin_referer( 'imajiner_cleanup' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You cannot change cleanup settings.', 'imajiner-editor' ), '', array( 'response' => 403 ) );
			}
			update_option( self::CLEANUP_OPTION, ! empty( $_POST['remove_data'] ), false );
			$message = __( 'Cleanup preference saved.', 'imajiner-editor' );
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['imajiner_forget'] ) ) {
			check_admin_referer( 'imajiner_forget' );
			self::forget();
			$message = __( 'Filesystem credentials removed.', 'imajiner-editor' );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Imajiner Filesystem', 'imajiner-editor' ) . '</h1>';
		if ( $message ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Use WordPress filesystem credentials to permit FTP or SSH writes. Credentials expire after ten minutes and are encrypted for your current login and theme only. Retry the editor operation after connecting. Configure FS_METHOD in wp-config.php to choose a transport; the plugin never falls back from a remote transport to direct writes.', 'imajiner-editor' ) . '</p>';
		$method = get_filesystem_method( self::credentials(), WP_CONTENT_DIR );
		if ( 'direct' === $method ) {
			echo '<p>' . esc_html__( 'WordPress is using direct filesystem access. No credentials are required.', 'imajiner-editor' ) . '</p>';
		} elseif ( ! is_ssl() ) {
			echo '<p>' . esc_html__( 'Open this settings screen over HTTPS before entering filesystem credentials.', 'imajiner-editor' ) . '</p>';
		} elseif ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			echo '<p>' . esc_html__( 'The sodium PHP extension is required to encrypt temporary filesystem credentials. Ask your host to enable it or provision credentials in wp-config.php.', 'imajiner-editor' ) . '</p>';
		} else {
			if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['_fs_nonce'] ) ) {
				check_admin_referer( 'filesystem-credentials', '_fs_nonce' );
			}
			$credentials = request_filesystem_credentials( admin_url( 'options-general.php?page=imajiner-filesystem' ), $method, false, WP_CONTENT_DIR, array() );
			if ( is_array( $credentials ) ) {
				$connected = Imajiner_Filesystem::connect( $credentials );
				if ( ! is_wp_error( $connected ) ) {
					update_user_meta( get_current_user_id(), self::META, array( 'cipher' => Imajiner_Secrets::encrypt( wp_json_encode( $credentials ) ), 'expires' => time() + 10 * MINUTE_IN_SECONDS, 'theme' => get_stylesheet(), 'session' => hash( 'sha256', wp_get_session_token() ) ) );
					wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, 'imajiner_fs_expire', array( get_current_user_id() ) );
					echo '<p>' . esc_html__( 'Connected. Return to the editor and retry.', 'imajiner-editor' ) . '</p>';
				} else {
					self::forget();
					echo '<div class="notice notice-error"><p>' . esc_html( $connected->get_error_message() ) . '</p></div>';
					request_filesystem_credentials( admin_url( 'options-general.php?page=imajiner-filesystem' ), $method, true, WP_CONTENT_DIR, array() );
				}
			}
		}
		echo '<form method="post">';
		wp_nonce_field( 'imajiner_forget' );
		echo '<input type="hidden" name="imajiner_forget" value="1">';
		submit_button( __( 'Forget my filesystem credentials', 'imajiner-editor' ), 'secondary' );
		echo '</form>';
		if ( current_user_can( 'manage_options' ) ) {
			echo '<h2>' . esc_html__( 'When deleting the plugin', 'imajiner-editor' ) . '</h2><form method="post">';
			wp_nonce_field( 'imajiner_cleanup' );
			echo '<input type="hidden" name="imajiner_cleanup" value="1"><label><input type="checkbox" name="remove_data" value="1" ' . checked( (bool) get_option( self::CLEANUP_OPTION, false ), true, false ) . '> ' . esc_html__( 'Remove preview cache and template revisions on uninstall (default: keep). Client theme PHP and CSS files are never deleted.', 'imajiner-editor' ) . '</label>';
			submit_button( __( 'Save cleanup preference', 'imajiner-editor' ) );
			echo '</form>';
		}
		echo '</div>';
	}
}
