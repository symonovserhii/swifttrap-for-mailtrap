<?php
/**
 * Mailtrap API helper wrappers using official SDK.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determine if email should use bulk stream.
 *
 * @param array $normalized Normalized email data.
 * @param array $settings   Plugin settings.
 *
 * @return bool Whether to use bulk stream.
 */
function mailtrap_mailer_should_use_bulk_stream( $normalized, $settings ) {
	$category = mailtrap_mailer_get_email_category( $normalized );

	// Use bulk stream for promotional and newsletter emails.
	$bulk_categories = array( 'promotional' );

	// Allow custom logic to determine stream.
	$use_bulk = in_array( $category, $bulk_categories, true );
	$use_bulk = apply_filters( 'mailtrap_mailer_use_bulk_stream', $use_bulk, $category, $normalized );

	return $use_bulk;
}

/**
 * Fetch basic sending stats.
 *
 * @param array $settings Plugin settings.
 *
 * @return array|WP_Error
 */
function mailtrap_mailer_fetch_stats( $settings ) {
	if ( empty( $settings['token'] ) ) {
		return new WP_Error( 'mailtrap_missing_token', __( 'Mailtrap API token is not set.', 'mailtrap-mailer' ) );
	}

	if ( ! class_exists( '\Mailtrap\MailtrapGeneralClient' ) ) {
		mailtrap_mailer_bootstrap_vendor();
	}

	if ( ! class_exists( '\Mailtrap\MailtrapGeneralClient' ) ) {
		return new WP_Error( 'mailtrap_sdk_missing', __( 'Mailtrap SDK is missing.', 'mailtrap-mailer' ) );
	}

	try {
		// Create a new config for general API (not sending API)
		$config = new \Mailtrap\Config( $settings['token'] );
		$general_client = new \Mailtrap\MailtrapGeneralClient( $config );

		$accounts_response = $general_client->accounts()->getList();
		$accounts          = \Mailtrap\Helper\ResponseHelper::toArray( $accounts_response );

		$account           = is_array( $accounts ) && count( $accounts ) ? $accounts[0] : array();
		$account_id = isset( $account['id'] ) ? (int) $account['id'] : null;

		$billing   = array();
		if ( $account_id ) {
			$billing_response = $general_client->billing( $account_id )->getBillingUsage();
			$billing          = \Mailtrap\Helper\ResponseHelper::toArray( $billing_response );
		}

		// Parse sending stats from API response
		$sending = $billing['sending'] ?? array();
		$sending_usage = $sending['usage'] ?? array();
		$sent_count = $sending_usage['sent_messages_count'] ?? array();

		// Parse testing stats if available
		$testing = $billing['testing'] ?? array();
		$testing_plan = $testing['plan'] ?? array();

		$result = array(
			'plan'         => $sending['plan']['name'] ?? $testing_plan['name'] ?? '',
			'team'         => $account['name'] ?? '',
			'balance'      => null, // Mailtrap free plan doesn't have credit balance
			'monthly_sent' => $sent_count['current'] ?? null,
		);

		return $result;
	} catch ( \Throwable $e ) {
		return new WP_Error( 'mailtrap_stats_failed', $e->getMessage() );
	}
}

/**
 * Get Mailtrap email log directory inside uploads.
 *
 * @return string|WP_Error Directory path or WP_Error when uploads are unavailable.
 */
function mailtrap_mailer_get_log_dir() {
	$uploads = wp_upload_dir( null, false );

	if ( ! empty( $uploads['error'] ) ) {
		return new WP_Error( 'mailtrap_uploads_unavailable', __( 'Upload directory is not available for Mailtrap logs.', 'mailtrap-mailer' ) );
	}

	$dir = trailingslashit( $uploads['basedir'] ) . 'mailtrap-mailer';

	if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'mailtrap_log_dir_unwritable', __( 'Unable to create Mailtrap log directory.', 'mailtrap-mailer' ) );
	}

	if ( ! wp_is_writable( $dir ) ) {
		return new WP_Error( 'mailtrap_log_dir_unwritable', __( 'Mailtrap log directory is not writable.', 'mailtrap-mailer' ) );
	}

	return $dir;
}

/**
 * Get Mailtrap email log file path.
 *
 * @return string|WP_Error Path to log file or WP_Error when unavailable.
 */
function mailtrap_mailer_get_log_file() {
	$log_dir = mailtrap_mailer_get_log_dir();

	if ( is_wp_error( $log_dir ) ) {
		return $log_dir;
	}

	return trailingslashit( $log_dir ) . 'mailtrap-emails.log';
}

/**
 * Write email send entry to log file.
 *
 * @param array $email_data Email information.
 * @param array $response   API response data.
 * @param bool  $success    Whether send was successful.
 * @param array $normalized Optional normalized email data for additional details.
 */
function mailtrap_mailer_log_email( $email_data, $response = array(), $success = false, $normalized = array() ) {
	$settings = mailtrap_mailer_get_settings();
	if ( empty( $settings['log_emails'] ) ) {
		return; // Logging disabled.
	}

	$log_file = mailtrap_mailer_get_log_file();

	if ( is_wp_error( $log_file ) ) {
		return;
	}

	// Get category if normalized data is available.
	$category = '';
	if ( ! empty( $normalized ) ) {
		$category = mailtrap_mailer_get_email_category( $normalized );
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

	if ( ! function_exists( 'wp_is_writable' ) || wp_is_writable( dirname( $log_file ) ) ) {
		error_log( $log_line, 3, $log_file );
	}

	// Clean old logs based on retention days setting.
	mailtrap_mailer_cleanup_logs();
}

/**
 * Clean old email logs based on retention period.
 */
function mailtrap_mailer_cleanup_logs() {
	$settings = mailtrap_mailer_get_settings();
	$retention_days = ! empty( $settings['log_retention_days'] ) ? (int) $settings['log_retention_days'] : 30;

	$log_file = mailtrap_mailer_get_log_file();

	if ( is_wp_error( $log_file ) || ! file_exists( $log_file ) || ! is_readable( $log_file ) ) {
		return;
	}

	$cutoff_time = time() - ( $retention_days * DAY_IN_SECONDS );
	$lines = array();

	// Read file and filter by date.
	$file = new SplFileObject( $log_file, 'r' );
	while ( ! $file->eof() ) {
		$line = $file->fgets();
		if ( empty( trim( $line ) ) ) {
			continue;
		}

		$entry = json_decode( $line, true );
		if ( ! is_array( $entry ) || empty( $entry['timestamp'] ) ) {
			$lines[] = $line; // Keep unparseable lines.
			continue;
		}

		$entry_time = strtotime( $entry['timestamp'] );
		if ( $entry_time > $cutoff_time ) {
			$lines[] = $line; // Keep recent logs.
		}
	}

	unset( $file );

	// Write back filtered logs.
	file_put_contents( $log_file, implode( '', $lines ), LOCK_EX );
}

/**
 * Determine email category based on email content and subject.
 *
 * @param array $normalized Normalized email data.
 *
 * @return string Email category for Mailtrap.
 */
function mailtrap_mailer_detect_email_category( $normalized ) {
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
function mailtrap_mailer_get_email_category( $normalized ) {
	$settings = mailtrap_mailer_get_settings();

	if ( empty( $settings['enable_categories'] ) ) {
		return '';
	}

	if ( ! empty( $settings['auto_categorize'] ) ) {
		return mailtrap_mailer_detect_email_category( $normalized );
	}

	return apply_filters( 'mailtrap_mailer_email_category', 'general', $normalized );
}

/**
 * Read email logs from file.
 *
 * @param int $limit Number of logs to retrieve.
 *
 * @return array Array of email log entries.
 */
function mailtrap_mailer_read_email_logs( $limit = 20 ) {
	$log_file = mailtrap_mailer_get_log_file();

	if ( is_wp_error( $log_file ) || ! file_exists( $log_file ) || ! is_readable( $log_file ) ) {
		return array();
	}

	$entries = array();
	$file = new SplFileObject( $log_file, 'r' );
	$file->seek( PHP_INT_MAX );

	$line_count = $file->key();
	$start_line = max( 0, $line_count - $limit * 2 );

	$file->seek( $start_line );

	while ( ! $file->eof() ) {
		$line = $file->fgets();
		if ( empty( trim( $line ) ) ) {
			continue;
		}

		$entry = json_decode( $line, true );
		if ( is_array( $entry ) ) {
			$entries[] = $entry;
		}
	}

	unset( $file );

	// Sort by timestamp (newest first).
	usort( $entries, function ( $a, $b ) {
		$time_a = strtotime( $a['timestamp'] ?? '1970-01-01 00:00:00' );
		$time_b = strtotime( $b['timestamp'] ?? '1970-01-01 00:00:00' );
		return $time_b - $time_a; // Descending order (newest first).
	});

	return array_slice( $entries, 0, $limit );
}
