<?php
/**
 * SwiftTrap for Mailtrap API integration, email logging, and statistics.
 *
 * All HTTP requests use the WordPress HTTP API (wp_remote_get/wp_remote_post).
 *
 * @package SwiftTrapForMailtrap
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determine if email should use bulk stream.
 *
 * @param string $category Email category.
 * @param array  $settings Plugin settings.
 *
 * @return bool Whether to use bulk stream.
 */
function swifttrap_mailtrap_should_use_bulk_stream( string $category, array $settings ): bool {
	$streams  = $settings['category_streams'] ?? array( 'promotional' => 'bulk' );
	$use_bulk = isset( $streams[ $category ] ) && 'bulk' === $streams[ $category ];

	return (bool) apply_filters( 'swifttrap_mailtrap_use_bulk_stream', $use_bulk, $category, $settings );
}

/**
 * Get Mailtrap account data (ID and name) with caching.
 *
 * @since 2.2.0
 *
 * @param array $settings Plugin settings.
 *
 * @return array|WP_Error Account data array ('id', 'name') or error.
 */
function swifttrap_mailtrap_get_account_data( array $settings ): array|WP_Error {
	if ( empty( $settings['token'] ) ) {
		return new WP_Error( 'swifttrap_missing_token', __( 'Mailtrap API token is not set.', 'swifttrap-for-mailtrap' ) );
	}

	$token_hash = substr( md5( $settings['token'] ), 0, 8 );
	$cache_key  = 'swifttrap_account_data_' . $token_hash;
	$cached     = get_transient( $cache_key );
	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	$response = wp_remote_get( 'https://mailtrap.io/api/accounts', array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $settings['token'],
			'Content-Type'  => 'application/json',
		),
		'timeout' => 10,
	) );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'swifttrap_account_failed', $response->get_error_message() );
	}

	$body     = wp_remote_retrieve_body( $response );
	$accounts = json_decode( $body, true );

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $accounts ) || empty( $accounts ) ) {
		return new WP_Error( 'swifttrap_account_failed', __( 'Unable to retrieve Mailtrap account info.', 'swifttrap-for-mailtrap' ) );
	}

	$account_id = isset( $accounts[0]['id'] ) ? (int) $accounts[0]['id'] : null;

	if ( ! $account_id ) {
		return new WP_Error( 'swifttrap_account_failed', __( 'Mailtrap account ID not found.', 'swifttrap-for-mailtrap' ) );
	}

	$data = array(
		'id'   => $account_id,
		'name' => $accounts[0]['name'] ?? '',
	);

	set_transient( $cache_key, $data, HOUR_IN_SECONDS );

	return $data;
}

/**
 * Get Mailtrap account ID with caching.
 *
 * @param array $settings Plugin settings.
 *
 * @return int|WP_Error Account ID or error.
 */
function swifttrap_mailtrap_get_account_id( array $settings ): int|WP_Error {
	$data = swifttrap_mailtrap_get_account_data( $settings );

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	return $data['id'];
}

/**
 * Fetch basic sending stats via Mailtrap HTTP API.
 *
 * @param array $settings Plugin settings.
 *
 * @return array|WP_Error
 */
function swifttrap_mailtrap_fetch_stats( array $settings ): array|WP_Error {
	$token_hash = substr( md5( $settings['token'] ?? '' ), 0, 8 );
	$cache_key  = 'swifttrap_mailtrap_stats_' . $token_hash;
	$cached     = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$account_data = swifttrap_mailtrap_get_account_data( $settings );
	if ( is_wp_error( $account_data ) ) {
		return $account_data;
	}

	$team_name = $account_data['name'];

	$billing          = array();
	$billing_response = wp_remote_get(
		'https://mailtrap.io/api/billing/usage',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $settings['token'],
				'Content-Type'  => 'application/json',
			),
			'timeout' => 10,
		)
	);

	if ( ! is_wp_error( $billing_response ) ) {
		$billing_body = wp_remote_retrieve_body( $billing_response );
		$billing      = json_decode( $billing_body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $billing ) ) {
			$billing = array();
		}
	}

	$sending       = $billing['sending'] ?? array();
	$sending_usage = $sending['usage'] ?? array();
	$sent_count    = $sending_usage['sent_messages_count'] ?? array();
	$testing       = $billing['testing'] ?? array();
	$testing_plan  = $testing['plan'] ?? array();

	$result = array(
		'plan'          => $sending['plan']['name'] ?? $testing_plan['name'] ?? '',
		'team'          => $team_name,
		'monthly_sent'  => $sent_count['current'] ?? null,
		'monthly_limit' => $sent_count['limit'] ?? null,
	);

	set_transient( $cache_key, $result, HOUR_IN_SECONDS );

	return $result;
}

/**
 * Fetch sending domains from Mailtrap API.
 *
 * @param array $settings Plugin settings.
 *
 * @return array|WP_Error List of domains or error.
 */
function swifttrap_mailtrap_fetch_domains( array $settings ): array|WP_Error {
	$token_hash = substr( md5( $settings['token'] ?? '' ), 0, 8 );
	$cache_key  = 'swifttrap_domains_' . $token_hash;
	$cached     = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$account_id = swifttrap_mailtrap_get_account_id( $settings );
	if ( is_wp_error( $account_id ) ) {
		return $account_id;
	}

	$response = wp_remote_get(
		sprintf( 'https://mailtrap.io/api/accounts/%d/sending_domains', $account_id ),
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $settings['token'],
				'Content-Type'  => 'application/json',
			),
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'swifttrap_domains_failed', $response->get_error_message() );
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	if ( $status_code < 200 || $status_code >= 300 ) {
		/* translators: %d: HTTP status code returned by the Mailtrap API */
		return new WP_Error( 'swifttrap_domains_failed', sprintf( __( 'Mailtrap API returned HTTP %d', 'swifttrap-for-mailtrap' ), $status_code ) );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		return new WP_Error( 'swifttrap_domains_failed', __( 'Invalid domains response.', 'swifttrap-for-mailtrap' ) );
	}
	$data = $body['data'] ?? $body;

	if ( ! is_array( $data ) ) {
		return new WP_Error( 'swifttrap_domains_failed', __( 'Invalid domains response.', 'swifttrap-for-mailtrap' ) );
	}

	$domains = array();
	foreach ( $data as $item ) {
		$dns = array();
		if ( ! empty( $item['dns_records'] ) && is_array( $item['dns_records'] ) ) {
			foreach ( $item['dns_records'] as $record ) {
				$key    = $record['key'] ?? $record['type'] ?? 'unknown';
				$status = $record['status'] ?? 'pending';
				$dns[ $key ] = $status;
			}
		}

		$domains[] = array(
			'id'         => $item['id'] ?? '',
			'name'       => $item['domain_name'] ?? $item['name'] ?? '',
			'verified'   => ! empty( $item['dns_verified'] ),
			'compliance' => $item['compliance_status'] ?? '',
			'dns'        => $dns,
		);
	}

	set_transient( $cache_key, $domains, HOUR_IN_SECONDS );

	return $domains;
}

/**
 * Fetch suppression list from Mailtrap API.
 *
 * @param array $settings Plugin settings.
 *
 * @return array|WP_Error Suppressions data with items and summary, or error.
 */
function swifttrap_mailtrap_fetch_suppressions( array $settings ): array|WP_Error {
	$token_hash = substr( md5( $settings['token'] ?? '' ), 0, 8 );
	$cache_key  = 'swifttrap_suppressions_' . $token_hash;
	$cached     = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$account_id = swifttrap_mailtrap_get_account_id( $settings );
	if ( is_wp_error( $account_id ) ) {
		return $account_id;
	}

	$response = wp_remote_get(
		sprintf( 'https://mailtrap.io/api/accounts/%d/suppressions', $account_id ),
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $settings['token'],
				'Content-Type'  => 'application/json',
			),
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'swifttrap_suppressions_failed', $response->get_error_message() );
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	if ( $status_code < 200 || $status_code >= 300 ) {
		/* translators: %d: HTTP status code returned by the Mailtrap API */
		return new WP_Error( 'swifttrap_suppressions_failed', sprintf( __( 'Mailtrap API returned HTTP %d', 'swifttrap-for-mailtrap' ), $status_code ) );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
		return new WP_Error( 'swifttrap_suppressions_failed', __( 'Invalid suppressions response.', 'swifttrap-for-mailtrap' ) );
	}

	$summary = array(
		'bounce'      => 0,
		'complaint'   => 0,
		'unsubscribe' => 0,
		'manual'      => 0,
		'total'       => 0,
	);

	$items = array();
	foreach ( $data as $item ) {
		$type_map = array(
			'hard bounce'    => 'bounce',
			'spam complaint' => 'complaint',
			'unsubscription' => 'unsubscribe',
			'manual import'  => 'manual',
		);
		$raw_type = $item['type'] ?? '';
		$reason   = $type_map[ $raw_type ] ?? 'manual';
		if ( isset( $summary[ $reason ] ) ) {
			$summary[ $reason ]++;
		}
		$summary['total']++;

		$created_at = $item['created_at'] ?? '';
		$items[] = array(
			'id'              => $item['id'] ?? '',
			'email'           => $item['email'] ?? '',
			'reason'          => $reason,
			'bounce_category' => $item['message_bounce_category'] ?? '',
			'created_at'      => $created_at,
			'created_at_fmt'  => $created_at ? wp_date( get_option( 'date_format' ), strtotime( $created_at ) ) : '',
		);
	}

	$result = array(
		'items'   => $items,
		'summary' => $summary,
	);

	set_transient( $cache_key, $result, HOUR_IN_SECONDS );

	return $result;
}


/**
 * Determine email category based on email content and subject.
 *
 * @param array $normalized Normalized email data.
 *
 * @return string Email category for Mailtrap.
 */
function swifttrap_mailtrap_detect_email_category( array $normalized ): string {
	$subject = strtolower( $normalized['subject'] );
	$message = strtolower( $normalized['message'] );

	// Detect common email types.
	if ( str_contains( $subject, 'confirm' ) || str_contains( $subject, 'verification' ) ) {
		return 'verification';
	}

	if ( str_contains( $subject, 'reset' ) || str_contains( $subject, 'password' ) ) {
		return 'password-reset';
	}

	if ( str_contains( $subject, 'welcome' ) || str_contains( $subject, 'sign up' ) ) {
		return 'welcome';
	}

	if ( str_contains( $subject, 'comment' ) || str_contains( $message, 'comment' ) ) {
		return 'notification';
	}

	if ( str_contains( $subject, 'order' ) || str_contains( $subject, 'invoice' ) ) {
		return 'transactional';
	}

	if ( str_contains( $subject, 'newsletter' ) || str_contains( $subject, 'promotion' ) ) {
		return 'promotional';
	}

	return 'general';
}

/**
 * Get email category for Mailtrap.
 *
 * @param array $normalized Normalized email data.
 *
 * @return string Category name.
 */
function swifttrap_mailtrap_get_email_category( array $normalized ): string {
	$settings = swifttrap_mailtrap_get_settings();

	if ( empty( $settings['enable_categories'] ) ) {
		return apply_filters( 'swifttrap_mailtrap_email_category', '', $normalized );
	}

	$category = '';

	if ( ! empty( $settings['auto_categorize'] ) ) {
		$category = swifttrap_mailtrap_detect_email_category( $normalized );
	} else {
		$category = 'general';
	}

	return apply_filters( 'swifttrap_mailtrap_email_category', $category, $normalized );
}

/**
 * Fetch email sending history from Mailtrap API.
 *
 * @param array $settings Plugin settings.
 * @param string $cursor  Pagination cursor (empty = first page).
 * @param array  $filters Optional: search, status, date_from, date_to.
 *
 * @return array|WP_Error Array with 'entries', 'total', 'next_cursor', or WP_Error.
 */
function swifttrap_mailtrap_fetch_emails( array $settings, string $cursor = '', array $filters = array() ): array|WP_Error {
	$base_params = array();
	if ( ! empty( $cursor ) ) {
		$base_params['search_after'] = $cursor;
	}

	// Build filter query parts with correct bracket notation.
	$filter_parts = array();
	if ( ! empty( $filters['search'] ) ) {
		$v = rawurlencode( sanitize_text_field( $filters['search'] ) );
		$filter_parts[] = 'filters%5Bto%5D%5Boperator%5D=contain&filters%5Bto%5D%5Bvalue%5D%5B%5D=' . $v;
	}
	if ( ! empty( $filters['status'] ) ) {
		$v = rawurlencode( sanitize_key( $filters['status'] ) );
		$filter_parts[] = 'filters%5Bstatus%5D%5Boperator%5D=equal&filters%5Bstatus%5D%5Bvalue%5D%5B%5D=' . $v;
	}
	if ( ! empty( $filters['date_from'] ) ) {
		$filter_parts[] = 'filters%5Bsent_after%5D=' . rawurlencode( sanitize_text_field( $filters['date_from'] ) . 'T00:00:00Z' );
	}
	if ( ! empty( $filters['date_to'] ) ) {
		$filter_parts[] = 'filters%5Bsent_before%5D=' . rawurlencode( sanitize_text_field( $filters['date_to'] ) . 'T23:59:59Z' );
	}

	$url = 'https://mailtrap.io/api/email_logs?' . http_build_query( $base_params );
	if ( ! empty( $filter_parts ) ) {
		$url .= '&' . implode( '&', $filter_parts );
	}

	$response = wp_remote_get( $url, array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $settings['token'],
			'Content-Type'  => 'application/json',
		),
		'timeout' => 15,
	) );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'swifttrap_emails_failed', $response->get_error_message() );
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	if ( $status_code < 200 || $status_code >= 300 ) {
		/* translators: %d: HTTP status code returned by the Mailtrap API */
		return new WP_Error( 'swifttrap_emails_failed', sprintf( __( 'Mailtrap API returned HTTP %d', 'swifttrap-for-mailtrap' ), $status_code ) );
	}

	$raw  = wp_remote_retrieve_body( $response );
	$body = json_decode( $raw, true );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
		/* translators: %s: raw API response snippet for debugging */
		return new WP_Error( 'swifttrap_emails_failed', sprintf( __( 'Invalid emails response: %s', 'swifttrap-for-mailtrap' ), substr( $raw, 0, 300 ) ) );
	}

	$entries     = $body['messages'] ?? array();
	$total       = (int) ( $body['total_count'] ?? count( $entries ) );
	$next_cursor = $body['next_page_cursor'] ?? null;

	return array(
		'entries'     => is_array( $entries ) ? $entries : array(),
		'total'       => $total,
		'next_cursor' => $next_cursor,
	);
}

/**
 * AJAX handler: send test email.
 */
function swifttrap_mailtrap_ajax_send_test_email(): void {
	check_ajax_referer( 'swifttrap_send_test_email', '_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'swifttrap-for-mailtrap' ) ) );
	}

	$settings = swifttrap_mailtrap_get_settings();

	if ( empty( $settings['enabled'] ) || empty( $settings['token'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Mailtrap is not enabled or API token is missing.', 'swifttrap-for-mailtrap' ) ) );
	}

	$to      = $settings['sender_email'];
	/* translators: %s: current date and time */
	$subject = sprintf( __( 'Mailtrap Test Email — %s', 'swifttrap-for-mailtrap' ), wp_date( 'Y-m-d H:i:s' ) );
	$message = __( 'This is a test email sent from the SwiftTrap for Mailtrap plugin. If you received this, your configuration is working correctly.', 'swifttrap-for-mailtrap' );

	$result = wp_mail( $to, $subject, $message );

	if ( $result ) {
		/* translators: %s: recipient email address */
		wp_send_json_success( array( 'message' => sprintf( __( 'Test email sent to %s', 'swifttrap-for-mailtrap' ), $to ) ) );
	} else {
		wp_send_json_error( array( 'message' => __( 'Failed to send test email. Check your settings.', 'swifttrap-for-mailtrap' ) ) );
	}
}
add_action( 'wp_ajax_swifttrap_send_test_email', 'swifttrap_mailtrap_ajax_send_test_email' );


/**
 * AJAX handler: load all Mailtrap API data for Stats page.
 *
 * Returns stats, domains, and suppressions in one response so the page
 * can render immediately with placeholders while data loads asynchronously.
 *
 * @since 2.2.0
 */
function swifttrap_mailtrap_ajax_load_api_data(): void {
	check_ajax_referer( 'swifttrap_load_api_data', '_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'swifttrap-for-mailtrap' ) ) );
	}

	$settings = swifttrap_mailtrap_get_settings();
	$data     = array();

	// Stats.
	$stats = swifttrap_mailtrap_fetch_stats( $settings );
	if ( is_wp_error( $stats ) ) {
		$data['stats'] = array( 'error' => $stats->get_error_message() );
	} else {
		$data['stats'] = $stats;
	}

	// Domains.
	$domains = swifttrap_mailtrap_fetch_domains( $settings );
	if ( is_wp_error( $domains ) ) {
		$data['domains'] = array( 'error' => $domains->get_error_message() );
	} else {
		$data['domains'] = $domains;
	}

	// Suppressions.
	$suppressions = swifttrap_mailtrap_fetch_suppressions( $settings );
	if ( is_wp_error( $suppressions ) ) {
		$data['suppressions'] = array( 'error' => $suppressions->get_error_message() );
	} else {
		$data['suppressions'] = $suppressions;
	}

	wp_send_json_success( $data );
}
add_action( 'wp_ajax_swifttrap_load_api_data', 'swifttrap_mailtrap_ajax_load_api_data' );

/**
 * AJAX handler: load email log entries from Mailtrap.
 */
function swifttrap_mailtrap_ajax_load_emails(): void {
	check_ajax_referer( 'swifttrap_load_emails', '_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'swifttrap-for-mailtrap' ) ) );
		return;
	}

	$settings = swifttrap_mailtrap_get_settings();
	$cursor   = sanitize_text_field( wp_unslash( $_POST['cursor'] ?? '' ) );

	$filters = array(
		'search'    => sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ),
		'status'    => sanitize_key( $_POST['status'] ?? '' ),
		'date_from' => sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) ),
		'date_to'   => sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) ),
	);

	$result = swifttrap_mailtrap_fetch_emails( $settings, $cursor, $filters );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		return;
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_swifttrap_load_emails', 'swifttrap_mailtrap_ajax_load_emails' );

/**
 * Add an email suppression to Mailtrap.
 *
 * @param array  $settings       Plugin settings.
 * @param string $email          Recipient email.
 * @param int    $domain_id      Domain ID.
 * @param string $sending_stream Send stream (transactional or bulk).
 *
 * @return bool|WP_Error
 */
function swifttrap_mailtrap_add_suppression( array $settings, string $email, int $domain_id, string $sending_stream ): bool|WP_Error {
	$account_id = swifttrap_mailtrap_get_account_id( $settings );
	if ( is_wp_error( $account_id ) ) {
		return $account_id;
	}

	$response = wp_remote_post(
		sprintf( 'https://mailtrap.io/api/accounts/%d/suppressions', $account_id ),
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $settings['token'],
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'email'          => $email,
				'domain_id'      => $domain_id,
				'sending_stream' => $sending_stream,
				'type'           => 'manual import',
			) ),
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	if ( $status_code < 200 || $status_code >= 300 ) {
		return new WP_Error( 'swifttrap_add_suppression_failed', sprintf( __( 'Mailtrap API returned HTTP %d', 'swifttrap-for-mailtrap' ), $status_code ) );
	}

	// Invalidate suppressions cache.
	$token_hash = substr( md5( $settings['token'] ?? '' ), 0, 8 );
	delete_transient( 'swifttrap_suppressions_' . $token_hash );

	return true;
}

/**
 * Remove an email suppression from Mailtrap.
 *
 * @param array  $settings       Plugin settings.
 * @param string $suppression_id Suppression UUID.
 *
 * @return bool|WP_Error
 */
function swifttrap_mailtrap_delete_suppression( array $settings, string $suppression_id ): bool|WP_Error {
	$account_id = swifttrap_mailtrap_get_account_id( $settings );
	if ( is_wp_error( $account_id ) ) {
		return $account_id;
	}

	$response = wp_remote_request(
		sprintf( 'https://mailtrap.io/api/accounts/%d/suppressions/%s', $account_id, $suppression_id ),
		array(
			'method'  => 'DELETE',
			'headers' => array(
				'Authorization' => 'Bearer ' . $settings['token'],
				'Content-Type'  => 'application/json',
			),
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	if ( $status_code < 200 || $status_code >= 300 ) {
		return new WP_Error( 'swifttrap_delete_suppression_failed', sprintf( __( 'Mailtrap API returned HTTP %d', 'swifttrap-for-mailtrap' ), $status_code ) );
	}

	// Invalidate suppressions cache.
	$token_hash = substr( md5( $settings['token'] ?? '' ), 0, 8 );
	delete_transient( 'swifttrap_suppressions_' . $token_hash );

	return true;
}

/**
 * Check if a recipient's email is in the suppressions list.
 *
 * @param string $email    Recipient email.
 * @param array  $settings Plugin settings.
 *
 * @return bool
 */
function swifttrap_mailtrap_is_recipient_suppressed( string $email, array $settings ): bool {
	$suppressions_data = swifttrap_mailtrap_fetch_suppressions( $settings );
	if ( is_wp_error( $suppressions_data ) || empty( $suppressions_data['items'] ) ) {
		return false;
	}

	foreach ( $suppressions_data['items'] as $item ) {
		if ( strcasecmp( $item['email'], $email ) === 0 ) {
			return true;
		}
	}

	return false;
}


/**
 * AJAX handler: add suppression.
 */
function swifttrap_mailtrap_ajax_add_suppression(): void {
	check_ajax_referer( 'swifttrap_add_suppression', '_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'swifttrap-for-mailtrap' ) ) );
	}

	$email          = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$domain_id      = isset( $_POST['domain_id'] ) ? (int) $_POST['domain_id'] : 0;
	$sending_stream = isset( $_POST['sending_stream'] ) ? sanitize_key( wp_unslash( $_POST['sending_stream'] ) ) : 'transactional';

	if ( empty( $email ) || ! is_email( $email ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid email address.', 'swifttrap-for-mailtrap' ) ) );
	}

	if ( empty( $domain_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid domain ID.', 'swifttrap-for-mailtrap' ) ) );
	}

	$settings = swifttrap_mailtrap_get_settings();
	$result   = swifttrap_mailtrap_add_suppression( $settings, $email, $domain_id, $sending_stream );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array( 'message' => __( 'Suppression added successfully.', 'swifttrap-for-mailtrap' ) ) );
}
add_action( 'wp_ajax_swifttrap_add_suppression', 'swifttrap_mailtrap_ajax_add_suppression' );

/**
 * AJAX handler: delete suppression.
 */
function swifttrap_mailtrap_ajax_delete_suppression(): void {
	check_ajax_referer( 'swifttrap_delete_suppression', '_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'swifttrap-for-mailtrap' ) ) );
	}

	$suppression_id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

	if ( empty( $suppression_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid suppression ID.', 'swifttrap-for-mailtrap' ) ) );
	}

	$settings = swifttrap_mailtrap_get_settings();
	$result   = swifttrap_mailtrap_delete_suppression( $settings, $suppression_id );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array( 'message' => __( 'Suppression removed successfully.', 'swifttrap-for-mailtrap' ) ) );
}
add_action( 'wp_ajax_swifttrap_delete_suppression', 'swifttrap_mailtrap_ajax_delete_suppression' );

