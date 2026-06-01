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
		$reason = $item['reason'] ?? 'manual';
		if ( isset( $summary[ $reason ] ) ) {
			$summary[ $reason ]++;
		}
		$summary['total']++;

		$items[] = array(
			'id'         => $item['id'] ?? '',
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
function swifttrap_mailtrap_get_log_dir(): string|WP_Error {
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
function swifttrap_mailtrap_get_log_file(): string|WP_Error {
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
function swifttrap_mailtrap_log_email( array $email_data, array $response = array(), bool $success = false, string $category = '' ): void {
	$settings = swifttrap_mailtrap_get_settings();
	if ( empty( $settings['log_emails'] ) ) {
		return; // Logging disabled.
	}

	$log_file = swifttrap_mailtrap_get_log_file();

	if ( is_wp_error( $log_file ) ) {
		return;
	}

	$log_entry = array(
		'id'           => wp_generate_uuid4(),
		'timestamp'    => current_time( 'mysql' ),
		'status'       => $success ? 'success' : 'failed',
		'to'           => $email_data['to'] ?? array(),
		'from'         => $email_data['from'] ?? '',
		'subject'      => $email_data['subject'] ?? '',
		'body'         => $email_data['message'] ?? '',
		'headers'      => $email_data['headers'] ?? array(),
		'content_type' => $email_data['content_type'] ?? 'text/plain',
		'category'     => $category,
		'response'     => $response,
		'http_status'  => $response['http_status'] ?? null,
		'message'      => $response['message'] ?? '',
		'message_ids'  => $response['message_ids'] ?? array(),
	);

	$log_line = wp_json_encode( $log_entry ) . "\n";

	$wp_filesystem = swifttrap_mailtrap_filesystem();
	if ( $wp_filesystem && $wp_filesystem->is_writable( dirname( $log_file ) ) ) {
		$existing = $wp_filesystem->exists( $log_file ) ? $wp_filesystem->get_contents( $log_file ) : '';
		$wp_filesystem->put_contents( $log_file, $existing . $log_line, FS_CHMOD_FILE );
	}
}

/**
 * Clean old email logs based on retention period.
 */
function swifttrap_mailtrap_cleanup_logs(): void {
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
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $entry ) || empty( $entry['timestamp'] ) ) {
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
 * Read email logs from file.
 *
 * @param int $limit Number of logs to retrieve.
 *
 * @return array Array of email log entries.
 */
function swifttrap_mailtrap_read_email_logs( int $limit = 20, int $offset = 0, array $filters = array() ): array {
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
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $entry ) ) {
			// Apply filters
			if ( ! empty( $filters['search'] ) ) {
				$search = strtolower( $filters['search'] );
				$to_emails = array();
				if ( ! empty( $entry['to'] ) && is_array( $entry['to'] ) ) {
					foreach ( $entry['to'] as $t ) {
						$to_emails[] = $t['email'] ?? '';
						$to_emails[] = $t['name'] ?? '';
					}
				}
				$to_string = implode( ' ', $to_emails );
				$subject = $entry['subject'] ?? '';
				if (
					str_contains( strtolower( $subject ), $search ) === false &&
					str_contains( strtolower( $to_string ), $search ) === false
				) {
					continue;
				}
			}

			if ( ! empty( $filters['category'] ) ) {
				$cat = $entry['category'] ?? 'uncategorized';
				if ( strcasecmp( $cat, $filters['category'] ) !== 0 ) {
					continue;
				}
			}

			if ( ! empty( $filters['status'] ) ) {
				$status = $entry['status'] ?? '';
				if ( strcasecmp( $status, $filters['status'] ) !== 0 ) {
					continue;
				}
			}

			if ( ! empty( $filters['date'] ) ) {
				$entry_date = wp_date( 'Y-m-d', strtotime( $entry['timestamp'] ) );
				if ( $entry_date !== $filters['date'] ) {
					continue;
				}
			}

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
function swifttrap_mailtrap_compute_log_stats( int $days = 7 ): array {
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
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $entry ) || empty( $entry['timestamp'] ) ) {
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
 * AJAX handler: clear email logs.
 */
function swifttrap_mailtrap_ajax_clear_logs(): void {
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
 * Find matching log row by message ID and update its status.
 *
 * @param string $message_id Message UUID from Mailtrap.
 * @param string $status     New delivery status.
 *
 * @return bool True if log entry updated, false otherwise.
 */
function swifttrap_mailtrap_update_log_status( string $message_id, string $status ): bool {
	$log_file = swifttrap_mailtrap_get_log_file();

	if ( is_wp_error( $log_file ) ) {
		return false;
	}

	$wp_filesystem = swifttrap_mailtrap_filesystem();
	if ( ! $wp_filesystem || ! $wp_filesystem->exists( $log_file ) || ! $wp_filesystem->is_writable( $log_file ) ) {
		return false;
	}

	$contents = $wp_filesystem->get_contents( $log_file );
	if ( false === $contents || '' === trim( $contents ) ) {
		return false;
	}

	$all_lines = explode( "\n", $contents );
	$updated   = false;
	$lines     = array();

	foreach ( $all_lines as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}

		$entry = json_decode( $line, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $entry ) ) {
			$lines[] = $line;
			continue;
		}

		// Look for message_id inside message_ids array or as single field.
		$match = false;
		if ( ! empty( $entry['message_ids'] ) && is_array( $entry['message_ids'] ) ) {
			if ( in_array( $message_id, $entry['message_ids'], true ) ) {
				$match = true;
			}
		} elseif ( ! empty( $entry['response']['message_ids'] ) && is_array( $entry['response']['message_ids'] ) ) {
			if ( in_array( $message_id, $entry['response']['message_ids'], true ) ) {
				$match = true;
			}
		}

		if ( $match ) {
			$entry['status'] = $status;
			$lines[]         = wp_json_encode( $entry );
			$updated         = true;
		} else {
			$lines[] = $line;
		}
	}

	if ( $updated ) {
		$wp_filesystem->put_contents( $log_file, implode( "\n", $lines ) . "\n", FS_CHMOD_FILE );
	}

	return $updated;
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

/**
 * AJAX handler: get log details for modal viewer.
 */
function swifttrap_mailtrap_ajax_get_log_details(): void {
	check_ajax_referer( 'swifttrap_get_log_details', '_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'swifttrap-for-mailtrap' ) ) );
	}

	$log_id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
	if ( empty( $log_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid log ID.', 'swifttrap-for-mailtrap' ) ) );
	}

	$log_result = swifttrap_mailtrap_read_email_logs( 9999, 0 );
	$entry      = null;
	foreach ( $log_result['entries'] as $e ) {
		if ( isset( $e['id'] ) && $e['id'] === $log_id ) {
			$entry = $e;
			break;
		}
	}

	if ( ! $entry ) {
		wp_send_json_error( array( 'message' => __( 'Log entry not found.', 'swifttrap-for-mailtrap' ) ) );
	}

	wp_send_json_success(
		array(
			'subject'      => $entry['subject'] ?? '',
			'body'         => $entry['body'] ?? '',
			'content_type' => $entry['content_type'] ?? 'text/plain',
			'response'     => $entry['response'] ?? array(),
		)
	);
}
add_action( 'wp_ajax_swifttrap_get_log_details', 'swifttrap_mailtrap_ajax_get_log_details' );

/**
 * AJAX handler: resend a failed/logged email.
 */
function swifttrap_mailtrap_ajax_resend_email(): void {
	check_ajax_referer( 'swifttrap_resend_email', '_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'swifttrap-for-mailtrap' ) ) );
	}

	$log_id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
	if ( empty( $log_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid log ID.', 'swifttrap-for-mailtrap' ) ) );
	}

	$log_result = swifttrap_mailtrap_read_email_logs( 9999, 0 );
	$entry      = null;
	foreach ( $log_result['entries'] as $e ) {
		if ( isset( $e['id'] ) && $e['id'] === $log_id ) {
			$entry = $e;
			break;
		}
	}

	if ( ! $entry ) {
		wp_send_json_error( array( 'message' => __( 'Log entry not found.', 'swifttrap-for-mailtrap' ) ) );
	}

	$to_emails = array();
	if ( ! empty( $entry['to'] ) && is_array( $entry['to'] ) ) {
		foreach ( $entry['to'] as $to ) {
			$to_emails[] = ! empty( $to['name'] ) ? "{$to['name']} <{$to['email']}>" : $to['email'];
		}
	}

	$headers = array();
	if ( ! empty( $entry['headers'] ) && is_array( $entry['headers'] ) ) {
		foreach ( $entry['headers'] as $name => $val ) {
			$headers[] = "{$name}: {$val}";
		}
	}

	if ( ! empty( $entry['content_type'] ) ) {
		$headers[] = "Content-Type: {$entry['content_type']}";
	}

	$subject = $entry['subject'] ?? '';
	$body    = $entry['body'] ?? '';

	$result = wp_mail( $to_emails, $subject, $body, $headers );

	if ( $result ) {
		wp_send_json_success( array( 'message' => __( 'Email resent successfully.', 'swifttrap-for-mailtrap' ) ) );
	} else {
		wp_send_json_error( array( 'message' => __( 'Failed to resend email.', 'swifttrap-for-mailtrap' ) ) );
	}
}
add_action( 'wp_ajax_swifttrap_resend_email', 'swifttrap_mailtrap_ajax_resend_email' );
