<?php
defined( 'ABSPATH' ) || exit;

class Imajiner_Editor_Locks {
	const TTL = 300;
	public static function init() {}

	private static function key( $template ) {
		return 'imajiner_lock_' . md5( get_stylesheet() . '|' . $template );
	}

	private static function owner( $token ) {
		if ( null !== $token && ( ! is_string( $token ) || ! preg_match( '/^[a-zA-Z0-9-]{16,80}$/', $token ) ) ) {
			return new WP_Error( 'imajiner_lock_token', __( 'Invalid editing session.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}
		return hash( 'sha256', get_current_user_id() . '|' . wp_get_session_token() . '|' . ( $token ?: 'default' ) );
	}

	public static function acquire( $template, $token = null ) {
		$owner = self::owner( $token );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$key = self::key( $template );
		$lock = get_option( $key );
		if ( $lock && $lock['expires'] <= time() ) {
			self::remove( $key, $lock );
			$lock = false;
		}
		if ( $lock && ( (int) $lock['user'] !== get_current_user_id() || ! hash_equals( $lock['owner'], $owner ) ) ) {
			return new WP_Error( 'imajiner_locked', __( 'Another editing session owns this template. Wait for it to close or for the lock to expire.', 'imajiner-editor' ), array( 'status' => 423, 'expires' => $lock['expires'] ) );
		}
		$next = array( 'user' => get_current_user_id(), 'owner' => $owner, 'expires' => time() + self::TTL );
		if ( $lock ) {
			global $wpdb;
			$updated = $wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $next ) ), array( 'option_name' => $key, 'option_value' => maybe_serialize( $lock ) ) );
			wp_cache_delete( $key, 'options' );
			if ( false === $updated ) {
				return new WP_Error( 'imajiner_lock_write', __( 'Could not renew the editing lock.', 'imajiner-editor' ), array( 'status' => 503 ) );
			}
			if ( 0 === $updated && get_option( $key ) !== $next ) {
				return self::acquire( $template, $token );
			}
		} elseif ( ! add_option( $key, $next, '', false ) ) {
			return new WP_Error( 'imajiner_locked', __( 'The template was just opened in another session.', 'imajiner-editor' ), array( 'status' => 423 ) );
		}
		return array( 'expires' => $next['expires'] );
	}

	public static function release( $template, $token = null ) {
		$owner = self::owner( $token );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$key = self::key( $template );
		$lock = get_option( $key );
		if ( $lock && ( (int) $lock['user'] !== get_current_user_id() || ! hash_equals( $lock['owner'], $owner ) ) ) {
			return new WP_Error( 'imajiner_locked', __( 'This editing lock belongs to another session.', 'imajiner-editor' ), array( 'status' => 423 ) );
		}
		if ( $lock ) {
			self::remove( $key, $lock );
		}
		return true;
	}

	private static function remove( $key, array $lock ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key, 'option_value' => maybe_serialize( $lock ) ) );
		wp_cache_delete( $key, 'options' );
	}
}
