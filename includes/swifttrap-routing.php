<?php
/**
 * Stream routing: which messages go through Mailtrap's bulk stream.
 *
 * @package SwiftTrapForMailtrap
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Newsletter plugins whose campaign emails are recognized automatically.
 *
 * Only plugins that send through wp_mail() and mark their campaigns with a
 * header no ordinary email carries are listed. Plugins that send with their
 * own mailer (MailPoet) never reach this plugin, and plugins without a
 * reliable marker are left to the `swifttrap_mailtrap_bulk_source` filter.
 *
 * @return array<string,array{label:string}>
 */
function swifttrap_mailtrap_bulk_sources(): array {
	return array(
		'newsletter' => array( 'label' => 'Newsletter' ),
		'fluentcrm'  => array( 'label' => 'FluentCRM' ),
		'icegram'    => array( 'label' => 'Icegram Express' ),
		'groundhogg' => array( 'label' => 'Groundhogg' ),
	);
}

/**
 * Find a header value regardless of the header name's letter case.
 *
 * @param array  $headers Header name => value.
 * @param string $name    Header name.
 *
 * @return string|null Null when the header is absent.
 */
function swifttrap_mailtrap_get_header( array $headers, string $name ): ?string {
	foreach ( $headers as $key => $value ) {
		if ( is_string( $key ) && 0 === strcasecmp( $key, $name ) ) {
			return is_scalar( $value ) ? (string) $value : '';
		}
	}

	return null;
}

/**
 * Recognize a campaign email sent by a known newsletter plugin.
 *
 * - Newsletter: `X-Newsletter-Email-Id`, set by its sending engine only.
 * - FluentCRM: a `List-Unsubscribe` link with `fluentcrm=1` and a campaign
 *   email ID (`ce_id`); double opt-in mail has the link but no `ce_id`.
 * - Icegram Express: `Campaign-ID:` inside the `List-Unsubscribe` mailto.
 * - Groundhogg: `List-Id` together with `Precedence: bulk` (it sets
 *   `Precedence: bulk` on transactional mail too, `List-Id` only on marketing).
 *
 * @param array $normalized Normalized email data.
 *
 * @return string Source slug (a key of swifttrap_mailtrap_bulk_sources()), or '' when not recognized.
 */
function swifttrap_mailtrap_detect_bulk_source( array $normalized ): string {
	$headers = is_array( $normalized['headers'] ?? null ) ? $normalized['headers'] : array();
	$source  = '';

	if ( ! empty( $headers ) ) {
		$unsubscribe = (string) swifttrap_mailtrap_get_header( $headers, 'List-Unsubscribe' );
		$precedence  = strtolower( trim( (string) swifttrap_mailtrap_get_header( $headers, 'Precedence' ) ) );

		if ( null !== swifttrap_mailtrap_get_header( $headers, 'X-Newsletter-Email-Id' ) ) {
			$source = 'newsletter';
		} elseif ( false !== stripos( $unsubscribe, 'fluentcrm=1' ) && preg_match( '/[?&]ce_id=\d+/i', $unsubscribe ) ) {
			$source = 'fluentcrm';
		} elseif ( false !== stripos( $unsubscribe, 'Campaign-ID:' ) ) {
			$source = 'icegram';
		} elseif ( 'bulk' === $precedence && null !== swifttrap_mailtrap_get_header( $headers, 'List-Id' ) ) {
			$source = 'groundhogg';
		}
	}

	/**
	 * Which newsletter plugin (or other bulk sender) this email comes from.
	 *
	 * Return any non-empty string to treat the email as bulk mail when
	 * "Send newsletters through the bulk stream" is on.
	 *
	 * @since 3.1.0
	 *
	 * @param string $source     Detected source slug, '' when not recognized.
	 * @param array  $normalized Normalized email data (to, subject, message, headers, ...).
	 */
	$source = apply_filters( 'swifttrap_mailtrap_bulk_source', $source, $normalized );

	return is_string( $source ) ? $source : '';
}

/**
 * Determine if email should use bulk stream.
 *
 * Strongest first: the `swifttrap_mailtrap_use_bulk_stream` filter, the
 * `X-SwiftTrap-Stream` header, newsletter plugin detection (when switched on),
 * the category mapping from the settings screen.
 *
 * @param string $category   Email category.
 * @param array  $settings   Plugin settings.
 * @param array  $normalized Normalized email data, when known.
 *
 * @return bool Whether to use bulk stream.
 */
function swifttrap_mailtrap_should_use_bulk_stream( string $category, array $settings, array $normalized = array() ): bool {
	$streams  = $settings['category_streams'] ?? array( 'promotional' => 'bulk' );
	$use_bulk = isset( $streams[ $category ] ) && 'bulk' === $streams[ $category ];
	$explicit = $normalized['stream'] ?? '';

	if ( 'bulk' === $explicit ) {
		$use_bulk = true;
	} elseif ( 'transactional' === $explicit ) {
		$use_bulk = false;
	} elseif ( ! $use_bulk && ! empty( $settings['bulk_autodetect'] ) && ! empty( $normalized ) ) {
		$use_bulk = '' !== swifttrap_mailtrap_detect_bulk_source( $normalized );
	}

	/**
	 * Force a message into the bulk (true) or transactional (false) stream.
	 *
	 * @param bool   $use_bulk   Decision so far.
	 * @param string $category   Email category.
	 * @param array  $settings   Plugin settings.
	 * @param array  $normalized Normalized email data (since 3.1.0).
	 */
	return (bool) apply_filters( 'swifttrap_mailtrap_use_bulk_stream', $use_bulk, $category, $settings, $normalized );
}

/**
 * Keep Newsletter's signup emails on the transactional stream.
 *
 * Confirmation and welcome emails built in Newsletter's composer are sent by
 * the same engine as campaigns and carry the same headers. The email type is
 * only known here, so they are marked for the transactional stream.
 *
 * @param mixed $headers Header name => value.
 * @param mixed $email   Newsletter email object (has ->type).
 *
 * @return mixed
 */
function swifttrap_mailtrap_newsletter_headers( mixed $headers, mixed $email = null ): mixed {
	if ( ! is_array( $headers ) || ! is_object( $email ) ) {
		return $headers;
	}

	$settings = swifttrap_mailtrap_get_settings();

	if ( empty( $settings['enabled'] ) || empty( $settings['token'] ) ) {
		return $headers;
	}

	if ( in_array( (string) ( $email->type ?? '' ), array( 'welcome', 'confirmation' ), true ) ) {
		$headers['X-SwiftTrap-Stream'] = 'transactional';
	}

	return $headers;
}
add_filter( 'newsletter_message_headers', 'swifttrap_mailtrap_newsletter_headers', 20, 2 );
