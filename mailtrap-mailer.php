<?php
/**
 * Plugin Name: Mailtrap Mailer
 * Plugin URI: https://mailtrap.io/
 * Description: Routes wp_mail() through the Mailtrap HTTP API with configurable sender settings.
 * Version: 1.1.0
 * Author: CrowdSpace
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mailtrap-mailer
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MAILTRAP_MAILER_VERSION', '1.1.0' );
define( 'MAILTRAP_MAILER_OPTION_KEY', 'mailtrap_mailer_settings' );

/**
 * Locate composer autoload file inside the plugin.
 *
 * @return string|null
 */
function mailtrap_mailer_locate_autoload() {
	static $path = null;

	if ( null !== $path ) {
		return $path;
	}

	$candidates = array(
		__DIR__ . '/vendor/autoload.php',
		trailingslashit( WP_PLUGIN_DIR ) . 'mailtrap-mailer/vendor/autoload.php',
	);

	foreach ( $candidates as $candidate ) {
		if ( file_exists( $candidate ) ) {
			$path = $candidate;
			break;
		}
	}

	return $path;
}

/**
 * Load Composer autoload if present.
 *
 * @return bool Whether vendor autoload was successfully loaded.
 */
function mailtrap_mailer_bootstrap_vendor() {
	static $loaded = false;

	if ( $loaded ) {
		return true;
	}

	$autoload = mailtrap_mailer_locate_autoload();

	if ( $autoload && file_exists( $autoload ) ) {
		require_once $autoload;
		$loaded = true;
		return true;
	}

	return false;
}

define( 'MAILTRAP_MAILER_VENDOR_LOADED', mailtrap_mailer_bootstrap_vendor() );

require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/mailtrap-api.php';

/**
 * Main hook: short-circuit wp_mail() and send via Mailtrap API when enabled.
 *
 * @param null|bool $pre_wp_mail .
 * @param array     $atts        Mail attributes.
 *
 * @return bool|WP_Error|null
 */
function mailtrap_mailer_pre_wp_mail( $pre_wp_mail, $atts ) {
	$settings = mailtrap_mailer_get_settings();

	if ( empty( $settings['enabled'] ) || empty( $settings['token'] ) ) {
		return $pre_wp_mail;
	}

	$normalized = mailtrap_mailer_normalize_atts( $atts, $settings );

	if ( is_wp_error( $normalized ) ) {
		mailtrap_mailer_trigger_failed( $normalized );

		return $normalized;
	}

	$response = mailtrap_mailer_send_via_sdk( $normalized, $settings );

	if ( is_wp_error( $response ) ) {
		mailtrap_mailer_trigger_failed( $response );

		return $response;
	}

	return true;
}
add_filter( 'pre_wp_mail', 'mailtrap_mailer_pre_wp_mail', 1, 2 );

/**
 * Default plugin settings.
 *
 * @return array
 */
function mailtrap_mailer_default_settings() {
	return array(
		'enabled'             => 1,
		'token'               => '',
		'sender_email'        => '',
		'sender_name'         => '',
		'endpoint'            => 'https://send.api.mailtrap.io/api/send',
		'log_emails'          => 0,
		'log_retention_days'  => 30,
		'logs_per_page'       => 10,
		'enable_categories'   => 1,
		'auto_categorize'     => 1,
		'emails_per_hour'     => 150,
	);
}

/**
 * Get plugin settings merged with defaults and sensible fallbacks.
 *
 * @return array
 */
function mailtrap_mailer_get_settings() {
	$settings = get_option( MAILTRAP_MAILER_OPTION_KEY, array() );
	$settings = wp_parse_args( $settings, mailtrap_mailer_default_settings() );

	if ( '' === $settings['sender_email'] ) {
		$settings['sender_email'] = get_option( 'admin_email' );
	}

	if ( '' === $settings['sender_name'] ) {
		$settings['sender_name'] = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	$settings['sender_email'] = apply_filters( 'wp_mail_from', $settings['sender_email'] );
	$settings['sender_name']  = apply_filters( 'wp_mail_from_name', $settings['sender_name'] );

	return $settings;
}

/**
 * Normalize wp_mail() arguments for Mailtrap API.
 *
 * @param array $atts     .
 * @param array $settings .
 *
 * @return array|WP_Error
 */
function mailtrap_mailer_normalize_atts( $atts, $settings ) {
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

	$recipients = mailtrap_mailer_parse_recipients( $to );
	if ( is_wp_error( $recipients ) ) {
		return $recipients;
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
	$charset      = get_bloginfo( 'charset' );
	$from_email   = $settings['sender_email'];
	$from_name    = $settings['sender_name'];

	foreach ( (array) $headers_input as $header_line ) {
		if ( ! str_contains( $header_line, ':' ) ) {
			continue;
		}

		list( $name, $content ) = explode( ':', trim( $header_line ), 2 );

		$name    = trim( $name );
		$content = trim( $content );

		switch ( strtolower( $name ) ) {
			case 'from':
				$from = mailtrap_mailer_parse_recipients( array( $content ) );
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
					list( $type, $charset_content ) = explode( ';', $content );
					$content_type                   = trim( $type );
					if ( false !== stripos( $charset_content, 'charset=' ) ) {
						$charset = trim( str_replace( array( 'charset=', '"' ), '', $charset_content ) );
					}
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

	$cc  = mailtrap_mailer_parse_recipients( $cc );
	$bcc = mailtrap_mailer_parse_recipients( $bcc );

	if ( is_wp_error( $cc ) ) {
		return $cc;
	}

	if ( is_wp_error( $bcc ) ) {
		return $bcc;
	}

	$reply_to = mailtrap_mailer_parse_recipients( $reply_to );
	if ( is_wp_error( $reply_to ) ) {
		return $reply_to;
	}

	$content_type = apply_filters( 'wp_mail_content_type', $content_type );
	$charset      = apply_filters( 'wp_mail_charset', $charset );

	$attachments = mailtrap_mailer_normalize_attachments( $atts['attachments'] );
	if ( is_wp_error( $attachments ) ) {
		return $attachments;
	}

	if ( ! is_email( $from_email ) ) {
		return new WP_Error( 'mailtrap_invalid_sender', __( 'Sender email is invalid.', 'mailtrap-mailer' ) );
	}

	return array(
		'to'           => $recipients,
		'cc'           => $cc,
		'bcc'          => $bcc,
		'reply_to'     => $reply_to,
		'subject'      => (string) $atts['subject'],
		'message'      => (string) $atts['message'],
		'headers'      => $headers,
		'content_type' => $content_type,
		'charset'      => $charset,
		'attachments'  => $attachments,
		'from_email'   => $from_email,
		'from_name'    => $from_name,
	);
}

/**
 * Parse recipients into Mailtrap format.
 *
 * @param array $recipients .
 *
 * @return array|WP_Error
 */
function mailtrap_mailer_parse_recipients( $recipients ) {
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
			return new WP_Error( 'mailtrap_invalid_recipient', sprintf( __( 'Invalid email address: %s', 'mailtrap-mailer' ), $recipient ) );
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
function mailtrap_mailer_normalize_attachments( $attachments ) {
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
			return new WP_Error( 'mailtrap_missing_attachment', sprintf( __( 'Attachment not found or unreadable: %s', 'mailtrap-mailer' ), $attachment ) );
		}

		$contents = file_get_contents( $attachment );

		if ( false === $contents ) {
			return new WP_Error( 'mailtrap_unreadable_attachment', sprintf( __( 'Unable to read attachment: %s', 'mailtrap-mailer' ), $attachment ) );
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
 * Send email through Mailtrap SDK.
 *
 * @param array $normalized Normalized email data.
 * @param array $settings   Plugin settings.
 *
 * @return true|WP_Error
 */
function mailtrap_mailer_send_via_sdk( $normalized, $settings ) {
	if ( ! class_exists( '\Mailtrap\MailtrapClient' ) ) {
		return new WP_Error( 'mailtrap_sdk_missing', __( 'Mailtrap SDK is missing.', 'mailtrap-mailer' ) );
	}

	try {
		// Create config with token
		$config = new \Mailtrap\Config( $settings['token'] );

		// Use bulk stream for promotional emails
		$use_bulk = mailtrap_mailer_should_use_bulk_stream( $normalized, $settings );
		if ( $use_bulk ) {
			$config->setHost( 'bulk.api.mailtrap.io' );
			$email_client = new \Mailtrap\MailtrapBulkSendingClient( $config );
		} else {
			$email_client = new \Mailtrap\MailtrapSendingClient( $config );
		}

		// Create Mailtrap Email object.
		$email = new \Mailtrap\Mime\MailtrapEmail();

		// Set From.
		$email->from(
			new \Symfony\Component\Mime\Address(
				$normalized['from_email'],
				$normalized['from_name']
			)
		);

		// Set To.
		foreach ( $normalized['to'] as $recipient ) {
			$address = new \Symfony\Component\Mime\Address(
				$recipient['email'],
				$recipient['name'] ?? ''
			);
			$email->addTo( $address );
		}

		// Set CC.
		foreach ( $normalized['cc'] as $recipient ) {
			$address = new \Symfony\Component\Mime\Address(
				$recipient['email'],
				$recipient['name'] ?? ''
			);
			$email->addCc( $address );
		}

		// Set BCC.
		foreach ( $normalized['bcc'] as $recipient ) {
			$address = new \Symfony\Component\Mime\Address(
				$recipient['email'],
				$recipient['name'] ?? ''
			);
			$email->addBcc( $address );
		}

		// Set Reply-To.
		if ( ! empty( $normalized['reply_to'] ) && isset( $normalized['reply_to'][0] ) ) {
			$address = new \Symfony\Component\Mime\Address(
				$normalized['reply_to'][0]['email'],
				$normalized['reply_to'][0]['name'] ?? ''
			);
			$email->replyTo( $address );
		}

		// Set Subject.
		$email->subject( $normalized['subject'] );

		// Add category if enabled.
		$category = mailtrap_mailer_get_email_category( $normalized );
		if ( ! empty( $category ) ) {
			$email->category( $category );
		}

		// Set Body (text/html).
		$is_html = false !== stripos( $normalized['content_type'], 'text/html' );
		if ( $is_html ) {
			$email->html( $normalized['message'] );
			$email->text( wp_strip_all_tags( $normalized['message'] ) );
		} else {
			$email->text( $normalized['message'] );
		}

		// Add headers.
		foreach ( $normalized['headers'] as $header_name => $header_value ) {
			$email->getHeaders()->addTextHeader( $header_name, $header_value );
		}

		// Add attachments.
		foreach ( $normalized['attachments'] as $attachment ) {
			$email->attach(
				base64_decode( $attachment['content'] ),
				$attachment['filename'],
				$attachment['type']
			);
		}

		// Send via SDK.
		$response = $email_client->emails()->send( $email );

		// Check response.
		if ( ! is_object( $response ) ) {
			// Log to mailtrap-emails.log.
			mailtrap_mailer_log_email(
				array(
					'to'      => $normalized['to'],
					'from'    => $normalized['from_email'],
					'subject' => $normalized['subject'],
				),
				array(
					'http_status' => 0,
					'message'     => 'Invalid response - not an object',
				),
				false,
				$normalized
			);

			return new WP_Error( 'mailtrap_invalid_response', __( 'Invalid response from Mailtrap API.', 'mailtrap-mailer' ) );
		}

		// Response can be a ResponseInterface, try to get status code if available.
		if ( method_exists( $response, 'getStatusCode' ) ) {
			$status = $response->getStatusCode();

			if ( $status < 200 || $status >= 300 ) {
				$body = method_exists( $response, 'getBody' ) ? (string) $response->getBody() : 'Unknown error';

				// Log to mailtrap-emails.log.
				mailtrap_mailer_log_email(
					array(
						'to'      => $normalized['to'],
						'from'    => $normalized['from_email'],
						'subject' => $normalized['subject'],
					),
					array(
						'http_status' => $status,
						'message'     => $body,
					),
					false,
					$normalized
				);

				return new WP_Error(
					'mailtrap_api_error',
					sprintf( __( 'Mailtrap API returned HTTP %d', 'mailtrap-mailer' ), $status ),
					array( 'body' => $body )
				);
			}
		}

		// Log successful send.
		mailtrap_mailer_log_email(
			array(
				'to'      => $normalized['to'],
				'from'    => $normalized['from_email'],
				'subject' => $normalized['subject'],
			),
			array(
				'http_status' => 200,
				'message'     => 'Email sent successfully',
			),
			true,
			$normalized
		);

		return true;
	} catch ( \Throwable $e ) {
		return new WP_Error( 'mailtrap_send_failed', $e->getMessage() );
	}
}

/**
 * Fire wp_mail_failed action to preserve logging hooks.
 *
 * @param WP_Error $error .
 */
function mailtrap_mailer_trigger_failed( WP_Error $error ) {
	do_action( 'wp_mail_failed', $error );
}
