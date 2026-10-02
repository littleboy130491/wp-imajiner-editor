<?php
/** Filesystem connection and retention settings. @package Imajiner_Editor */
defined( 'ABSPATH' ) || exit;

class Imajiner_Filesystem_Settings {
	const RETENTION_OPTION = 'imajiner_editor_remove_history';
	const CREDENTIAL_TTL = 600;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'clear_auth_cookie', array( __CLASS__, 'forget' ) );
	}

	public static function menu() {
		add_options_page( __( 'Imajiner File Access', 'imajiner-editor' ), __( 'Imajiner File Access', 'imajiner-editor' ), 'manage_options', 'imajiner-file-access', array( __CLASS__, 'screen' ) );
	}

	private static function credential_key() {
		return 'imajiner_fs_' . get_current_user_id() . '_' . substr( hash( 'sha256', wp_get_session_token() . '|' . get_stylesheet() ), 0, 32 );
	}

	public static function credentials() {
		if ( is_wp_error( Imajiner_Filesystem::can_write() ) || ! current_user_can( 'manage_options' ) || ! class_exists( 'Imajiner_Secrets' ) || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return array();
		}
		$stored = get_transient( self::credential_key() );
		$plain = $stored ? Imajiner_Secrets::decrypt( $stored ) : false;
		$data = $plain ? json_decode( $plain, true ) : null;
		return is_array( $data ) ? $data : array();
	}

	public static function forget() {
		delete_transient( self::credential_key() );
		Imajiner_Filesystem::reset();
	}

	public static function save_retention( $enabled, $nonce ) {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'imajiner_file_settings' ) || is_wp_error( Imajiner_Filesystem::can_write() ) ) {
			return new WP_Error( 'imajiner_forbidden', __( 'You are not allowed to change these settings.', 'imajiner-editor' ), array( 'status' => 403 ) );
		}
		update_option( self::RETENTION_OPTION, (bool) $enabled, false );
		return true;
	}

	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) || is_wp_error( Imajiner_Filesystem::can_write() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage file access.', 'imajiner-editor' ), '', array( 'response' => 403 ) );
		}
		nocache_headers();
		$url = admin_url( 'options-general.php?page=imajiner-file-access' );
		echo '<div class="wrap"><h1>' . esc_html__( 'Imajiner File Access', 'imajiner-editor' ) . '</h1>';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['imajiner_retention'] ) ) {
			$nonce = isset( $_POST['_imajiner_settings_nonce'] ) && is_string( $_POST['_imajiner_settings_nonce'] ) ? wp_unslash( $_POST['_imajiner_settings_nonce'] ) : '';
			$result = self::save_retention( ! empty( $_POST['remove_history'] ), $nonce );
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403 ) );
			}
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Retention setting saved.', 'imajiner-editor' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( $url ) . '">';
		wp_nonce_field( 'imajiner_file_settings', '_imajiner_settings_nonce' );
		echo '<input type="hidden" name="imajiner_retention" value="1"><label><input type="checkbox" name="remove_history" value="1" ' . checked( get_option( self::RETENTION_OPTION, false ), true, false ) . '> ' . esc_html__( 'Remove preview cache and template revisions when uninstalling. Keep client PHP and CSS files.', 'imajiner-editor' ) . '</label>';
		submit_button( __( 'Save retention setting', 'imajiner-editor' ) );
		echo '</form><h2>' . esc_html__( 'Filesystem connection', 'imajiner-editor' ) . '</h2><p>' . esc_html__( 'Connections are encrypted, limited to this user, login session and child theme, and expire after ten minutes. Reconnect and retry when prompted. Nothing is sent to AI providers.', 'imajiner-editor' ) . '</p>';
		$is_connection_post = 'POST' === $_SERVER['REQUEST_METHOD'] && ! isset( $_POST['imajiner_retention'] );
		if ( $is_connection_post ) {
			$nonce = isset( $_POST['_imajiner_fs_nonce'] ) && is_string( $_POST['_imajiner_fs_nonce'] ) ? wp_unslash( $_POST['_imajiner_fs_nonce'] ) : '';
			if ( ! wp_verify_nonce( $nonce, 'imajiner_file_connect' ) ) {
				wp_die( esc_html__( 'The connection form expired. Reload and retry.', 'imajiner-editor' ), '', array( 'response' => 403 ) );
			}
		}
		$_POST['_imajiner_fs_nonce'] = wp_create_nonce( 'imajiner_file_connect' );
		$credentials = request_filesystem_credentials( $url, '', false, get_stylesheet_directory(), array( '_imajiner_fs_nonce' ) );
		if ( false !== $credentials ) {
			if ( true !== $credentials && ( ! function_exists( 'sodium_crypto_secretbox' ) || ! class_exists( 'Imajiner_Secrets' ) ) ) {
				echo '<p>' . esc_html__( 'Sodium encryption is required to retain a filesystem connection.', 'imajiner-editor' ) . '</p>';
			} elseif ( WP_Filesystem( $credentials, get_stylesheet_directory() ) ) {
				if ( is_array( $credentials ) ) {
					set_transient( self::credential_key(), Imajiner_Secrets::encrypt( wp_json_encode( $credentials ) ), self::CREDENTIAL_TTL );
				}
				Imajiner_Filesystem::reset();
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Filesystem connected. Return to the editor and retry.', 'imajiner-editor' ) . '</p></div>';
			} else {
				self::forget();
				// Core's backend error may contain connection details; show a generic error.
				request_filesystem_credentials( $url, '', true, get_stylesheet_directory(), array( '_imajiner_fs_nonce' ) );
			}
		}
		echo '</div>';
	}
}
