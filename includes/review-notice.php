<?php
/**
 * One-time, dismissible request for a WordPress.org review.
 *
 * Shown only after the first email has actually gone out through Mailtrap and a
 * week has passed, only to administrators, and only on the plugin's own
 * screens. "Maybe later" snoozes it for 30 days; "Don't ask again" hides it for
 * good. Nothing is sent anywhere — the state lives in one local option.
 *
 * @package SwiftTrapForMailtrap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SWIFTTRAP_MAILTRAP_REVIEW_OPTION = 'swifttrap_mailtrap_review_state';
const SWIFTTRAP_MAILTRAP_REVIEW_ACTION = 'swifttrap_mailtrap_review_action';
const SWIFTTRAP_MAILTRAP_REVIEW_URL    = 'https://wordpress.org/support/plugin/swifttrap-for-mailtrap/reviews/#new-post';

/**
 * Stored state.
 *
 * @return array<string,mixed>
 */
function swifttrap_mailtrap_review_get_state(): array {
	$state = get_option( SWIFTTRAP_MAILTRAP_REVIEW_OPTION, array() );

	return is_array( $state ) ? $state : array();
}

/**
 * Remember when the first email went out through Mailtrap (the "it works" signal).
 * Cheap on the hot path: one cached option read, one write ever.
 */
function swifttrap_mailtrap_review_record_success(): void {
	$state = swifttrap_mailtrap_review_get_state();

	if ( empty( $state['first_sent_at'] ) ) {
		$state['first_sent_at'] = time();
		update_option( SWIFTTRAP_MAILTRAP_REVIEW_OPTION, $state );
	}
}

/**
 * Pure decision: should the notice be visible at $now for this state?
 *
 * @param array<string,mixed> $state Stored state.
 * @param int                 $now   Current timestamp.
 */
function swifttrap_mailtrap_review_is_due( array $state, int $now ): bool {
	if ( 'done' === ( $state['status'] ?? '' ) ) {
		return false;
	}

	$first_sent_at = (int) ( $state['first_sent_at'] ?? 0 );
	if ( $first_sent_at <= 0 || $now < $first_sent_at + WEEK_IN_SECONDS ) {
		return false;
	}

	return $now >= (int) ( $state['snooze_until'] ?? 0 );
}

/**
 * Nonce-protected admin-post URL for a choice.
 *
 * @param string $choice 'later' or 'done'.
 */
function swifttrap_mailtrap_review_action_url( string $choice ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => SWIFTTRAP_MAILTRAP_REVIEW_ACTION,
				'choice' => $choice,
			),
			admin_url( 'admin-post.php' )
		),
		SWIFTTRAP_MAILTRAP_REVIEW_ACTION
	);
}

/**
 * Print the notice on this plugin's screens.
 */
function swifttrap_mailtrap_review_render(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'toplevel_page_swifttrap-for-mailtrap', 'mailtrap_page_swifttrap-for-mailtrap-settings' ), true ) ) {
		return;
	}

	if ( ! swifttrap_mailtrap_review_is_due( swifttrap_mailtrap_review_get_state(), time() ) ) {
		return;
	}
	?>
	<div class="notice notice-info">
		<p>
			<strong><?php esc_html_e( 'Enjoying SwiftTrap for Mailtrap?', 'swifttrap-for-mailtrap' ); ?></strong>
			<?php esc_html_e( 'Your emails have been going out through Mailtrap. If the plugin saves you time, a short review on WordPress.org helps other sites find it.', 'swifttrap-for-mailtrap' ); ?>
		</p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( SWIFTTRAP_MAILTRAP_REVIEW_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Leave a review', 'swifttrap-for-mailtrap' ); ?></a>
			<a class="button" href="<?php echo esc_url( swifttrap_mailtrap_review_action_url( 'later' ) ); ?>"><?php esc_html_e( 'Maybe later', 'swifttrap-for-mailtrap' ); ?></a>
			<a class="button-link" href="<?php echo esc_url( swifttrap_mailtrap_review_action_url( 'done' ) ); ?>"><?php esc_html_e( "Already did / don't ask again", 'swifttrap-for-mailtrap' ); ?></a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'swifttrap_mailtrap_review_render' );

/**
 * Handle "Maybe later" / "Don't ask again".
 */
function swifttrap_mailtrap_review_handle_action(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'swifttrap-for-mailtrap' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( SWIFTTRAP_MAILTRAP_REVIEW_ACTION );

	$choice = isset( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() above.
	$state  = swifttrap_mailtrap_review_get_state();

	if ( 'done' === $choice ) {
		$state['status'] = 'done';
	} elseif ( 'later' === $choice ) {
		$state['snooze_until'] = time() + 30 * DAY_IN_SECONDS;
	}

	update_option( SWIFTTRAP_MAILTRAP_REVIEW_OPTION, $state );

	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=swifttrap-for-mailtrap' ) );
	exit;
}
add_action( 'admin_post_' . SWIFTTRAP_MAILTRAP_REVIEW_ACTION, 'swifttrap_mailtrap_review_handle_action' );
