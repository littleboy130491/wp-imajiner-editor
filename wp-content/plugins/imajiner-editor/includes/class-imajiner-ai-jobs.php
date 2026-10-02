<?php
/** Private, expiring AI work executed outside the initiating HTTP request. */
defined( 'ABSPATH' ) || exit;

class Imajiner_AI_Jobs {
	const POST_TYPE = 'imajiner_ai_job';
	const TTL = HOUR_IN_SECONDS;
	const WORK_LIMIT = 300;
	private static $locks = array();

	public static function init() {
		register_post_type( self::POST_TYPE, array( 'public' => false, 'show_ui' => false, 'can_export' => false, 'rewrite' => false ) );
		add_action( 'imajiner_ai_job_run', array( __CLASS__, 'run' ) );
		add_action( 'imajiner_ai_job_cleanup', array( __CLASS__, 'cleanup' ) );
		add_action( 'admin_post_imajiner_ai_worker', array( __CLASS__, 'dispatch' ) );
		add_action( 'admin_post_nopriv_imajiner_ai_worker', array( __CLASS__, 'dispatch' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( Imajiner_Rest::NAMESPACE_V1, '/ai/jobs/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'poll' ), 'permission_callback' => array( 'Imajiner_Generation', 'can_generate' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'cancel' ), 'permission_callback' => array( 'Imajiner_Generation', 'can_generate' ) ),
		) );
	}

	public static function enqueue( $type, array $payload ) {
		if ( ! Imajiner_Generation::can_generate() || ! has_filter( 'imajiner_ai_job_handler_' . $type ) || ! preg_match( '/^[a-z_]+$/', $type ) ) {
			return new WP_Error( 'imajiner_job_forbidden', __( 'This AI task is unavailable.', 'imajiner-editor' ), array( 'status' => 403 ) );
		}
		$id = wp_insert_post( array( 'post_type' => self::POST_TYPE, 'post_status' => 'private', 'post_author' => get_current_user_id(), 'post_title' => $type ), true );
		if ( is_wp_error( $id ) ) return $id;
		if ( ! update_post_meta( $id, '_imj_payload', wp_slash( $payload ) ) ) {
			wp_delete_post( $id, true );
			return new WP_Error( 'imajiner_job_failed', __( 'The AI task could not be stored.', 'imajiner-editor' ) );
		}
		if ( ! self::update_job( $id, array( 'type' => $type, 'stylesheet' => get_stylesheet(), 'theme_root' => get_stylesheet_directory(), 'hash' => isset( $payload['hash'] ) ? $payload['hash'] : '', 'state' => 'queued', 'progress' => 0, 'expires' => time() + self::TTL ) ) ) {
			wp_delete_post( $id, true );
			return new WP_Error( 'imajiner_job_failed', __( 'The AI task could not be stored.', 'imajiner-editor' ) );
		}
		wp_schedule_single_event( time() + 1, 'imajiner_ai_job_run', array( $id ) );
		wp_schedule_single_event( time() + self::TTL + 1, 'imajiner_ai_job_cleanup' );
		$expires = time() + 60;
		wp_remote_post( admin_url( 'admin-post.php' ), array( 'blocking' => false, 'timeout' => 0.01, 'body' => array( 'action' => 'imajiner_ai_worker', 'job' => $id, 'expires' => $expires, 'signature' => self::signature( $id, $expires ) ) ) );
		return array( 'job' => $id, 'state' => 'queued', 'progress' => 0 );
	}

	private static function signature( $id, $expires ) {
		return hash_hmac( 'sha256', $id . ':' . $expires, wp_salt( 'auth' ) );
	}

	public static function dispatch() {
		$id = isset( $_POST['job'] ) ? absint( $_POST['job'] ) : 0;
		$expires = isset( $_POST['expires'] ) ? absint( $_POST['expires'] ) : 0;
		$signature = isset( $_POST['signature'] ) && is_string( $_POST['signature'] ) ? wp_unslash( $_POST['signature'] ) : '';
		if ( $expires < time() || $expires > time() + 60 || ! hash_equals( self::signature( $id, $expires ), $signature ) ) {
			status_header( 403 );
			exit;
		}
		self::run( $id );
		exit;
	}

	public static function acquire( $key ) {
		global $wpdb;
		$name = 'imajiner_ai_lock_' . hash( 'sha256', $key );
		$previous = get_option( $name );
		if ( is_string( $previous ) && (int) $previous < time() ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name, 'option_value' => $previous ) );
			wp_cache_delete( $name, 'options' );
		}
		$value = ( time() + self::WORK_LIMIT + 60 ) . ':' . wp_generate_uuid4();
		if ( ! add_option( $name, $value, '', false ) ) return false;
		self::$locks[ $key ] = $value;
		return true;
	}

	public static function release( $key ) {
		global $wpdb;
		if ( ! isset( self::$locks[ $key ] ) ) return;
		$name = 'imajiner_ai_lock_' . hash( 'sha256', $key );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name, 'option_value' => self::$locks[ $key ] ) );
		wp_cache_delete( $name, 'options' );
		unset( self::$locks[ $key ] );
	}

	public static function run( $id ) {
		if ( ! self::acquire( 'job:' . $id ) ) return;
		$previous = get_current_user_id();
		try {
			$post = get_post( $id );
			$job = get_post_meta( $id, '_imj_job', true );
			if ( ! $post || self::POST_TYPE !== $post->post_type || ! is_array( $job ) || 'queued' !== $job['state'] ) return;
			wp_set_current_user( $post->post_author );
			if ( $job['expires'] <= time() || $job['stylesheet'] !== get_stylesheet() || $job['theme_root'] !== get_stylesheet_directory() || ! Imajiner_Generation::can_generate() ) {
				self::fail( $id, $job );
				return;
			}
			$queued = $job;
			$job['state'] = 'running';
			$job['started'] = time();
			$job['progress'] = 10;
			if ( ! self::update_job( $id, $job, $queued ) ) return;
			if ( function_exists( 'set_time_limit' ) ) set_time_limit( self::WORK_LIMIT );
			$result = apply_filters( 'imajiner_ai_job_handler_' . $job['type'], new WP_Error( 'imajiner_no_handler', __( 'AI task handler unavailable.', 'imajiner-editor' ) ), get_post_meta( $id, '_imj_payload', true ), $id );
			wp_cache_delete( $id, 'post_meta' );
			$current = get_post_meta( $id, '_imj_job', true );
			if ( ! is_array( $current ) || 'running' !== $current['state'] ) {
				do_action( 'imajiner_ai_job_discard_' . $job['type'], $result, $id );
				return;
			}
			if ( $job['expires'] <= time() || is_wp_error( $result ) ) {
				do_action( 'imajiner_ai_job_discard_' . $job['type'], $result, $id );
				self::fail( $id, $job, is_wp_error( $result ) ? $result->get_error_code() : 'imajiner_expired' );
				return;
			}
			$job['state'] = 'complete';
			$job['progress'] = 100;
			$job['result'] = $result instanceof WP_REST_Response ? $result->get_data() : $result;
			if ( ! self::update_job( $id, $job, $current ) ) do_action( 'imajiner_ai_job_discard_' . $job['type'], $result, $id );
			delete_post_meta( $id, '_imj_payload' );
		} catch ( Throwable $error ) {
			if ( isset( $job ) && is_array( $job ) ) self::fail( $id, $job );
		} finally {
			wp_set_current_user( $previous );
			self::release( 'job:' . $id );
		}
	}

	private static function update_job( $id, array $job, $previous = null ) {
		return null === $previous
			? update_post_meta( $id, '_imj_job', wp_slash( $job ) )
			: update_post_meta( $id, '_imj_job', wp_slash( $job ), wp_slash( $previous ) );
	}

	private static function fail( $id, array $job, $code = 'imajiner_job_failed' ) {
		wp_cache_delete( $id, 'post_meta' );
		$current = get_post_meta( $id, '_imj_job', true );
		if ( ! is_array( $current ) || ! in_array( $current['state'], array( 'queued', 'running' ), true ) ) return;
		$job['state'] = 'failed';
		$job['error'] = array( 'code' => sanitize_key( $code ), 'message' => __( 'AI task failed or expired. Check the settings and try again.', 'imajiner-editor' ) );
		unset( $job['result'] );
		self::update_job( $id, $job, $current );
		delete_post_meta( $id, '_imj_payload' );
	}

	private static function owned( $id ) {
		wp_cache_delete( $id, 'post_meta' );
		$post = get_post( $id );
		$job = get_post_meta( $id, '_imj_job', true );
		if ( ! $post || self::POST_TYPE !== $post->post_type || (int) $post->post_author !== get_current_user_id() || ! is_array( $job ) ) return new WP_Error( 'imajiner_job_not_found', __( 'AI task not found.', 'imajiner-editor' ), array( 'status' => 404 ) );
		if ( $job['expires'] <= time() ) return new WP_Error( 'imajiner_expired', __( 'AI task expired.', 'imajiner-editor' ), array( 'status' => 410 ) );
		if ( $job['stylesheet'] !== get_stylesheet() || $job['theme_root'] !== get_stylesheet_directory() ) return new WP_Error( 'imajiner_theme_changed', __( 'The active theme changed.', 'imajiner-editor' ), array( 'status' => 409 ) );
		return $job;
	}

	public static function poll( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$job = self::owned( $id );
		if ( is_wp_error( $job ) ) return $job;
		if ( 'running' === $job['state'] && $job['started'] + self::WORK_LIMIT < time() ) {
			self::fail( $id, $job );
			$job = get_post_meta( $id, '_imj_job', true );
		}
		if ( 'queued' === $job['state'] ) wp_schedule_single_event( time() + 1, 'imajiner_ai_job_run', array( $id ) );
		return rest_ensure_response( array_intersect_key( $job, array_flip( array( 'state', 'progress', 'result', 'error', 'expires' ) ) ) );
	}

	public static function cancel( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$job = self::owned( $id );
		if ( is_wp_error( $job ) ) return $job;
		if ( ! in_array( $job['state'], array( 'queued', 'running' ), true ) ) return rest_ensure_response( array( 'state' => $job['state'] ) );
		$previous = $job;
		$job['state'] = 'cancelled';
		unset( $job['result'] );
		if ( ! self::update_job( $id, $job, $previous ) ) return self::poll( $request );
		delete_post_meta( $id, '_imj_payload' );
		wp_clear_scheduled_hook( 'imajiner_ai_job_run', array( $id ) );
		return rest_ensure_response( array( 'state' => 'cancelled' ) );
	}

	public static function cleanup() {
		$ids = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'private', 'numberposts' => 100, 'fields' => 'ids', 'date_query' => array( array( 'before' => gmdate( 'Y-m-d H:i:s', time() - self::TTL ), 'column' => 'post_date_gmt', 'inclusive' => true ) ) ) );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
			delete_option( 'imajiner_ai_lock_' . hash( 'sha256', 'job:' . $id ) );
		}
		if ( count( $ids ) === 100 ) wp_schedule_single_event( time() + 5, 'imajiner_ai_job_cleanup' );
	}

	public static function progress( $id, $percent ) {
		$job = get_post_meta( $id, '_imj_job', true );
		if ( ! is_array( $job ) || 'running' !== $job['state'] ) return false;
		$previous = $job;
		$job['progress'] = max( $job['progress'], min( 95, max( 10, (int) $percent ) ) );
		return self::update_job( $id, $job, $previous );
	}
}
