<?php
/**
 * SwiftTrap for Mailtrap webhook handler.
 *
 * @package SwiftTrapForMailtrap
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'swifttrap_mailtrap_register_webhook_route' );

/**
 * Register webhook REST route.
 */
function swifttrap_mailtrap_register_webhook_route(): void {
	register_rest_route(
		'swifttrap/v1',
		'/webhook',
		array(
			'methods'             => 'POST',
			'callback'            => 'swifttrap_mailtrap_handle_webhook_request',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Handle Mailtrap webhook event post request.
 *
 * @param WP_REST_Request $request REST request object.
 *
 * @return WP_REST_Response|WP_Error
 */
function swifttrap_mailtrap_handle_webhook_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$settings          = swifttrap_mailtrap_get_settings();
	$configured_secret = $settings['webhook_secret'] ?? '';

	if ( empty( $configured_secret ) ) {
		return new WP_Error( 'swifttrap_webhook_disabled', __( 'Webhook secret is not configured.', 'swifttrap-for-mailtrap' ), array( 'status' => 403 ) );
	}

	$signature_header = $request->get_header( 'Mailtrap-Signature' );
	$raw_body         = $request->get_body();

	if ( empty( $signature_header ) ) {
		return new WP_Error( 'swifttrap_webhook_unauthorized', __( 'Missing webhook signature.', 'swifttrap-for-mailtrap' ), array( 'status' => 401 ) );
	}

	// Mailtrap signs webhooks with HMAC-SHA256 over the raw request body, sent as a
	// hex-encoded Mailtrap-Signature header — not a bare shared-secret header.
	$computed_signature = hash_hmac( 'sha256', $raw_body, $configured_secret );

	if ( ! hash_equals( $computed_signature, $signature_header ) ) {
		return new WP_Error( 'swifttrap_webhook_unauthorized', __( 'Invalid webhook signature.', 'swifttrap-for-mailtrap' ), array( 'status' => 401 ) );
	}

	$payload = json_decode( $raw_body, true );
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'swifttrap_webhook_invalid_payload', __( 'Invalid JSON payload.', 'swifttrap-for-mailtrap' ), array( 'status' => 400 ) );
	}

	// Mailtrap wraps events as { "events": [ ... ] }; fall back to treating the payload
	// itself as a single event for forward-compatibility with alternate delivery formats.
	$events = isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : array( $payload );

	foreach ( $events as $event ) {
		if ( empty( $event['message_id'] ) || empty( $event['event'] ) ) {
			continue;
		}
		do_action( 'swifttrap_mailtrap_webhook_event', $event );
	}

	return new WP_REST_Response( array( 'success' => true, 'count' => count( $events ) ), 200 );
}
