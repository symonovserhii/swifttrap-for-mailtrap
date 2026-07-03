<?php
/**
 * SwiftTrap for Mailtrap uninstall handler.
 *
 * Removes plugin options, transients, and log files on uninstall.
 *
 * @package SwiftTrapForMailtrap
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$swifttrap_settings = get_option( 'swifttrap_mailtrap_settings', array() );
$swifttrap_token    = is_array( $swifttrap_settings ) ? ( $swifttrap_settings['token'] ?? '' ) : '';

if ( '' !== $swifttrap_token ) {
	// Cache keys are suffixed with a token hash (see includes/swifttrap-api.php); read the
	// token before delete_option() below removes the only place it's stored.
	$swifttrap_token_hash = substr( md5( $swifttrap_token ), 0, 8 );
	delete_transient( 'swifttrap_mailtrap_stats_' . $swifttrap_token_hash );
	delete_transient( 'swifttrap_account_data_' . $swifttrap_token_hash );
	delete_transient( 'swifttrap_domains_' . $swifttrap_token_hash );
	delete_transient( 'swifttrap_suppressions_' . $swifttrap_token_hash );
}

delete_option( 'swifttrap_mailtrap_settings' );

// Remove a legacy log directory left behind by installs upgraded from pre-3.0.0
// (file-based email logging was replaced by the live Mailtrap API log in 3.0.0).
$swifttrap_uploads = wp_upload_dir( null, false );
if ( empty( $swifttrap_uploads['error'] ) ) {
	$swifttrap_log_dir = trailingslashit( $swifttrap_uploads['basedir'] ) . 'swifttrap-for-mailtrap';
	if ( is_dir( $swifttrap_log_dir ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->rmdir( $swifttrap_log_dir, true );
		}
	}
}
