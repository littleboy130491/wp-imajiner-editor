<?php
/** Private, durable background AI work. @package Imajiner_Editor */
defined( 'ABSPATH' ) || exit;

class Imajiner_AI_Jobs {

	const POST_TYPE = 'imajiner_ai_job';
	const TTL = 3600;
	const WORKER_LIMIT = 600;
	private static $active_job = 0;

	public static function init() {
		if ( did_action( 'init' ) ) {
			self::register_type();
		} else {
			add_action( 'init', array( __CLASS__, 'register_type' ) );
		}
		add_action( 'imajiner_ai_run_job', array( __CLASS__, 'run' ) );
		add_action( 'imajiner_ai_expire_job', array( __CLASS__, 'expire' ) );
		add_action( 'wp_ajax_imajiner_ai_dispatch', array( __CLASS__, 'dispatch' ) );
		add_action( 'wp_ajax_nopriv_imajiner_ai_dispatch', array( __CLASS__, 'dispatch' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'imajiner_ai_request_timeout', array( __CLASS__, 'request_timeout' ) );
	}

	public static function register_type() {
		register_post_type( self::POST_TYPE, array( 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'supports' => array(), 'can_export' => false, 'rewrite' => false, 'query_var' => false ) );
	}

	public static function register_routes() {
		foreach ( array( '' => 'GET', '/cancel' => 'POST' ) as $suffix => $method ) {
			register_rest_route( Imajiner_Rest::NAMESPACE_V1, '/ai/jobs/(?P<id>[0-9]+)' . $suffix, array(
				'methods' => $method,
				'callback' => array( __CLASS__, $suffix ? 'cancel' : 'status' ),
				'permission_callback' => array( 'Imajiner_Generation', 'can_generate' ),
			) );
		}
	}

	/** Handler filter: ($result, $payload, $id) => array|WP_Error. No credentials in payload. */
	public static function enqueue( $type, array $payload ) {
		if ( ! Imajiner_Generation::can_generate() || ! preg_match( '/^[a-z][a-z0-9_]{0,40}$/D', $type ) || ! has_filter( 'imajiner_ai_job_handler_' . $type ) ) {
			return new WP_Error( 'imajiner_job_forbidden', __( 'This AI job is not available.', 'imajiner-editor' ), array( 'status' => 403 ) );
		}
		$json = wp_json_encode( $payload );
		if ( false === $json || strlen( $json ) > 2000000 || preg_match( '/"(?:api_key|authorization|password|secret)"\s*:/i', $json ) ) {
			return new WP_Error( 'imajiner_job_payload', __( 'The AI job payload is invalid or too large.', 'imajiner-editor' ), array( 'status' => 400 ) );
		}
		$id = wp_insert_post( array( 'post_type' => self::POST_TYPE, 'post_status' => 'private', 'post_author' => get_current_user_id() ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$job = array( 'type' => $type, 'owner' => get_current_user_id(), 'theme' => get_stylesheet(), 'hash' => isset( $payload['hash'] ) ? $payload['hash'] : '', 'state' => 'queued', 'progress' => 0, 'created' => time(), 'expires' => time() + self::TTL );
		update_post_meta( $id, '_imajiner_job', $job );
		update_post_meta( $id, '_imajiner_payload', $payload );
		wp_schedule_single_event( time() + 1, 'imajiner_ai_run_job', array( $id ) );
		wp_schedule_single_event( $job['expires'], 'imajiner_ai_expire_job', array( $id ) );
		self::kick( $id );
		return array( 'id' => $id, 'state' => 'queued', 'progress' => 0, 'expires' => $job['expires'] );
	}

	private static function kick( $id ) {
		$expires = time() + 60;
		wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'timeout' => 0.01, 'blocking' => false, 'body' => array( 'action' => 'imajiner_ai_dispatch', 'id' => $id, 'expires' => $expires, 'signature' => self::signature( $id, $expires ) ) ) );
	}

	private static function signature( $id, $expires ) {
		return hash_hmac( 'sha256', $id . ':' . $expires, wp_salt( 'auth' ) );
	}

	public static function dispatch() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$expires = isset( $_POST['expires'] ) ? absint( $_POST['expires'] ) : 0;
		$signature = isset( $_POST['signature'] ) && is_string( $_POST['signature'] ) ? wp_unslash( $_POST['signature'] ) : '';
		if ( $expires < time() || $expires > time() + 60 || ! hash_equals( self::signature( $id, $expires ), $signature ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );
		self::run( $id );
		wp_die();
	}

	private static function job( $id ) {
		$post = get_post( $id );
		// Cancellation and watchdog updates can originate in another request.
		wp_cache_delete( $id, 'post_meta' );
		return $post && self::POST_TYPE === $post->post_type ? get_post_meta( $id, '_imajiner_job', true ) : null;
	}

	public static function run( $id ) {
		$job = self::job( $id );
		if ( is_array( $job ) && 'running' === $job['state'] ) {
			self::timeout( $id, $job );
			return;
		}
		if ( ! is_array( $job ) || 'queued' !== $job['state'] || time() >= $job['expires'] || ! add_option( 'imajiner_ai_worker_' . $id, time(), '', false ) ) {
			return;
		}
		$queued = $job;
		$job['state'] = 'running';
		$job['started'] = time();
		$job['progress'] = 10;
		if ( ! update_post_meta( $id, '_imajiner_job', $job, $queued ) ) {
			return;
		}
		$previous_user = get_current_user_id();
		$previous_job = self::$active_job;
		self::$active_job = $id;
		wp_set_current_user( $job['owner'] );
		wp_schedule_single_event( time() + self::WORKER_LIMIT, 'imajiner_ai_run_job', array( $id ) );
		try {
			@set_time_limit( self::WORKER_LIMIT );
			if ( $job['theme'] !== get_stylesheet() || ! Imajiner_Generation::can_generate() ) {
				$result = new WP_Error( 'imajiner_job_access', __( 'The theme or editing permissions changed.', 'imajiner-editor' ) );
			} else {
				$result = apply_filters( 'imajiner_ai_job_handler_' . $job['type'], null, get_post_meta( $id, '_imajiner_payload', true ), $id );
			}
			$current = self::job( $id );
			if ( is_array( $current ) ) {
				self::timeout( $id, $current );
				$current = self::job( $id );
			}
			if ( is_array( $current ) && 'running' === $current['state'] && time() < $current['expires'] ) {
				$finished = $current;
				$finished['state'] = is_wp_error( $result ) || ! is_array( $result ) ? 'failed' : 'complete';
				$finished['progress'] = 100;
				if ( 'complete' === $finished['state'] ) {
					update_post_meta( $id, '_imajiner_result', $result );
				} else {
					$finished['error'] = is_wp_error( $result ) ? sanitize_key( $result->get_error_code() ) : 'imajiner_job_handler';
				}
				if ( update_post_meta( $id, '_imajiner_job', $finished, $current ) ) {
					return;
				}
			}
			delete_post_meta( $id, '_imajiner_result' );
			self::discard( $job, $result );
		} catch ( Throwable $error ) {
			$current = self::job( $id );
			if ( is_array( $current ) && 'running' === $current['state'] ) {
				$previous = $current;
				$current['state'] = 'failed';
				$current['error'] = 'imajiner_job_exception';
				update_post_meta( $id, '_imajiner_job', $current, $previous );
			}
		} finally {
			delete_post_meta( $id, '_imajiner_payload' );
			wp_set_current_user( $previous_user );
			self::$active_job = $previous_job;
		}
	}

	/** Guard every provider request, including generic handler retries and fallbacks. */
	public static function request_timeout( $timeout ) {
		if ( ! self::$active_job || is_wp_error( $timeout ) ) {
			return $timeout;
		}
		$job = self::job( self::$active_job );
		if ( ! is_array( $job ) || 'running' !== $job['state'] ) {
			return new WP_Error( 'imajiner_job_stopped', __( 'The AI job stopped. No further provider requests were sent.', 'imajiner-editor' ) );
		}
		if ( $job['owner'] !== get_current_user_id() || $job['theme'] !== get_stylesheet() || ! Imajiner_Generation::can_generate() ) {
			return new WP_Error( 'imajiner_job_access', __( 'The theme or editing permissions changed.', 'imajiner-editor' ) );
		}
		$remaining = min( $job['expires'], $job['started'] + self::WORKER_LIMIT ) - time() - 5;
		if ( $remaining < 1 ) {
			return new WP_Error( 'imajiner_job_timeout', __( 'The AI job reached its time limit. Try again.', 'imajiner-editor' ) );
		}
		return min( $timeout, $remaining );
	}

	public static function progress( $id, $percent ) {
		$job = self::job( $id );
		if ( is_array( $job ) && 'running' === $job['state'] ) {
			$previous = $job;
			$job['progress'] = max( $job['progress'], min( 95, absint( $percent ) ) );
			update_post_meta( $id, '_imajiner_job', $job, $previous );
		}
	}

	private static function discard( array $job, $result ) {
		do_action( 'imajiner_ai_job_discarded', $job['type'], $result, $job['owner'] );
	}

	private static function timeout( $id, array $job ) {
		if ( 'running' === $job['state'] && time() >= $job['started'] + self::WORKER_LIMIT ) {
			$previous = $job;
			$job['state'] = 'failed';
			$job['error'] = 'imajiner_job_timeout';
			if ( update_post_meta( $id, '_imajiner_job', $job, $previous ) ) {
				delete_post_meta( $id, '_imajiner_payload' );
			}
		}
	}

	/** Acceptance must still belong to a completed, unexpired, uncancelled job. */
	public static function can_accept( $id ) {
		$job = self::owned( $id );
		return ! is_wp_error( $job ) && 'complete' === $job['state'];
	}

	private static function owned( $id ) {
		$job = self::job( $id );
		if ( ! is_array( $job ) || (int) $job['owner'] !== get_current_user_id() || $job['theme'] !== get_stylesheet() ) {
			return new WP_Error( 'imajiner_job_not_found', __( 'AI job not found.', 'imajiner-editor' ), array( 'status' => 404 ) );
		}
		if ( time() >= $job['expires'] ) {
			self::expire( $id );
			return new WP_Error( 'imajiner_job_expired', __( 'This AI job expired.', 'imajiner-editor' ), array( 'status' => 410 ) );
		}
		return $job;
	}

	public static function status( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$job = self::owned( $id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		self::timeout( $id, $job );
		$job = self::job( $id );
		$result = array( 'id' => $id, 'state' => $job['state'], 'progress' => $job['progress'], 'expires' => $job['expires'] );
		if ( 'complete' === $job['state'] ) {
			$result['result'] = get_post_meta( $id, '_imajiner_result', true );
		} elseif ( 'failed' === $job['state'] ) {
			$result['error'] = isset( $job['error'] ) ? $job['error'] : 'imajiner_job_failed';
			$result['message'] = __( 'AI generation failed. Try again or check your provider settings.', 'imajiner-editor' );
		}
		return rest_ensure_response( $result );
	}

	public static function cancel( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$job = self::owned( $id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$previous = $job;
		$job['state'] = 'cancelled';
		if ( ! update_post_meta( $id, '_imajiner_job', $job, $previous ) && 'cancelled' !== $previous['state'] ) {
			return new WP_Error( 'imajiner_job_changed', __( 'The AI job changed. Try cancelling again.', 'imajiner-editor' ), array( 'status' => 409 ) );
		}
		self::discard( $job, get_post_meta( $id, '_imajiner_result', true ) );
		delete_post_meta( $id, '_imajiner_payload' );
		delete_post_meta( $id, '_imajiner_result' );
		return rest_ensure_response( array( 'id' => $id, 'state' => 'cancelled' ) );
	}

	public static function expire( $id ) {
		$job = self::job( $id );
		if ( is_array( $job ) && time() >= $job['expires'] ) {
			self::discard( $job, get_post_meta( $id, '_imajiner_result', true ) );
			wp_delete_post( $id, true );
			delete_option( 'imajiner_ai_worker_' . $id );
			wp_clear_scheduled_hook( 'imajiner_ai_run_job', array( $id ) );
		}
	}
}
