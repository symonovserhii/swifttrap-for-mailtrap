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

delete_option( 'swifttrap_mailtrap_settings' );
delete_transient( 'swifttrap_mailtrap_stats' );
delete_transient( 'swifttrap_account_id' );
delete_transient( 'swifttrap_account_data' );
delete_transient( 'swifttrap_domains' );
delete_transient( 'swifttrap_suppressions' );
delete_transient( 'swifttrap_log_stats_7' );

// Remove log directory using WP_Filesystem.
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
