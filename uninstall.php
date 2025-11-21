<?php
/**
 * Remove plugin data on uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'mailtrap_mailer_settings' );

// Remove saved logs.
$log_paths = array();

$uploads = wp_upload_dir( null, false );
if ( empty( $uploads['error'] ) ) {
	$log_paths[] = trailingslashit( $uploads['basedir'] ) . 'mailtrap-mailer/mailtrap-emails.log';
}

// Legacy location (pre 1.1.1).
$log_paths[] = WP_CONTENT_DIR . '/mailtrap-emails.log';

if ( ! function_exists( 'wp_delete_file' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}

foreach ( $log_paths as $log_path ) {
	if ( file_exists( $log_path ) && is_file( $log_path ) ) {
		$log_directory = dirname( $log_path );

		if ( ! empty( $log_directory ) && is_writable( $log_directory ) ) {
			if ( function_exists( 'wp_delete_file' ) ) {
				wp_delete_file( $log_path );
			} else {
				unlink( $log_path );
			}
		}
	}
}

// Remove empty log directory.
if ( ! empty( $uploads['basedir'] ) ) {
	$log_dir = trailingslashit( $uploads['basedir'] ) . 'mailtrap-mailer';
	if ( is_dir( $log_dir ) && is_readable( $log_dir ) && is_writable( dirname( $log_dir ) ) ) {
		$log_dir_contents = scandir( $log_dir );

		if ( false !== $log_dir_contents && 0 === count( array_diff( $log_dir_contents, array( '.', '..' ) ) ) ) {
			rmdir( $log_dir );
		}
	}
}
