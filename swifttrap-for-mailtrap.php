<?php
/**
 * Plugin Name: SwiftTrap for Mailtrap
 * Plugin URI: https://plugins.symonov.com/swifttrap-for-mailtrap/
 * Description: Routes wp_mail() through the Mailtrap HTTP API with configurable sender settings.
 * Version: 2.4.2
 * Author: simmotorlp
 * Author URI: https://profiles.wordpress.org/simmotorlp/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: swifttrap-for-mailtrap
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SWIFTTRAP_MAILTRAP_VERSION', '2.4.2' );
define( 'SWIFTTRAP_MAILTRAP_OPTION_KEY', 'swifttrap_mailtrap_settings' );

require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/swifttrap-api.php';
require_once __DIR__ . '/includes/swifttrap-webhook.php';
require_once __DIR__ . '/includes/swifttrap-site-health.php';
require_once __DIR__ . '/includes/swifttrap-cli.php';

register_activation_hook( __FILE__, 'swifttrap_mailtrap_activate' );
register_deactivation_hook( __FILE__, 'swifttrap_mailtrap_deactivate' );

/**
 * Activate plugin and schedule daily cleanup cron.
 */
function swifttrap_mailtrap_activate(): void {
	if ( ! wp_next_scheduled( 'swifttrap_mailtrap_cleanup_logs_cron' ) ) {
		wp_schedule_event( time(), 'daily', 'swifttrap_mailtrap_cleanup_logs_cron' );
	}
}

/**
 * Deactivate plugin and clear scheduled cleanup cron.
 */
function swifttrap_mailtrap_deactivate(): void {
	wp_clear_scheduled_hook( 'swifttrap_mailtrap_cleanup_logs_cron' );
}

add_action( 'swifttrap_mailtrap_cleanup_logs_cron', 'swifttrap_mailtrap_cleanup_logs' );

/**
 * Main hook: short-circuit wp_mail() and send via Mailtrap API when enabled.
 *
 * @param null|bool $pre_wp_mail .
 * @param array     $atts        Mail attributes.
 *
 * @return bool|WP_Error|null
 */
function swifttrap_mailtrap_pre_wp_mail( ?bool $pre_wp_mail, array $atts ): bool|WP_Error|null {
	$settings = swifttrap_mailtrap_get_settings();

	if ( empty( $settings['enabled'] ) || empty( $settings['token'] ) ) {
		return $pre_wp_mail;
	}

	$normalized = swifttrap_mailtrap_normalize_atts( $atts, $settings );

	if ( is_wp_error( $normalized ) ) {
		swifttrap_mailtrap_trigger_failed( $normalized );

		return $normalized;
	}

	$skipped_recipients = array();

	$to_filtered = array();
	foreach ( $normalized['to'] as $rec ) {
		if ( swifttrap_mailtrap_is_recipient_suppressed( $rec['email'], $settings ) ) {
			$skipped_recipients[] = $rec['email'];
		} else {
			$to_filtered[] = $rec;
		}
	}
	$normalized['to'] = $to_filtered;

	$cc_filtered = array();
	foreach ( $normalized['cc'] as $rec ) {
		if ( swifttrap_mailtrap_is_recipient_suppressed( $rec['email'], $settings ) ) {
			$skipped_recipients[] = $rec['email'];
		} else {
			$cc_filtered[] = $rec;
		}
	}
	$normalized['cc'] = $cc_filtered;

	$bcc_filtered = array();
	foreach ( $normalized['bcc'] as $rec ) {
		if ( swifttrap_mailtrap_is_recipient_suppressed( $rec['email'], $settings ) ) {
			$skipped_recipients[] = $rec['email'];
		} else {
			$bcc_filtered[] = $rec;
		}
	}
	$normalized['bcc'] = $bcc_filtered;

	$normalized['skipped_recipients'] = $skipped_recipients;

	if ( empty( $normalized['to'] ) ) {
		$error = new WP_Error(
			'swifttrap_all_recipients_suppressed',
			sprintf( __( 'All recipients skipped due to suppression: %s', 'swifttrap-for-mailtrap' ), implode( ', ', $skipped_recipients ) )
		);
		swifttrap_mailtrap_log_email(
			array(
				'to'      => $atts['to'] ?? array(),
				'from'    => $normalized['from_email'] ?? '',
				'subject' => $normalized['subject'] ?? '',
			),
			array(
				'http_status' => 0,
				'message'     => $error->get_error_message(),
			),
			false,
			swifttrap_mailtrap_get_email_category( $normalized )
		);
		swifttrap_mailtrap_trigger_failed( $error );

		return $error;
	}

	$response = swifttrap_mailtrap_send( $normalized, $settings );

	if ( is_wp_error( $response ) ) {
		swifttrap_mailtrap_trigger_failed( $response );

		return null; // Fallback to native wp_mail()
	}

	return true;
}
add_filter( 'pre_wp_mail', 'swifttrap_mailtrap_pre_wp_mail', 1, 2 );

/**
 * Default plugin settings.
 *
 * @return array
 */
function swifttrap_mailtrap_default_settings(): array {
	return array(
		'enabled'                => 1,
		'token'                  => '',
		'sender_email'           => '',
		'sender_name'            => '',
		'log_emails'             => 0,
		'log_retention_days'     => 30,
		'logs_per_page'          => 10,
		'enable_categories'      => 1,
		'auto_categorize'        => 1,
		'webhook_secret'         => '',
		'max_attachment_size_mb' => 25,
		'category_streams'       => array(
			'promotional' => 'bulk',
		),
		'category_senders'       => array(),
	);
}

/**
 * Get plugin settings merged with defaults and sensible fallbacks.
 *
 * @return array
 */
function swifttrap_mailtrap_get_settings(): array {
	static $cached = array();

	$blog_id = get_current_blog_id();

	if ( isset( $cached[ $blog_id ] ) ) {
		return $cached[ $blog_id ];
	}

	$settings = get_option( SWIFTTRAP_MAILTRAP_OPTION_KEY, array() );
	$settings = wp_parse_args( $settings, swifttrap_mailtrap_default_settings() );

	if ( empty( $settings['webhook_secret'] ) ) {
		$settings['webhook_secret'] = function_exists( 'wp_generate_password' ) ? wp_generate_password( 32, false ) : bin2hex( random_bytes( 16 ) );
		update_option( SWIFTTRAP_MAILTRAP_OPTION_KEY, $settings );
	}

	if ( '' === $settings['sender_email'] ) {
		$settings['sender_email'] = get_option( 'admin_email' );
	}

	if ( '' === $settings['sender_name'] ) {
		$settings['sender_name'] = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional WP core filter override.
	$settings['sender_email'] = apply_filters( 'wp_mail_from', $settings['sender_email'] );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional WP core filter override.
	$settings['sender_name']  = apply_filters( 'wp_mail_from_name', $settings['sender_name'] );

	$cached[ $blog_id ] = $settings;

	return $cached[ $blog_id ];
}

/**
 * Normalize wp_mail() arguments for Mailtrap API.
 *
 * @param array $atts     .
 * @param array $settings .
 *
 * @return array|WP_Error
 */
function swifttrap_mailtrap_normalize_atts( array $atts, array $settings ): array|WP_Error {
	$atts = wp_parse_args(
		$atts,
		array(
			'to'          => array(),
			'subject'     => '',
			'message'     => '',
			'headers'     => array(),
			'attachments' => array(),
		)
	);

	$to = $atts['to'];
	if ( ! is_array( $to ) ) {
		$to = explode( ',', $to );
	}

	$recipients = swifttrap_mailtrap_parse_recipients( $to );
	if ( is_wp_error( $recipients ) ) {
		return $recipients;
	}
	if ( empty( $recipients ) ) {
		return new WP_Error( 'swifttrap_no_recipients', __( 'No valid recipients.', 'swifttrap-for-mailtrap' ) );
	}

	$headers_input = $atts['headers'];

	if ( empty( $headers_input ) ) {
		$headers_input = array();
	} elseif ( ! is_array( $headers_input ) ) {
		$headers_input = explode( "\n", str_replace( "\r\n", "\n", $headers_input ) );
	}

	$headers      = array();
	$cc           = array();
	$bcc          = array();
	$reply_to     = array();
	$content_type = 'text/plain';

	$temp_normalized = array(
		'subject' => (string) $atts['subject'],
		'message' => (string) $atts['message'],
	);
	$category = swifttrap_mailtrap_get_email_category( $temp_normalized );

	$from_email = $settings['sender_email'];
	$from_name  = $settings['sender_name'];

	if ( ! empty( $settings['category_senders'][ $category ]['email'] ) ) {
		$from_email = $settings['category_senders'][ $category ]['email'];
		if ( ! empty( $settings['category_senders'][ $category ]['name'] ) ) {
			$from_name = $settings['category_senders'][ $category ]['name'];
		}
	}

	foreach ( (array) $headers_input as $header_line ) {
		if ( ! str_contains( $header_line, ':' ) ) {
			continue;
		}

		[ $name, $content ] = explode( ':', trim( $header_line ), 2 );

		$name    = trim( $name );
		$content = trim( $content );

		switch ( strtolower( $name ) ) {
			case 'from':
				$from = swifttrap_mailtrap_parse_recipients( array( $content ) );
				if ( is_wp_error( $from ) ) {
					return $from;
				}
				if ( ! empty( $from ) ) {
					$from_email = $from[0]['email'];
					if ( isset( $from[0]['name'] ) ) {
						$from_name = $from[0]['name'];
					}
				}
				break;
			case 'content-type':
				if ( str_contains( $content, ';' ) ) {
					[ $type, ] = explode( ';', $content );
					$content_type  = trim( $type );
				} elseif ( '' !== $content ) {
					$content_type = $content;
				}
				break;
			case 'cc':
				$cc = array_merge( $cc, explode( ',', $content ) );
				break;
			case 'bcc':
				$bcc = array_merge( $bcc, explode( ',', $content ) );
				break;
			case 'reply-to':
				$reply_to = array_merge( $reply_to, explode( ',', $content ) );
				break;
			default:
				$headers[ $name ] = $content;
		}
	}

	$cc  = swifttrap_mailtrap_parse_recipients( $cc );
	$bcc = swifttrap_mailtrap_parse_recipients( $bcc );

	if ( is_wp_error( $cc ) ) {
		return $cc;
	}

	if ( is_wp_error( $bcc ) ) {
		return $bcc;
	}

	$reply_to = swifttrap_mailtrap_parse_recipients( $reply_to );
	if ( is_wp_error( $reply_to ) ) {
		return $reply_to;
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional WP core filter override.
	$content_type = apply_filters( 'wp_mail_content_type', $content_type );

	// If content type is still plain text but the message contains HTML, switch to text/html.
	if ( false === stripos( $content_type, 'text/html' ) && swifttrap_mailtrap_message_looks_html( $atts['message'] ) ) {
		$content_type = 'text/html';
	}

	$attachments = swifttrap_mailtrap_normalize_attachments( $atts['attachments'] );
	if ( is_wp_error( $attachments ) ) {
		return $attachments;
	}

	if ( ! is_email( $from_email ) ) {
		return new WP_Error( 'swifttrap_invalid_sender', __( 'Sender email is invalid.', 'swifttrap-for-mailtrap' ) );
	}

	return array(
		'to'           => $recipients,
		'cc'           => $cc,
		'bcc'          => $bcc,
		'reply_to'     => $reply_to,
		'subject'      => str_replace( array( "\r", "\n" ), '', (string) $atts['subject'] ),
		'message'      => (string) $atts['message'],
		'headers'      => $headers,
		'content_type' => $content_type,
		'attachments'  => $attachments,
		'from_email'   => $from_email,
		'from_name'    => $from_name,
	);
}

/**
 * Heuristic: detect if message looks like HTML.
 *
 * @param string $message Message body.
 *
 * @return bool
 */
function swifttrap_mailtrap_message_looks_html( mixed $message ): bool {
	if ( ! is_string( $message ) || '' === trim( $message ) ) {
		return false;
	}

	// Quick tag check to avoid expensive parsing.
	if ( ! str_contains( $message, '<' ) || ! str_contains( $message, '>' ) ) {
		return false;
	}

	// Common HTML markers.
	$markers = array( '<html', '<body', '<table', '<div', '<span', '<p', '<br', '<style', '<!doctype' );

	foreach ( $markers as $marker ) {
		if ( stripos( $message, $marker ) !== false ) {
			return true;
		}
	}

	// Fallback: look for any tag pattern.
	return (bool) preg_match( '/<[^>]+>/', $message );
}

/**
 * Parse recipients into Mailtrap format.
 *
 * @param array $recipients .
 *
 * @return array|WP_Error
 */
function swifttrap_mailtrap_parse_recipients( mixed $recipients ): array|WP_Error {
	$parsed = array();

	foreach ( (array) $recipients as $recipient ) {
		$recipient = trim( $recipient );

		if ( '' === $recipient ) {
			continue;
		}

		$name  = '';
		$email = $recipient;

		if ( str_contains( $recipient, '<' ) ) {
			$bracket_pos = strpos( $recipient, '<' );
			if ( $bracket_pos > 0 ) {
				$name = trim( str_replace( '"', '', substr( $recipient, 0, $bracket_pos ) ) );
			}

			$email = substr( $recipient, $bracket_pos + 1 );
			$email = trim( str_replace( '>', '', $email ) );
		}

		if ( ! is_email( $email ) ) {
			/* translators: %s: the invalid email address */
		return new WP_Error( 'swifttrap_invalid_recipient', sprintf( __( 'Invalid email address: %s', 'swifttrap-for-mailtrap' ), $recipient ) );
		}

		$entry = array(
			'email' => $email,
		);

		if ( '' !== $name ) {
			$entry['name'] = $name;
		}

		$parsed[] = $entry;
	}

	return $parsed;
}

/**
 * Normalize attachments for Mailtrap API.
 *
 * @param string|array $attachments .
 *
 * @return array|WP_Error
 */
function swifttrap_mailtrap_normalize_attachments( mixed $attachments ): array|WP_Error {
	if ( empty( $attachments ) ) {
		return array();
	}

	if ( ! is_array( $attachments ) ) {
		$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
	}

	$normalized = array();

	foreach ( $attachments as $attachment ) {
		$attachment = trim( $attachment );

		if ( '' === $attachment ) {
			continue;
		}

		if ( ! file_exists( $attachment ) || ! is_readable( $attachment ) ) {
			/* translators: %s: file path of the missing attachment */
		return new WP_Error( 'swifttrap_missing_attachment', sprintf( __( 'Attachment not found or unreadable: %s', 'swifttrap-for-mailtrap' ), $attachment ) );
		}

		$settings  = swifttrap_mailtrap_get_settings();
		$max_mb    = $settings['max_attachment_size_mb'] ?? 25;
		$max_size  = $max_mb * MB_IN_BYTES;
		$file_size = filesize( $attachment );
		if ( $file_size > $max_size ) {
			return new WP_Error(
				'swifttrap_attachment_too_large',
				sprintf(
					/* translators: %1$s: file name, %2$s: file size, %3$d: max size MB */
					__( 'Attachment exceeds %3$d MB limit: %1$s (%2$s)', 'swifttrap-for-mailtrap' ),
					wp_basename( $attachment ),
					size_format( $file_size ),
					$max_mb
				)
			);
		}

		$wp_filesystem = swifttrap_mailtrap_filesystem();
		if ( ! $wp_filesystem ) {
			return new WP_Error( 'swifttrap_filesystem_unavailable', __( 'WordPress filesystem is not available.', 'swifttrap-for-mailtrap' ) );
		}

		$contents = $wp_filesystem->get_contents( $attachment );

		if ( false === $contents ) {
			/* translators: %s: file path of the unreadable attachment */
		return new WP_Error( 'swifttrap_unreadable_attachment', sprintf( __( 'Unable to read attachment: %s', 'swifttrap-for-mailtrap' ), $attachment ) );
		}

		$type = 'application/octet-stream';
		$info = wp_check_filetype( wp_basename( $attachment ) );

		if ( ! empty( $info['type'] ) ) {
			$type = $info['type'];
		}

		$normalized[] = array(
			'content'  => base64_encode( $contents ),
			'filename' => wp_basename( $attachment ),
			'type'     => $type,
		);
	}

	return $normalized;
}


/**
 * Send email through Mailtrap HTTP API.
 *
 * @param array $normalized Normalized email data.
 * @param array $settings   Plugin settings.
 *
 * @return true|WP_Error
 */
function swifttrap_mailtrap_send( array $normalized, array $settings ): bool|WP_Error {
	$category = swifttrap_mailtrap_get_email_category( $normalized );

	// Block bulk/promotional emails when SWIFTTRAP_BLOCK_BULK is enabled (e.g. staging).
	if ( defined( 'SWIFTTRAP_BLOCK_BULK' ) && SWIFTTRAP_BLOCK_BULK && 'promotional' === $category ) {
		$blocked = new WP_Error(
			'swifttrap_bulk_blocked',
			__( 'Bulk/promotional emails are blocked on this environment.', 'swifttrap-for-mailtrap' )
		);
		swifttrap_mailtrap_log_email(
			array(
				'to'      => $normalized['to'],
				'from'    => $normalized['from_email'],
				'subject' => $normalized['subject'],
			),
			array(
				'http_status' => 0,
				'message'     => 'Blocked by SWIFTTRAP_BLOCK_BULK',
			),
			false,
			$category
		);
		return $blocked;
	}

	$use_bulk = swifttrap_mailtrap_should_use_bulk_stream( $category, $settings );

	$host = $use_bulk
		? 'https://bulk.api.mailtrap.io/api/send'
		: 'https://send.api.mailtrap.io/api/send';

	$body = swifttrap_mailtrap_build_payload( $normalized, $category );

	$max_attempts = 3;
	$attempt      = 0;
	$response     = null;

	while ( $attempt < $max_attempts ) {
		$attempt++;
		$response = wp_remote_post( $host, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $settings['token'],
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		) );

		$should_retry = false;
		$retry_after  = 1;

		if ( is_wp_error( $response ) ) {
			$error_msg = $response->get_error_message();
			if ( false !== stripos( $error_msg, 'timeout' ) || false !== stripos( $error_msg, 'timed out' ) ) {
				$should_retry = true;
			}
		} else {
			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 429 === $status_code || ( $status_code >= 500 && $status_code < 600 ) ) {
				$should_retry = true;
				$retry_after_header = wp_remote_retrieve_header( $response, 'retry-after' );
				if ( ! empty( $retry_after_header ) && is_numeric( $retry_after_header ) ) {
					$retry_after = max( 1, (int) $retry_after_header );
				}
			}
		}

		if ( $should_retry && $attempt < $max_attempts ) {
			sleep( min( $retry_after, 10 ) );
			continue;
		}

		break;
	}

	$log_data = array(
		'to'           => $normalized['to'] ?? array(),
		'from'         => $normalized['from_email'] ?? '',
		'subject'      => $normalized['subject'] ?? '',
		'message'      => $normalized['message'] ?? '',
		'headers'      => $normalized['headers'] ?? array(),
		'content_type' => $normalized['content_type'] ?? 'text/plain',
	);

	if ( is_wp_error( $response ) ) {
		$err_msg = $response->get_error_message() . ' — falling back to native wp_mail()';
		if ( ! empty( $normalized['skipped_recipients'] ) ) {
			$err_msg .= ' (Skipped suppressed: ' . implode( ', ', $normalized['skipped_recipients'] ) . ')';
		}
		swifttrap_mailtrap_log_email(
			$log_data,
			array(
				'http_status' => 0,
				'message'     => $err_msg,
			),
			false,
			$category
		);

		return new WP_Error( 'swifttrap_send_failed', $response->get_error_message() );
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$body_raw    = wp_remote_retrieve_body( $response );

	if ( $status_code < 200 || $status_code >= 300 ) {
		$err_msg = $body_raw . ' — falling back to native wp_mail()';
		if ( ! empty( $normalized['skipped_recipients'] ) ) {
			$err_msg .= ' (Skipped suppressed: ' . implode( ', ', $normalized['skipped_recipients'] ) . ')';
		}
		swifttrap_mailtrap_log_email(
			$log_data,
			array(
				'http_status' => $status_code,
				'message'     => $err_msg,
			),
			false,
			$category
		);

		return new WP_Error(
			'swifttrap_api_error',
			/* translators: %d: HTTP status code returned by the Mailtrap API */
			sprintf( __( 'Mailtrap API returned HTTP %d', 'swifttrap-for-mailtrap' ), $status_code ),
			array( 'body' => $body_raw )
		);
	}

	$message_ids = array();
	$body_decoded = json_decode( $body_raw, true );
	if ( is_array( $body_decoded ) && ! empty( $body_decoded['message_ids'] ) ) {
		$message_ids = (array) $body_decoded['message_ids'];
	}

	$success_msg = 'Email sent successfully';
	if ( ! empty( $normalized['skipped_recipients'] ) ) {
		$success_msg .= '. Skipped suppressed: ' . implode( ', ', $normalized['skipped_recipients'] );
	}

	swifttrap_mailtrap_log_email(
		$log_data,
		array(
			'http_status' => $status_code,
			'message'     => $success_msg,
			'message_ids' => $message_ids,
		),
		true,
		$category
	);

	return true;
}

/**
 * Build Mailtrap API payload from normalized email data.
 *
 * @param array  $normalized Normalized email data.
 * @param string $category   Email category.
 *
 * @return array API payload.
 */
function swifttrap_mailtrap_build_payload( array $normalized, string $category ): array {
	$payload = array(
		'from'    => array( 'email' => $normalized['from_email'], 'name' => $normalized['from_name'] ),
		'to'      => $normalized['to'],
		'subject' => $normalized['subject'],
	);

	if ( false !== stripos( $normalized['content_type'], 'text/html' ) ) {
		$payload['html'] = $normalized['message'];
		$payload['text'] = wp_strip_all_tags( $normalized['message'] );
	} else {
		$payload['text'] = $normalized['message'];
	}

	if ( ! empty( $normalized['cc'] ) ) {
		$payload['cc'] = $normalized['cc'];
	}
	if ( ! empty( $normalized['bcc'] ) ) {
		$payload['bcc'] = $normalized['bcc'];
	}
	if ( ! empty( $normalized['reply_to'] ) ) {
		$payload['headers'] = array();
		$reply_emails = array();
		foreach ( $normalized['reply_to'] as $rt ) {
			$reply_emails[] = ! empty( $rt['name'] )
				? $rt['name'] . ' <' . $rt['email'] . '>'
				: $rt['email'];
		}
		$payload['headers']['Reply-To'] = implode( ', ', $reply_emails );
	}
	if ( ! empty( $normalized['headers'] ) ) {
		if ( ! isset( $payload['headers'] ) ) {
			$payload['headers'] = array();
		}
		$payload['headers'] = array_merge( $payload['headers'], $normalized['headers'] );
	}
	if ( ! empty( $category ) ) {
		$payload['category'] = $category;
	}

	// Template support: when template_uuid is set, Mailtrap ignores html/text.
	$template = apply_filters( 'swifttrap_mailtrap_template', array(), $normalized );

	if ( ! empty( $template['uuid'] ) ) {
		$payload['template_uuid'] = $template['uuid'];
		unset( $payload['html'], $payload['text'] );

		if ( ! empty( $template['variables'] ) && is_array( $template['variables'] ) ) {
			$payload['template_variables'] = $template['variables'];
		}
	}

	// Custom variables: key-value pairs visible in Mailtrap dashboard for tracking.
	$custom_vars = apply_filters( 'swifttrap_mailtrap_custom_variables', array(), $normalized );

	if ( ! empty( $custom_vars ) && is_array( $custom_vars ) ) {
		$payload['custom_variables'] = array_map( 'strval', $custom_vars );
	}

	if ( ! empty( $normalized['attachments'] ) ) {
		$payload['attachments'] = array_map( function ( $att ) {
			return array(
				'content'     => $att['content'],
				'filename'    => $att['filename'],
				'type'        => $att['type'],
				'disposition' => 'attachment',
			);
		}, $normalized['attachments'] );
	}

	return $payload;
}

/**
 * Fire wp_mail_failed action to preserve logging hooks.
 *
 * @since 1.0.0
 *
 * @param WP_Error $error The error object.
 * @return void
 */
function swifttrap_mailtrap_trigger_failed( WP_Error $error ): void {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional WP core action.
	do_action( 'wp_mail_failed', $error );
}

/**
 * Get the WP_Filesystem instance.
 *
 * Initializes WP_Filesystem if needed and returns the global instance.
 *
 * @since 2.2.0
 *
 * @return WP_Filesystem_Base|false Filesystem instance or false on failure.
 */
function swifttrap_mailtrap_filesystem(): object|false {
	global $wp_filesystem;

	if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
		return $wp_filesystem;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';

	if ( WP_Filesystem() ) {
		return $wp_filesystem;
	}

	return false;
}

