<?php
/**
 * Plugin Name: SwiftTrap for Mailtrap
 * Plugin URI: https://plugins.symonov.com/swifttrap-for-mailtrap/
 * Description: Routes wp_mail() through the Mailtrap HTTP API with configurable sender settings.
 * Version: 3.1.0
 * Author: simmotorlp
 * Author URI: https://profiles.wordpress.org/simmotorlp/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: swifttrap-for-mailtrap
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SWIFTTRAP_MAILTRAP_VERSION', '3.1.0' );
define( 'SWIFTTRAP_MAILTRAP_OPTION_KEY', 'swifttrap_mailtrap_settings' );

require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/review-notice.php';
require_once __DIR__ . '/includes/swifttrap-routing.php';
require_once __DIR__ . '/includes/swifttrap-api.php';
require_once __DIR__ . '/includes/swifttrap-webhook.php';
require_once __DIR__ . '/includes/swifttrap-site-health.php';
require_once __DIR__ . '/includes/swifttrap-cli.php';

register_activation_hook( __FILE__, 'swifttrap_mailtrap_activate' );
register_deactivation_hook( __FILE__, 'swifttrap_mailtrap_deactivate' );

/**
 * Activate plugin.
 */
function swifttrap_mailtrap_activate(): void {
}

/**
 * Deactivate plugin: clear any leftover cleanup cron from prior versions.
 */
function swifttrap_mailtrap_deactivate(): void {
	wp_clear_scheduled_hook( 'swifttrap_mailtrap_cleanup_logs_cron' );
}

/**
 * Main hook: short-circuit wp_mail() and send via Mailtrap API when enabled.
 *
 * Returns true when Mailtrap accepted the message, false when the message
 * cannot be sent at all (so wp_mail() reports the failure to its caller), and
 * null to hand the message back to the default WordPress mailer.
 *
 * @param mixed $pre_wp_mail Short-circuit value set by an earlier filter, normally null.
 * @param mixed $atts        Mail attributes (to, subject, message, headers, attachments, embeds).
 *
 * @return mixed
 */
function swifttrap_mailtrap_pre_wp_mail( mixed $pre_wp_mail, mixed $atts = array() ): mixed {
	// Another plugin already handled this message, or the arguments are unusable.
	if ( null !== $pre_wp_mail || ! is_array( $atts ) ) {
		return $pre_wp_mail;
	}

	$settings = swifttrap_mailtrap_get_settings();

	if ( empty( $settings['enabled'] ) || empty( $settings['token'] ) ) {
		return $pre_wp_mail;
	}

	$normalized = swifttrap_mailtrap_normalize_atts( $atts, $settings );

	if ( is_wp_error( $normalized ) ) {
		swifttrap_mailtrap_trigger_failed( $normalized, $atts );

		return false;
	}

	$category = $normalized['category'] ?? swifttrap_mailtrap_get_email_category( $normalized );
	$use_bulk = swifttrap_mailtrap_should_use_bulk_stream( $category, $settings, $normalized );
	$stream   = $use_bulk ? 'bulk' : 'transactional';

	$normalized['category'] = $category;
	$normalized['use_bulk'] = $use_bulk;

	$skipped_recipients = array();

	foreach ( array( 'to', 'cc', 'bcc' ) as $field ) {
		$kept = array();
		foreach ( $normalized[ $field ] as $rec ) {
			if ( swifttrap_mailtrap_is_recipient_suppressed( $rec['email'], $settings, $stream ) ) {
				$skipped_recipients[] = $rec['email'];
			} else {
				$kept[] = $rec;
			}
		}
		$normalized[ $field ] = $kept;
	}

	$normalized['skipped_recipients'] = $skipped_recipients;

	if ( empty( $normalized['to'] ) ) {
		$error = new WP_Error(
			'swifttrap_all_recipients_suppressed',
			/* translators: %s: comma-separated list of email addresses */
			sprintf( __( 'All recipients skipped due to suppression: %s', 'swifttrap-for-mailtrap' ), implode( ', ', $skipped_recipients ) )
		);
		swifttrap_mailtrap_trigger_failed( $error, $atts );

		return false;
	}

	$response = swifttrap_mailtrap_send( $normalized, $settings );

	if ( is_wp_error( $response ) ) {
		swifttrap_mailtrap_trigger_failed( $response, $atts );

		// A message blocked on purpose (staging guard) must not leak out through the default mailer.
		if ( 'swifttrap_bulk_blocked' === $response->get_error_code() ) {
			return false;
		}

		/**
		 * Whether a message Mailtrap did not accept is handed to the default WordPress mailer.
		 *
		 * @since 3.1.0
		 *
		 * @param bool     $fallback   True to fall back (the "Backup delivery" setting).
		 * @param WP_Error $response   Why Mailtrap did not accept the message.
		 * @param array    $normalized Normalized email data.
		 */
		$fallback = (bool) apply_filters( 'swifttrap_mailtrap_fallback_to_wp_mail', ! empty( $settings['fallback'] ?? 1 ), $response, $normalized );

		return $fallback ? null : false;
	}

	swifttrap_mailtrap_review_record_success();

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
		'enable_categories'      => 1,
		'auto_categorize'        => 1,
		'bulk_autodetect'        => 0,
		'fallback'               => 1,
		'webhook_secret'         => '',
		'max_attachment_size_mb' => 25,
		'category_streams'       => array(
			'promotional' => 'bulk',
		),
		'category_senders'       => array(),
	);
}

/**
 * Whether the API token comes from the SWIFTTRAP_MAILTRAP_TOKEN constant (wp-config.php).
 *
 * @since 3.1.0
 *
 * @return bool
 */
function swifttrap_mailtrap_token_is_constant(): bool {
	return defined( 'SWIFTTRAP_MAILTRAP_TOKEN' ) && is_string( SWIFTTRAP_MAILTRAP_TOKEN ) && '' !== SWIFTTRAP_MAILTRAP_TOKEN;
}

/**
 * Forget the per-request settings cache (the option was just written).
 *
 * @since 3.1.0
 */
function swifttrap_mailtrap_flush_settings_cache(): void {
	$GLOBALS['swifttrap_mailtrap_settings_cache'] = array();
}
add_action( 'update_option_' . SWIFTTRAP_MAILTRAP_OPTION_KEY, 'swifttrap_mailtrap_flush_settings_cache', 10, 0 );
add_action( 'add_option_' . SWIFTTRAP_MAILTRAP_OPTION_KEY, 'swifttrap_mailtrap_flush_settings_cache', 10, 0 );
add_action( 'delete_option_' . SWIFTTRAP_MAILTRAP_OPTION_KEY, 'swifttrap_mailtrap_flush_settings_cache', 10, 0 );

/**
 * Get plugin settings merged with defaults and sensible fallbacks.
 *
 * The sender here is the configured default. The `wp_mail_from` and
 * `wp_mail_from_name` filters are applied per message, in
 * swifttrap_mailtrap_normalize_atts(), because plugins such as WooCommerce
 * add them only while they send their own email.
 *
 * @return array
 */
function swifttrap_mailtrap_get_settings(): array {
	global $swifttrap_mailtrap_settings_cache;

	if ( ! is_array( $swifttrap_mailtrap_settings_cache ) ) {
		$swifttrap_mailtrap_settings_cache = array();
	}

	$blog_id = get_current_blog_id();

	if ( isset( $swifttrap_mailtrap_settings_cache[ $blog_id ] ) ) {
		return $swifttrap_mailtrap_settings_cache[ $blog_id ];
	}

	$stored   = get_option( SWIFTTRAP_MAILTRAP_OPTION_KEY, array() );
	$settings = wp_parse_args( is_array( $stored ) ? $stored : array(), swifttrap_mailtrap_default_settings() );

	if ( empty( $settings['webhook_secret'] ) ) {
		$settings['webhook_secret'] = function_exists( 'wp_generate_password' ) ? wp_generate_password( 32, false ) : bin2hex( random_bytes( 16 ) );
		update_option( SWIFTTRAP_MAILTRAP_OPTION_KEY, $settings );
	}

	// A token defined in wp-config.php wins and is never written to the database.
	if ( swifttrap_mailtrap_token_is_constant() ) {
		$settings['token'] = SWIFTTRAP_MAILTRAP_TOKEN;
	}

	$settings['token'] = is_string( $settings['token'] ) ? trim( $settings['token'] ) : '';

	if ( '' === $settings['sender_email'] ) {
		$settings['sender_email'] = (string) get_option( 'admin_email' );
	}

	if ( '' === $settings['sender_name'] ) {
		$settings['sender_name'] = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	$swifttrap_mailtrap_settings_cache[ $blog_id ] = $settings;

	return $settings;
}

/**
 * Make a string safe for the JSON API: valid UTF-8, converted from the declared charset when needed.
 *
 * @since 3.1.0
 *
 * @param string $text    Subject or body.
 * @param string $charset Charset declared in the Content-Type header, if any.
 *
 * @return string
 */
function swifttrap_mailtrap_to_utf8( string $text, string $charset = '' ): string {
	if ( '' === $text || ! function_exists( 'mb_check_encoding' ) || mb_check_encoding( $text, 'UTF-8' ) ) {
		return $text;
	}

	if ( '' === $charset ) {
		$charset = (string) get_bloginfo( 'charset' );
	}

	if ( '' !== $charset && ! in_array( strtolower( $charset ), array( 'utf-8', 'utf8' ), true ) ) {
		try {
			$converted = @mb_convert_encoding( $text, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unknown charset names warn on PHP < 8.
			if ( is_string( $converted ) && mb_check_encoding( $converted, 'UTF-8' ) ) {
				return $converted;
			}
		} catch ( \ValueError $e ) {
			unset( $e ); // Unknown charset name: fall through to scrubbing.
		}
	}

	// Last resort: drop the invalid bytes so the JSON body can still be encoded.
	return (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
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
			'embeds'      => array(),
		)
	);

	$to = $atts['to'];
	if ( ! is_array( $to ) ) {
		$to = explode( ',', (string) $to );
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
		$headers_input = explode( "\n", str_replace( "\r\n", "\n", (string) $headers_input ) );
	}

	$headers      = array();
	$cc           = array();
	$bcc          = array();
	$reply_to     = array();
	$content_type = 'text/plain';
	$charset      = '';
	$stream       = '';
	$header_from  = null;

	// Headers that describe the SMTP envelope/MIME structure. The API builds these itself.
	$dropped = array( 'to', 'subject', 'mime-version', 'content-transfer-encoding' );

	foreach ( $headers_input as $header_line ) {
		if ( ! is_string( $header_line ) || ! str_contains( $header_line, ':' ) ) {
			continue;
		}

		[ $name, $content ] = explode( ':', trim( $header_line ), 2 );

		$name    = trim( $name );
		$content = trim( $content );

		if ( '' === $name ) {
			continue;
		}

		switch ( strtolower( $name ) ) {
			case 'from':
				$from = swifttrap_mailtrap_parse_recipients( array( $content ) );
				if ( is_wp_error( $from ) ) {
					return $from;
				}
				if ( ! empty( $from ) ) {
					$header_from = $from[0];
				}
				break;
			case 'content-type':
				$parts = explode( ';', $content );
				$type  = trim( array_shift( $parts ) );
				if ( '' !== $type ) {
					$content_type = $type;
				}
				foreach ( $parts as $part ) {
					if ( preg_match( '/^\s*charset\s*=\s*"?([^";\s]+)/i', $part, $m ) ) {
						$charset = $m[1];
					}
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
			case 'x-swifttrap-stream':
				// Routing hint for this plugin only; never forwarded to the recipient.
				$content = strtolower( $content );
				if ( in_array( $content, array( 'bulk', 'transactional' ), true ) ) {
					$stream = $content;
				}
				break;
			default:
				if ( ! in_array( strtolower( $name ), $dropped, true ) ) {
					$headers[ $name ] = $content;
				}
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
	$content_type = (string) apply_filters( 'wp_mail_content_type', $content_type );

	// If content type is still plain text but the message contains HTML, switch to text/html.
	if ( false === stripos( $content_type, 'text/html' ) && swifttrap_mailtrap_message_looks_html( $atts['message'] ) ) {
		$content_type = 'text/html';
	}

	$max_mb      = (int) ( $settings['max_attachment_size_mb'] ?? 25 );
	$attachments = swifttrap_mailtrap_normalize_attachments( $atts['attachments'], $max_mb );
	if ( is_wp_error( $attachments ) ) {
		return $attachments;
	}

	// Inline images (wp_mail() $embeds, WordPress 6.9+): content ID => file path.
	if ( ! empty( $atts['embeds'] ) && is_array( $atts['embeds'] ) ) {
		foreach ( $atts['embeds'] as $content_id => $embed_path ) {
			$embed = swifttrap_mailtrap_normalize_attachments( array( $embed_path ), $max_mb );
			if ( is_wp_error( $embed ) || empty( $embed ) ) {
				continue; // Same as core: an unreadable inline image never blocks the message.
			}
			$embed[0]['content_id'] = (string) $content_id;
			$attachments[]          = $embed[0];
		}
	}

	$normalized = array(
		'to'           => $recipients,
		'cc'           => $cc,
		'bcc'          => $bcc,
		'reply_to'     => $reply_to,
		'subject'      => swifttrap_mailtrap_to_utf8( str_replace( array( "\r", "\n" ), '', (string) $atts['subject'] ), $charset ),
		'message'      => swifttrap_mailtrap_to_utf8( (string) $atts['message'], $charset ),
		'headers'      => $headers,
		'content_type' => $content_type,
		'attachments'  => $attachments,
		'stream'       => $stream,
	);

	$category               = swifttrap_mailtrap_get_email_category( $normalized );
	$normalized['category'] = $category;

	// Sender, weakest to strongest: plugin setting, per-category override, From header, core filters.
	$from_email = $settings['sender_email'] ?? '';
	$from_name  = $settings['sender_name'] ?? '';

	if ( ! empty( $settings['category_senders'][ $category ]['email'] ) ) {
		$from_email = $settings['category_senders'][ $category ]['email'];
		if ( ! empty( $settings['category_senders'][ $category ]['name'] ) ) {
			$from_name = $settings['category_senders'][ $category ]['name'];
		}
	}

	if ( null !== $header_from ) {
		$from_email = $header_from['email'];
		if ( isset( $header_from['name'] ) ) {
			$from_name = $header_from['name'];
		}
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional WP core filter override.
	$from_email = apply_filters( 'wp_mail_from', $from_email );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional WP core filter override.
	$from_name = apply_filters( 'wp_mail_from_name', $from_name );

	if ( ! is_string( $from_email ) || ! is_email( $from_email ) ) {
		return new WP_Error( 'swifttrap_invalid_sender', __( 'Sender email is invalid.', 'swifttrap-for-mailtrap' ) );
	}

	$normalized['from_email'] = $from_email;
	$normalized['from_name']  = is_string( $from_name ) ? swifttrap_mailtrap_to_utf8( $from_name, $charset ) : '';

	return $normalized;
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
 * @param mixed $recipients One address, or a list of addresses ("Name <email>" or plain).
 *
 * @return array|WP_Error
 */
function swifttrap_mailtrap_parse_recipients( mixed $recipients ): array|WP_Error {
	$parsed = array();

	foreach ( (array) $recipients as $recipient ) {
		if ( ! is_string( $recipient ) ) {
			continue;
		}

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
 * @param mixed    $attachments File paths: a list, a "file name => path" map, or a newline-separated string.
 * @param int|null $max_mb      Per-file size limit in MB. Defaults to the plugin setting.
 *
 * @return array|WP_Error
 */
function swifttrap_mailtrap_normalize_attachments( mixed $attachments, ?int $max_mb = null ): array|WP_Error {
	if ( empty( $attachments ) ) {
		return array();
	}

	if ( ! is_array( $attachments ) ) {
		$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
	}

	if ( null === $max_mb ) {
		$settings = swifttrap_mailtrap_get_settings();
		$max_mb   = (int) ( $settings['max_attachment_size_mb'] ?? 25 );
	}
	$max_mb   = max( 1, $max_mb );
	$max_size = $max_mb * MB_IN_BYTES;

	$normalized = array();

	foreach ( $attachments as $key => $attachment ) {
		if ( ! is_string( $attachment ) ) {
			continue;
		}

		$attachment = trim( $attachment );

		if ( '' === $attachment ) {
			continue;
		}

		// Never show the server path: error text can end up in logs and on screen.
		$basename = wp_basename( $attachment );

		if ( ! file_exists( $attachment ) || ! is_file( $attachment ) || ! is_readable( $attachment ) ) {
			/* translators: %s: file name of the missing attachment */
			return new WP_Error( 'swifttrap_missing_attachment', sprintf( __( 'Attachment not found or unreadable: %s', 'swifttrap-for-mailtrap' ), $basename ) );
		}

		$file_size = filesize( $attachment );
		if ( $file_size > $max_size ) {
			return new WP_Error(
				'swifttrap_attachment_too_large',
				sprintf(
					/* translators: %1$s: file name, %2$s: file size, %3$d: max size MB */
					__( 'Attachment exceeds %3$d MB limit: %1$s (%2$s)', 'swifttrap-for-mailtrap' ),
					$basename,
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
			/* translators: %s: file name of the unreadable attachment */
			return new WP_Error( 'swifttrap_unreadable_attachment', sprintf( __( 'Unable to read attachment: %s', 'swifttrap-for-mailtrap' ), $basename ) );
		}

		// wp_mail() accepts "file name => path" to rename an attachment.
		$filename = ( is_string( $key ) && '' !== trim( $key ) ) ? wp_basename( trim( $key ) ) : $basename;

		$type = 'application/octet-stream';
		$info = wp_check_filetype( $filename );

		if ( empty( $info['type'] ) ) {
			$info = wp_check_filetype( $basename );
		}

		if ( ! empty( $info['type'] ) ) {
			$type = $info['type'];
		}

		$normalized[] = array(
			'content'  => base64_encode( $contents ),
			'filename' => $filename,
			'type'     => $type,
		);
	}

	return $normalized;
}

/**
 * Short, safe description of why the Mailtrap API refused a request.
 *
 * Mailtrap answers `{"errors": ["..."]}` or `{"error": "..."}`. Only that text is
 * returned (no request data, never the token), trimmed to a readable length.
 *
 * @since 3.1.0
 *
 * @param string $body_raw Raw response body.
 *
 * @return string Empty when the body has no readable error.
 */
function swifttrap_mailtrap_api_error_detail( string $body_raw ): string {
	$data = json_decode( $body_raw, true );

	if ( ! is_array( $data ) ) {
		return '';
	}

	$found = array();
	$raw   = $data['errors'] ?? ( $data['error'] ?? ( $data['message'] ?? array() ) );

	if ( is_array( $raw ) ) {
		array_walk_recursive(
			$raw,
			static function ( $value ) use ( &$found ): void {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$found[] = trim( (string) $value );
				}
			}
		);
	} elseif ( is_scalar( $raw ) && '' !== trim( (string) $raw ) ) {
		$found[] = trim( (string) $raw );
	}

	$detail = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( implode( '; ', $found ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- plain API text, no markup expected.

	return strlen( $detail ) > 300 ? substr( $detail, 0, 297 ) . '...' : $detail;
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
	$category = $normalized['category'] ?? swifttrap_mailtrap_get_email_category( $normalized );
	$use_bulk = array_key_exists( 'use_bulk', $normalized )
		? (bool) $normalized['use_bulk']
		: swifttrap_mailtrap_should_use_bulk_stream( $category, $settings, $normalized );

	// Block bulk/promotional emails when SWIFTTRAP_BLOCK_BULK is enabled (e.g. staging).
	if ( defined( 'SWIFTTRAP_BLOCK_BULK' ) && SWIFTTRAP_BLOCK_BULK && ( $use_bulk || 'promotional' === $category ) ) {
		return new WP_Error(
			'swifttrap_bulk_blocked',
			__( 'Bulk/promotional emails are blocked on this environment.', 'swifttrap-for-mailtrap' )
		);
	}

	$host = $use_bulk
		? 'https://bulk.api.mailtrap.io/api/send'
		: 'https://send.api.mailtrap.io/api/send';

	$json = wp_json_encode( swifttrap_mailtrap_build_payload( $normalized, $category ) );

	if ( false === $json ) {
		return new WP_Error( 'swifttrap_encode_failed', __( 'The email could not be prepared for the Mailtrap API (invalid characters).', 'swifttrap-for-mailtrap' ) );
	}

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
			'body'    => $json,
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
			// This runs inside a page request (a checkout, a form submit): never wait long.
			sleep( min( $retry_after, 5 ) );
			continue;
		}

		break;
	}

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'swifttrap_send_failed', $response->get_error_message() );
	}

	$status_code = (int) wp_remote_retrieve_response_code( $response );
	$body_raw    = (string) wp_remote_retrieve_body( $response );

	if ( $status_code < 200 || $status_code >= 300 ) {
		/* translators: %d: HTTP status code returned by the Mailtrap API */
		$message = sprintf( __( 'Mailtrap API returned HTTP %d', 'swifttrap-for-mailtrap' ), $status_code );
		$detail  = swifttrap_mailtrap_api_error_detail( $body_raw );

		if ( '' !== $detail ) {
			$message .= ': ' . $detail;
		}

		return new WP_Error(
			'swifttrap_api_error',
			$message,
			array(
				'status' => $status_code,
				'stream' => $use_bulk ? 'bulk' : 'transactional',
				'body'   => $body_raw,
			)
		);
	}

	return true;
}

/**
 * Plain-text alternative of an HTML message.
 *
 * Line breaks follow <br> and block ends, a link whose label is not its URL
 * keeps the URL in brackets, and entities are decoded, so a link written as
 * `?a=1&amp;b=2` in the HTML reads `?a=1&b=2` in the text part.
 *
 * @since 3.0.4
 *
 * @param string $html HTML message.
 *
 * @return string Plain text.
 */
function swifttrap_mailtrap_html_to_text( string $html ): string {
	$text = preg_replace_callback(
		'#<a\s[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
		static function ( array $m ): string {
			$href  = trim( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$label = trim( html_entity_decode( wp_strip_all_tags( $m[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

			if ( '' === $href || $label === $href || str_starts_with( $href, 'mailto:' ) ) {
				return $m[3];
			}

			return $m[3] . ' (' . htmlspecialchars( $href, ENT_NOQUOTES, 'UTF-8' ) . ')';
		},
		$html
	);
	$text = preg_replace( '#<br\s*/?>\r?\n?#i', "\n", (string) $text );
	$text = preg_replace( '#</(p|div|li|tr|h[1-6])>\r?\n?#i', "\n", (string) $text );
	$text = wp_strip_all_tags( (string) $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
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
	$from = array( 'email' => $normalized['from_email'] );
	if ( isset( $normalized['from_name'] ) && '' !== $normalized['from_name'] ) {
		$from['name'] = $normalized['from_name'];
	}

	$payload = array(
		'from'    => $from,
		'to'      => $normalized['to'],
		'subject' => $normalized['subject'],
	);

	if ( false !== stripos( $normalized['content_type'] ?? '', 'text/html' ) ) {
		$payload['html'] = $normalized['message'];
		$payload['text'] = swifttrap_mailtrap_html_to_text( $normalized['message'] );
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

	if ( is_array( $template ) && ! empty( $template['uuid'] ) ) {
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
			$item = array(
				'content'     => $att['content'],
				'filename'    => $att['filename'],
				'type'        => $att['type'],
				'disposition' => 'attachment',
			);

			if ( isset( $att['content_id'] ) && '' !== $att['content_id'] ) {
				$item['disposition'] = 'inline';
				$item['content_id']  = $att['content_id'];
			}

			return $item;
		}, $normalized['attachments'] );
	}

	return $payload;
}

/**
 * Fire wp_mail_failed action to preserve logging hooks.
 *
 * The error carries the same mail data core attaches (to, subject, message,
 * headers, attachments), so email-log plugins can show which message failed.
 *
 * @since 1.0.0
 *
 * @param WP_Error $error The error object.
 * @param array    $atts  Original wp_mail() arguments, when known.
 * @return void
 */
function swifttrap_mailtrap_trigger_failed( WP_Error $error, array $atts = array() ): void {
	if ( ! empty( $atts ) ) {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();

		foreach ( array( 'to', 'subject', 'message', 'headers', 'attachments' ) as $key ) {
			if ( ! isset( $data[ $key ] ) && isset( $atts[ $key ] ) ) {
				$data[ $key ] = $atts[ $key ];
			}
		}

		$error = new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional WP core action.
	do_action( 'wp_mail_failed', $error );
}

/**
 * Send a test message and report what Mailtrap said about it.
 *
 * wp_mail() alone is not a reliable signal here: when Mailtrap refuses the
 * message the default mailer may still deliver it, and the test would look
 * green while the Mailtrap setup is broken.
 *
 * @since 3.1.0
 *
 * @param string $to Recipient address.
 *
 * @return true|WP_Error
 */
function swifttrap_mailtrap_send_test_email( string $to ): bool|WP_Error {
	$settings = swifttrap_mailtrap_get_settings();

	if ( empty( $settings['enabled'] ) || empty( $settings['token'] ) ) {
		return new WP_Error( 'swifttrap_not_ready', __( 'Mailtrap is not enabled or API token is missing.', 'swifttrap-for-mailtrap' ) );
	}

	if ( ! is_email( $to ) ) {
		return new WP_Error( 'swifttrap_invalid_recipient', __( 'Invalid email address.', 'swifttrap-for-mailtrap' ) );
	}

	/* translators: %s: current date and time */
	$subject = sprintf( __( 'Mailtrap Test Email — %s', 'swifttrap-for-mailtrap' ), wp_date( 'Y-m-d H:i:s' ) );
	$message = __( 'This is a test email sent from the SwiftTrap for Mailtrap plugin. If you received this, your configuration is working correctly.', 'swifttrap-for-mailtrap' );

	$failure = null;
	$capture = static function ( $error ) use ( &$failure ): void {
		if ( null === $failure && $error instanceof WP_Error ) {
			$failure = $error;
		}
	};

	add_action( 'wp_mail_failed', $capture );
	$sent = wp_mail( $to, $subject, $message, array( 'X-SwiftTrap-Stream: transactional' ) );
	remove_action( 'wp_mail_failed', $capture );

	if ( $failure instanceof WP_Error ) {
		return $failure;
	}

	if ( ! $sent ) {
		return new WP_Error( 'swifttrap_test_failed', __( 'Failed to send test email. Check your settings.', 'swifttrap-for-mailtrap' ) );
	}

	return true;
}

/**
 * Get a filesystem object that reads local files.
 *
 * Attachments live on this server, so a direct filesystem is always the right
 * one — an FTP/SSH filesystem (FS_METHOD) cannot read them without credentials.
 *
 * @since 2.2.0
 *
 * @return WP_Filesystem_Base|false Filesystem instance or false on failure.
 */
function swifttrap_mailtrap_filesystem(): object|false {
	global $wp_filesystem;

	if ( $wp_filesystem instanceof WP_Filesystem_Base && 'direct' === ( $wp_filesystem->method ?? 'direct' ) ) {
		return $wp_filesystem;
	}

	if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
	}

	return class_exists( 'WP_Filesystem_Direct' ) ? new WP_Filesystem_Direct( null ) : false;
}
