<?php
/**
 * Remove plugin data on uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'mailtrap_mailer_settings' );
