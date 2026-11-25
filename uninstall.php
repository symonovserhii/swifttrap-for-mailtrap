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

/**
 * Remove this plugin's options and cached API data from the current site.
 */
function swifttrap_mailtrap_uninstall_site(): void {
	$settings = get_option( 'swifttrap_mailtrap_settings', array() );
	$tokens   = array( is_array( $settings ) ? (string) ( $settings['token'] ?? '' ) : '' );

	if ( defined( 'SWIFTTRAP_MAILTRAP_TOKEN' ) && is_string( SWIFTTRAP_MAILTRAP_TOKEN ) ) {
		$tokens[] = SWIFTTRAP_MAILTRAP_TOKEN;
	}

	// Cache keys are suffixed with a token hash (see includes/swifttrap-api.php); read the
	// token before delete_option() below removes the only place it's stored.
	foreach ( array_unique( $tokens ) as $token ) {
		$hash = substr( md5( $token ), 0, 8 );
		delete_transient( 'swifttrap_mailtrap_stats_' . $hash );
		delete_transient( 'swifttrap_account_data_' . $hash );
		delete_transient( 'swifttrap_domains_' . $hash );
		delete_transient( 'swifttrap_suppressions_' . $hash );
		delete_transient( 'swifttrap_suppressions_down_' . $hash );
	}

	delete_option( 'swifttrap_mailtrap_settings' );
	delete_option( 'swifttrap_mailtrap_review_state' );
	wp_clear_scheduled_hook( 'swifttrap_mailtrap_cleanup_logs_cron' );
}

if ( is_multisite() && ! wp_is_large_network() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $swifttrap_site_id ) {
		switch_to_blog( (int) $swifttrap_site_id );
		swifttrap_mailtrap_uninstall_site();
		restore_current_blog();
	}
} else {
	swifttrap_mailtrap_uninstall_site();
}

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
