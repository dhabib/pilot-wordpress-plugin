<?php
/**
 * REST route and HMAC signature verification for Pilot WMS webhooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pilot_Webhook {

	/** @var Pilot_Post_Handler */
	private $post_handler;

	/** Max age of a webhook timestamp in seconds (5 minutes). */
	const MAX_TIMESTAMP_AGE = 300;

	public function __construct( Pilot_Post_Handler $post_handler ) {
		$this->post_handler = $post_handler;
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route() {
		register_rest_route( 'pilot/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_request' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Main webhook handler.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_request( WP_REST_Request $request ) {
		$secret = get_option( 'pilot_wms_webhook_secret' );
		if ( empty( $secret ) ) {
			return new WP_Error(
				'pilot_wms_no_secret',
				'Webhook secret is not configured.',
				array( 'status' => 500 )
			);
		}

		// Verify signature.
		$timestamp = $request->get_header( 'X-Pilot-Timestamp' );
		$signature = $request->get_header( 'X-Pilot-Signature' );

		if ( empty( $timestamp ) || empty( $signature ) ) {
			return new WP_Error(
				'pilot_wms_missing_headers',
				'Missing required signature headers.',
				array( 'status' => 401 )
			);
		}

		// Replay protection: reject if timestamp is more than 5 minutes old.
		$ts_int = (int) $timestamp;
		if ( abs( time() - $ts_int ) > self::MAX_TIMESTAMP_AGE ) {
			return new WP_Error(
				'pilot_wms_stale_timestamp',
				'Webhook timestamp is too old.',
				array( 'status' => 401 )
			);
		}

		// Compute expected signature: HMAC-SHA256 of "timestamp.body".
		$raw_body = $request->get_body();
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error(
				'pilot_wms_invalid_signature',
				'Webhook signature verification failed.',
				array( 'status' => 401 )
			);
		}

		// Parse payload.
		$payload = $request->get_json_params();
		if ( empty( $payload ) || empty( $payload['event'] ) ) {
			return new WP_Error(
				'pilot_wms_bad_payload',
				'Invalid or empty payload.',
				array( 'status' => 400 )
			);
		}

		$event = sanitize_text_field( $payload['event'] );

		switch ( $event ) {
			case 'test':
				return new WP_REST_Response( array(
					'status'  => 'ok',
					'message' => 'Pilot WMS webhook is configured and working.',
				), 200 );

			case 'content.published':
				return $this->post_handler->handle_publish( $payload );

			case 'content.updated':
				return $this->post_handler->handle_update( $payload );

			case 'content.unpublished':
				return $this->post_handler->handle_unpublish( $payload );

			default:
				return new WP_Error(
					'pilot_wms_unknown_event',
					'Unknown event type: ' . $event,
					array( 'status' => 400 )
				);
		}
	}
}
