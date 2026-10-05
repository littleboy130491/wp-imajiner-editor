<?php
/** User/session locks for site-wide template editing. */
defined( 'ABSPATH' ) || exit;

class Imajiner_Editor_Locks {
	const TTL = 120;

	public static function init() {
		return true;
	}

	private static function key( $template ) {
		return 'imajiner_lock_' . md5( get_stylesheet() . ':' . $template );
	}

	private static function owner() {
		$cookie = wp_parse_auth_cookie( '', 'logged_in' );
		$token = is_array( $cookie ) && ! empty( $cookie['token'] ) ? $cookie['token'] : wp_get_session_token();
		return hash( 'sha256', get_current_user_id() . ':' . $token );
	}

	/** Writes respect existing editing sessions without opening an editor themselves. */
	public static function check( $template ) {
		$lock = get_option( self::key( $template ) );
		return $lock && $lock['expires'] > time() && $lock['owner'] !== self::owner()
			? new WP_Error( 'imajiner_locked', __( 'Another editing session holds this template.', 'imajiner-editor' ), array( 'status' => 423 ) ) : true;
	}

	/** Compare-and-swap prevents an expired lock deleting a newer owner's lock. */
	private static function remove( $key, $lock ) {
		global $wpdb;
		$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, maybe_serialize( $lock ) ) );
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return (bool) $removed;
	}

	public static function acquire( $template ) {
		$key = self::key( $template );
		$lock = get_option( $key );
		$owner = self::owner();
		if ( $lock && $lock['expires'] > time() && $lock['owner'] !== $owner ) {
			return new WP_Error( 'imajiner_locked', __( 'Another editing session holds this template. Wait for its lock to expire or ask them to close the editor.', 'imajiner-editor' ), array( 'status' => 423, 'expires' => $lock['expires'] ) );
		}
		$new = array( 'owner' => $owner, 'user' => get_current_user_id(), 'expires' => time() + self::TTL );
		if ( $lock && $lock['owner'] === $owner && $lock['expires'] > time() ) {
			global $wpdb;
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $new ), $key, maybe_serialize( $lock ) ) );
			wp_cache_delete( $key, 'options' );
			if ( $changed || ( $new === $lock && get_option( $key ) === $new ) ) {
				return array( 'expires' => $new['expires'] );
			}
			return new WP_Error( 'imajiner_locked', __( 'The editing lock changed. Try again.', 'imajiner-editor' ), array( 'status' => 423 ) );
		}
		if ( $lock ) {
			self::remove( $key, $lock );
		}
		if ( ! add_option( $key, $new, '', false ) ) {
			return new WP_Error( 'imajiner_locked', __( 'Another session acquired this template.', 'imajiner-editor' ), array( 'status' => 423 ) );
		}
		return array( 'expires' => $new['expires'] );
	}

	public static function release( $template ) {
		$key = self::key( $template );
		$lock = get_option( $key );
		if ( $lock && $lock['owner'] === self::owner() ) {
			self::remove( $key, $lock );
		}
		return true;
	}
}
