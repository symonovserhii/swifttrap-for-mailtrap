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
function swifttrap_mailtrap_should_use_bulk_stream( $category, $settings ) {
	// Use bulk stream for promotional emails.
	$bulk_categories = array( 'promotional' );

	// Allow custom logic to determine stream.
	$use_bulk = in_array( $category, $bulk_categories, true );
	$use_bulk = apply_filters( 'swifttrap_mailtrap_use_bulk_stream', $use_bulk, $category, array() );

	return $use_bulk;
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
function swifttrap_mailtrap_get_account_data( $settings ) {
	if ( empty( $settings['token'] ) ) {
		return new WP_Error( 'swifttrap_missing_token', __( 'Mailtrap API token is not set.', 'swifttrap-for-mailtrap' ) );
	}

	$cache_key = 'swifttrap_account_data';
	$cached    = get_transient( $cache_key );
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

	if ( ! is_array( $accounts ) || empty( $accounts ) ) {
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
function swifttrap_mailtrap_get_account_id( $settings ) {
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
function swifttrap_mailtrap_fetch_stats( $settings ) {
	$cache_key = 'swifttrap_mailtrap_stats';
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$account_data = swifttrap_mailtrap_get_account_data( $settings );
	if ( is_wp_error( $account_data ) ) {
		return $account_data;
	}

	$account_id = $account_data['id'];
	$team_name  = $account_data['name'];

	$billing          = array();
	$billing_response = wp_remote_get(
		sprintf( 'https://mailtrap.io/api/accounts/%d/billing/usage', $account_id ),
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
		if ( ! is_array( $billing ) ) {
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
function swifttrap_mailtrap_fetch_domains( $settings ) {
	$cache_key = 'swifttrap_domains';
	$cached    = get_transient( $cache_key );
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
function swifttrap_mailtrap_fetch_suppressions( $settings ) {
	$cache_key = 'swifttrap_suppressions';
	$cached    = get_transient( $cache_key );
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

	if ( ! is_array( $data ) ) {
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
		$reason = $item['reason'] ?? 'manual';
		if ( isset( $summary[ $reason ] ) ) {
			$summary[ $reason ]++;
		}
		$summary['total']++;

		$items[] = array(
			'email'      => $item['email'] ?? '',
			'reason'     => $reason,
			'created_at' => $item['created_at'] ?? '',
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
 * Get Mailtrap email log directory inside uploads.
 *
 * @return string|WP_Error Directory path or WP_Error when uploads are unavailable.
 */
function swifttrap_mailtrap_get_log_dir() {
	$uploads = wp_upload_dir( null, false );

	if ( ! empty( $uploads['error'] ) ) {
		return new WP_Error( 'swifttrap_uploads_unavailable', __( 'Upload directory is not available for Mailtrap logs.', 'swifttrap-for-mailtrap' ) );
	}

	$dir = trailingslashit( $uploads['basedir'] ) . 'swifttrap-for-mailtrap';

	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'swifttrap_log_dir_unwritable', __( 'Unable to create Mailtrap log directory.', 'swifttrap-for-mailtrap' ) );
	}

	if ( ! wp_is_writable( $dir ) ) {
		return new WP_Error( 'swifttrap_log_dir_unwritable', __( 'Mailtrap log directory is not writable.', 'swifttrap-for-mailtrap' ) );
	}

	// Protect log directory from direct web access.
	$wp_filesystem = swifttrap_mailtrap_filesystem();

	if ( $wp_filesystem ) {
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! $wp_filesystem->exists( $htaccess ) ) {
			$wp_filesystem->put_contents( $htaccess, "Deny from all\n", FS_CHMOD_FILE );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! $wp_filesystem->exists( $index ) ) {
			$wp_filesystem->put_contents( $index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}
	}

	return $dir;
}

/**
 * Get Mailtrap email log file path.
 *
 * @return string|WP_Error Path to log file or WP_Error when unavailable.
 */
function swifttrap_mailtrap_get_log_file() {
	$log_dir = swifttrap_mailtrap_get_log_dir();

	if ( is_wp_error( $log_dir ) ) {
		return $log_dir;
	}

	return trailingslashit( $log_dir ) . 'swifttrap-emails.log';
}

/**
 * Write email send entry to log file.
 *
 * @param array  $email_data Email information.
 * @param array  $response   API response data.
 * @param bool   $success    Whether send was successful.
 * @param string $category   Pre-computed email category.
 */
function swifttrap_mailtrap_log_email( $email_data, $response = array(), $success = false, $category = '' ) {
	$settings = swifttrap_mailtrap_get_settings();
	if ( empty( $settings['log_emails'] ) ) {
		return; // Logging disabled.
	}

	$log_file = swifttrap_mailtrap_get_log_file();

	if ( is_wp_error( $log_file ) ) {
		return;
	}

	$log_entry = array(
		'timestamp'   => current_time( 'mysql' ),
		'status'      => $success ? 'success' : 'failed',
		'to'          => $email_data['to'] ?? array(),
		'from'        => $email_data['from'] ?? '',
		'subject'     => $email_data['subject'] ?? '',
		'category'    => $category,
		'response'    => $response,
		'http_status' => $response['http_status'] ?? null,
		'message'     => $response['message'] ?? '',
	);

	$log_line = wp_json_encode( $log_entry ) . "\n";

	$wp_filesystem = swifttrap_mailtrap_filesystem();
	if ( $wp_filesystem && $wp_filesystem->is_writable( dirname( $log_file ) ) ) {
		$existing = $wp_filesystem->exists( $log_file ) ? $wp_filesystem->get_contents( $log_file ) : '';
		$wp_filesystem->put_contents( $log_file, $existing . $log_line, FS_CHMOD_FILE );
	}

	// Probabilistic cleanup: ~1% chance per log write (similar to PHP session GC).
	if ( wp_rand( 1, 100 ) === 1 ) {
		swifttrap_mailtrap_cleanup_logs();
	}
}

/**
 * Clean old email logs based on retention period.
 */
function swifttrap_mailtrap_cleanup_logs() {
	$settings       = swifttrap_mailtrap_get_settings();
	$retention_days = ! empty( $settings['log_retention_days'] ) ? (int) $settings['log_retention_days'] : 30;

	$log_file = swifttrap_mailtrap_get_log_file();

	if ( is_wp_error( $log_file ) ) {
		return;
	}

	$wp_filesystem = swifttrap_mailtrap_filesystem();
	if ( ! $wp_filesystem || ! $wp_filesystem->exists( $log_file ) || ! $wp_filesystem->is_readable( $log_file ) ) {
		return;
	}

	$cutoff_time = time() - ( $retention_days * DAY_IN_SECONDS );
	$contents    = $wp_filesystem->get_contents( $log_file );

	if ( false === $contents || '' === $contents ) {
		return;
	}

	$all_lines = explode( "\n", $contents );
	$lines     = array();

	foreach ( $all_lines as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}

		$entry = json_decode( $line, true );
		if ( ! is_array( $entry ) || empty( $entry['timestamp'] ) ) {
			$lines[] = $line;
			continue;
		}

		$entry_time = strtotime( $entry['timestamp'] );
		if ( $entry_time > $cutoff_time ) {
			$lines[] = $line;
		}
	}

	$wp_filesystem->put_contents( $log_file, implode( "\n", $lines ) . "\n", FS_CHMOD_FILE );
}

/**
 * Determine email category based on email content and subject.
 *
 * @param array $normalized Normalized email data.
 *
 * @return string Email category for Mailtrap.
 */
function swifttrap_mailtrap_detect_email_category( $normalized ) {
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
function swifttrap_mailtrap_get_email_category( $normalized ) {
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
 * Read email logs from file.
 *
 * @param int $limit Number of logs to retrieve.
 *
 * @return array Array of email log entries.
 */
function swifttrap_mailtrap_read_email_logs( $limit = 20, $offset = 0 ) {
	$log_file = swifttrap_mailtrap_get_log_file();

	if ( is_wp_error( $log_file ) ) {
		return array( 'entries' => array(), 'total' => 0 );
	}

	$wp_filesystem = swifttrap_mailtrap_filesystem();
	if ( ! $wp_filesystem || ! $wp_filesystem->exists( $log_file ) || ! $wp_filesystem->is_readable( $log_file ) ) {
		return array( 'entries' => array(), 'total' => 0 );
	}

	$contents = $wp_filesystem->get_contents( $log_file );
	if ( false === $contents || '' === trim( $contents ) ) {
		return array( 'entries' => array(), 'total' => 0 );
	}

	$all_lines = explode( "\n", $contents );

	// Parse all valid entries.
	$parsed = array();
	foreach ( $all_lines as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		$entry = json_decode( $line, true );
		if ( is_array( $entry ) ) {
			$parsed[] = $entry;
		}
	}

	$total = count( $parsed );

	if ( 0 === $total ) {
		return array( 'entries' => array(), 'total' => 0 );
	}

	// Newest first: reverse, then apply offset and limit.
	$reversed = array_reverse( $parsed );
	$entries  = array_slice( $reversed, $offset, $limit );

	return array( 'entries' => $entries, 'total' => $total );
}

/**
 * Compute email log statistics from the log file.
 *
 * @param int $days Number of days to analyze.
 *
 * @return array Stats array with totals, by_category, and daily_volume.
 */
function swifttrap_mailtrap_compute_log_stats( $days = 7 ) {
	$cache_key = 'swifttrap_log_stats_' . $days;
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$result = array(
		'total_sent'    => 0,
		'total_success' => 0,
		'total_failed'  => 0,
		'success_rate'  => 0,
		'by_category'   => array(),
		'daily_volume'  => array(),
	);

	// Pre-fill daily_volume with zeros for last N days.
	for ( $i = $days - 1; $i >= 0; $i-- ) {
		$date = wp_date( 'Y-m-d', strtotime( "-{$i} days" ) );
		$result['daily_volume'][ $date ] = 0;
	}

	$log_file = swifttrap_mailtrap_get_log_file();

	if ( is_wp_error( $log_file ) ) {
		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	$wp_filesystem = swifttrap_mailtrap_filesystem();
	if ( ! $wp_filesystem || ! $wp_filesystem->exists( $log_file ) || ! $wp_filesystem->is_readable( $log_file ) ) {
		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	$contents = $wp_filesystem->get_contents( $log_file );
	if ( false === $contents || '' === trim( $contents ) ) {
		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	$cutoff_time = strtotime( "-{$days} days" );
	$all_lines   = explode( "\n", $contents );

	foreach ( $all_lines as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}

		$entry = json_decode( $line, true );
		if ( ! is_array( $entry ) || empty( $entry['timestamp'] ) ) {
			continue;
		}

		$entry_time = strtotime( $entry['timestamp'] );
		if ( $entry_time < $cutoff_time ) {
			continue;
		}

		$result['total_sent']++;

		if ( 'success' === ( $entry['status'] ?? '' ) ) {
			$result['total_success']++;
		} else {
			$result['total_failed']++;
		}

		// Category counts.
		$category = ! empty( $entry['category'] ) ? $entry['category'] : 'uncategorized';
		if ( ! isset( $result['by_category'][ $category ] ) ) {
			$result['by_category'][ $category ] = 0;
		}
		$result['by_category'][ $category ]++;

		// Daily volume.
		$date = wp_date( 'Y-m-d', $entry_time );
		if ( isset( $result['daily_volume'][ $date ] ) ) {
			$result['daily_volume'][ $date ]++;
		}
	}

	// Sort categories by count descending.
	arsort( $result['by_category'] );

	// Calculate success rate.
	if ( $result['total_sent'] > 0 ) {
		$result['success_rate'] = round( ( $result['total_success'] / $result['total_sent'] ) * 100, 1 );
	}

	set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

	return $result;
}

/**
 * AJAX handler: send test email.
 */
function swifttrap_mailtrap_ajax_send_test_email() {
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
 * AJAX handler: clear email logs.
 */
function swifttrap_mailtrap_ajax_clear_logs() {
	check_ajax_referer( 'swifttrap_clear_logs', '_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'swifttrap-for-mailtrap' ) ) );
	}

	$log_file = swifttrap_mailtrap_get_log_file();

	if ( is_wp_error( $log_file ) ) {
		wp_send_json_error( array( 'message' => $log_file->get_error_message() ) );
	}

	$wp_filesystem = swifttrap_mailtrap_filesystem();
	if ( $wp_filesystem && $wp_filesystem->exists( $log_file ) ) {
		$wp_filesystem->put_contents( $log_file, '', FS_CHMOD_FILE );
	}

	// Invalidate log stats cache.
	delete_transient( 'swifttrap_log_stats_7' );

	wp_send_json_success( array( 'message' => __( 'Email logs cleared.', 'swifttrap-for-mailtrap' ) ) );
}
add_action( 'wp_ajax_swifttrap_clear_logs', 'swifttrap_mailtrap_ajax_clear_logs' );

/**
 * AJAX handler: load all Mailtrap API data for Stats page.
 *
 * Returns stats, domains, and suppressions in one response so the page
 * can render immediately with placeholders while data loads asynchronously.
 *
 * @since 2.2.0
 */
function swifttrap_mailtrap_ajax_load_api_data() {
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
