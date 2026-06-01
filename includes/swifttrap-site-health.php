<?php
/**
 * SwiftTrap for Mailtrap Site Health integration.
 *
 * @package SwiftTrapForMailtrap
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'site_status_tests', 'swifttrap_mailtrap_register_site_health_test' );

/**
 * Register SwiftTrap status test in Site Health.
 *
 * @param array $tests Array of registered status tests.
 *
 * @return array
 */
function swifttrap_mailtrap_register_site_health_test( array $tests ): array {
	$tests['direct']['swifttrap_mailtrap_status'] = array(
		'label' => __( 'SwiftTrap for Mailtrap Status', 'swifttrap-for-mailtrap' ),
		'test'  => 'swifttrap_mailtrap_perform_site_health_test',
	);
	return $tests;
}

/**
 * Perform Site Health status checks.
 *
 * @return array
 */
function swifttrap_mailtrap_perform_site_health_test(): array {
	$settings = swifttrap_mailtrap_get_settings();
	$result   = array(
		'label'       => __( 'SwiftTrap for Mailtrap is configured correctly', 'swifttrap-for-mailtrap' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Mailtrap', 'swifttrap-for-mailtrap' ),
			'color' => 'blue',
		),
		'description' => __( 'SwiftTrap for Mailtrap routes your WordPress emails through the Mailtrap Send API.', 'swifttrap-for-mailtrap' ),
		'actions'     => '',
	);

	if ( empty( $settings['enabled'] ) ) {
		$result['status']      = 'recommended';
		$result['label']       = __( 'SwiftTrap for Mailtrap is disabled', 'swifttrap-for-mailtrap' );
		$result['description'] = __( 'Mailtrap routing is currently disabled. Emails are sent using the default WordPress mail handler.', 'swifttrap-for-mailtrap' );
		return $result;
	}

	if ( empty( $settings['token'] ) ) {
		$result['status']      = 'critical';
		$result['label']       = __( 'Mailtrap API token is missing', 'swifttrap-for-mailtrap' );
		$result['description'] = __( 'You must provide a Mailtrap API token in settings to route emails.', 'swifttrap-for-mailtrap' );
		return $result;
	}

	// Verify token validity & domain verification.
	$account = swifttrap_mailtrap_get_account_data( $settings );
	if ( is_wp_error( $account ) ) {
		$result['status']      = 'critical';
		$result['label']       = __( 'Mailtrap API connection failed', 'swifttrap-for-mailtrap' );
		$result['description'] = sprintf( __( 'Failed to connect to Mailtrap API: %s', 'swifttrap-for-mailtrap' ), $account->get_error_message() );
		return $result;
	}

	// Check if sender email domain is verified in Mailtrap.
	$sender_email = $settings['sender_email'];
	$domain_parts = explode( '@', $sender_email );
	$sender_domain = end( $domain_parts );

	$domains = swifttrap_mailtrap_fetch_domains( $settings );
	if ( is_wp_error( $domains ) ) {
		$result['status']      = 'recommended';
		$result['label']       = __( 'Unable to verify Mailtrap sending domains', 'swifttrap-for-mailtrap' );
		$result['description'] = sprintf( __( 'Connected to Mailtrap, but could not retrieve domains list: %s', 'swifttrap-for-mailtrap' ), $domains->get_error_message() );
		return $result;
	}

	$domain_found    = false;
	$domain_verified = false;
	foreach ( $domains as $domain ) {
		if ( strcasecmp( $domain['name'], $sender_domain ) === 0 ) {
			$domain_found = true;
			if ( ! empty( $domain['verified'] ) ) {
				$domain_verified = true;
			}
			break;
		}
	}

	if ( ! $domain_found ) {
		$result['status']      = 'critical';
		$result['label']       = sprintf( __( 'Sender domain "%s" is not registered in Mailtrap', 'swifttrap-for-mailtrap' ), $sender_domain );
		$result['description'] = sprintf( __( 'The sender email address domain (%s) must be added to your Sending Domains in Mailtrap.', 'swifttrap-for-mailtrap' ), $sender_domain );
	} elseif ( ! $domain_verified ) {
		$result['status']      = 'critical';
		$result['label']       = sprintf( __( 'Sender domain "%s" is unverified in Mailtrap', 'swifttrap-for-mailtrap' ), $sender_domain );
		$result['description'] = sprintf( __( 'The sender email address domain (%s) is registered but has pending DNS verification in Mailtrap.', 'swifttrap-for-mailtrap' ), $sender_domain );
	} else {
		$result['label']       = __( 'SwiftTrap for Mailtrap is routing emails correctly', 'swifttrap-for-mailtrap' );
		$result['description'] = sprintf( __( 'API token is valid and sender domain "%s" is verified.', 'swifttrap-for-mailtrap' ), $sender_domain );
	}

	return $result;
}
