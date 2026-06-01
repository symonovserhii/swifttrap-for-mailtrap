<?php
/**
 * SwiftTrap for Mailtrap WP-CLI commands.
 *
 * @package SwiftTrapForMailtrap
 * @since   2.4.0
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * SwiftTrap WP-CLI Commands.
 */
class SwiftTrap_CLI {
	/**
	 * Send a test email via Mailtrap.
	 *
	 * ## OPTIONS
	 *
	 * [--to=<email>]
	 * : Recipient email address. Defaults to the configured sender email.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swifttrap test --to=user@example.com
	 *
	 * @param array $args       Command positional arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function test( array $args, array $assoc_args ): void {
		$settings = swifttrap_mailtrap_get_settings();
		$to       = $assoc_args['to'] ?? $settings['sender_email'];

		if ( empty( $to ) ) {
			WP_CLI::error( 'No recipient email specified.' );
		}

		WP_CLI::log( sprintf( 'Sending test email to %s...', $to ) );
		$result = wp_mail( $to, 'SwiftTrap Test Email', 'This is a test email sent from WP-CLI.' );

		if ( $result ) {
			WP_CLI::success( 'Test email sent successfully.' );
		} else {
			WP_CLI::error( 'Failed to send test email.' );
		}
	}

	/**
	 * Show Mailtrap account usage and statistics.
	 *
	 * @param array $args       Command positional arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function stats( array $args, array $assoc_args ): void {
		$settings = swifttrap_mailtrap_get_settings();
		$stats    = swifttrap_mailtrap_fetch_stats( $settings );

		if ( is_wp_error( $stats ) ) {
			WP_CLI::error( 'Failed to fetch stats: ' . $stats->get_error_message() );
		}

		WP_CLI::log( 'Mailtrap Stats:' );
		WP_CLI::log( sprintf( '  Team: %s', $stats['team'] ) );
		WP_CLI::log( sprintf( '  Plan: %s', $stats['plan'] ) );
		if ( isset( $stats['monthly_sent'] ) ) {
			WP_CLI::log( sprintf( '  Usage: %d / %d sent', $stats['monthly_sent'], $stats['monthly_limit'] ) );
		} else {
			WP_CLI::log( '  Usage data unavailable.' );
		}
	}

	/**
	 * Clean old email logs.
	 *
	 * @param array $args       Command positional arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function prune_logs( array $args, array $assoc_args ): void {
		WP_CLI::log( 'Pruning old logs...' );
		swifttrap_mailtrap_cleanup_logs();
		WP_CLI::success( 'Logs pruned successfully.' );
	}

	/**
	 * Sync and refresh Mailtrap suppressions.
	 *
	 * @param array $args       Command positional arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function send_suppression_sync( array $args, array $assoc_args ): void {
		WP_CLI::log( 'Syncing suppressions from Mailtrap API...' );
		$settings   = swifttrap_mailtrap_get_settings();
		$token_hash = substr( md5( $settings['token'] ?? '' ), 0, 8 );
		delete_transient( 'swifttrap_suppressions_' . $token_hash );

		$suppressions = swifttrap_mailtrap_fetch_suppressions( $settings );

		if ( is_wp_error( $suppressions ) ) {
			WP_CLI::error( 'Failed to sync suppressions: ' . $suppressions->get_error_message() );
		}

		$total = $suppressions['summary']['total'] ?? 0;
		WP_CLI::success( sprintf( 'Suppressions synced. Total suppressed: %d', $total ) );
	}
}

WP_CLI::add_command( 'swifttrap', 'SwiftTrap_CLI' );
