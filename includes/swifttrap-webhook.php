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

	$header_secret = $request->get_header( 'X-Mailtrap-Secret' );

	if ( ! hash_equals( $configured_secret, (string) $header_secret ) ) {
		return new WP_Error( 'swifttrap_webhook_unauthorized', __( 'Unauthorized secret token.', 'swifttrap-for-mailtrap' ), array( 'status' => 401 ) );
	}

	$payload = json_decode( $request->get_body(), true );
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'swifttrap_webhook_invalid_payload', __( 'Invalid JSON payload.', 'swifttrap-for-mailtrap' ), array( 'status' => 400 ) );
	}

	// Webhook payload can be an array of events or a single event.
	$events = isset( $payload[0] ) ? $payload : array( $payload );

	foreach ( $events as $event ) {
		if ( empty( $event['message_id'] ) || empty( $event['event'] ) ) {
			continue;
		}
		do_action( 'swifttrap_mailtrap_webhook_event', $event );
	}

	return new WP_REST_Response( array( 'success' => true, 'count' => count( $events ) ), 200 );
}
