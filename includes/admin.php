<?php
/**
 * Admin settings for Mailtrap Mailer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'plugins_loaded', 'mailtrap_mailer_load_textdomain' );
add_action( 'admin_init', 'mailtrap_mailer_register_settings' );
add_action( 'admin_menu', 'mailtrap_mailer_register_menu' );
add_action( 'admin_enqueue_scripts', 'mailtrap_mailer_admin_assets' );
add_action( 'admin_notices', 'mailtrap_mailer_sdk_notice' );
add_action( 'wp_dashboard_setup', 'mailtrap_mailer_register_dashboard_widget' );

/**
 * Load translations.
 */
function mailtrap_mailer_load_textdomain() {
	load_plugin_textdomain( 'mailtrap-mailer', false, basename( dirname( __DIR__ ) ) . '/languages' );
}

/**
 * Register settings and fields.
 */
function mailtrap_mailer_register_settings() {
	register_setting(
		'mailtrap_mailer',
		MAILTRAP_MAILER_OPTION_KEY,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'mailtrap_mailer_sanitize_settings',
			'show_option_none'  => false,
			'default'           => mailtrap_mailer_default_settings(),
		)
	);

	add_settings_section(
		'mailtrap_mailer_section',
		__( 'Mailtrap Delivery', 'mailtrap-mailer' ),
		'mailtrap_mailer_section_description',
		'mailtrap-mailer-settings'
	);

	add_settings_field(
		'mailtrap_mailer_enabled',
		__( 'Enable Mailtrap', 'mailtrap-mailer' ),
		'mailtrap_mailer_field_enabled',
		'mailtrap-mailer-settings',
		'mailtrap_mailer_section'
	);

	add_settings_field(
		'mailtrap_mailer_token',
		__( 'API Token', 'mailtrap-mailer' ),
		'mailtrap_mailer_field_token',
		'mailtrap-mailer-settings',
		'mailtrap_mailer_section'
	);

	add_settings_field(
		'mailtrap_mailer_sender_email',
		__( 'Sender email', 'mailtrap-mailer' ),
		'mailtrap_mailer_field_sender_email',
		'mailtrap-mailer-settings',
		'mailtrap_mailer_section'
	);

	add_settings_field(
		'mailtrap_mailer_sender_name',
		__( 'Sender name', 'mailtrap-mailer' ),
		'mailtrap_mailer_field_sender_name',
		'mailtrap-mailer-settings',
		'mailtrap_mailer_section'
	);

	add_settings_field(
		'mailtrap_mailer_endpoint',
		__( 'API endpoint', 'mailtrap-mailer' ),
		'mailtrap_mailer_field_endpoint',
		'mailtrap-mailer-settings',
		'mailtrap_mailer_section'
	);

	add_settings_section(
		'mailtrap_mailer_logging_section',
		__( 'Email Logging', 'mailtrap-mailer' ),
		'mailtrap_mailer_logging_section_description',
		'mailtrap-mailer-settings'
	);

	add_settings_field(
		'mailtrap_mailer_log_emails',
		__( 'Log emails', 'mailtrap-mailer' ),
		'mailtrap_mailer_field_log_emails',
		'mailtrap-mailer-settings',
		'mailtrap_mailer_logging_section'
	);

	add_settings_field(
		'mailtrap_mailer_log_retention_days',
		__( 'Log retention (days)', 'mailtrap-mailer' ),
		'mailtrap_mailer_field_log_retention_days',
		'mailtrap-mailer-settings',
		'mailtrap_mailer_logging_section'
	);

	add_settings_field(
		'mailtrap_mailer_logs_per_page',
		__( 'Logs per page', 'mailtrap-mailer' ),
		'mailtrap_mailer_field_logs_per_page',
		'mailtrap-mailer-settings',
		'mailtrap_mailer_logging_section'
	);

	add_settings_section(
		'mailtrap_mailer_advanced_section',
		__( 'Advanced Settings', 'mailtrap-mailer' ),
		'mailtrap_mailer_advanced_section_description',
		'mailtrap-mailer-settings'
	);

	add_settings_field(
		'mailtrap_mailer_enable_categories',
		__( 'Email Categories', 'mailtrap-mailer' ),
		'mailtrap_mailer_field_enable_categories',
		'mailtrap-mailer-settings',
		'mailtrap_mailer_advanced_section'
	);

	add_settings_field(
		'mailtrap_mailer_auto_categorize',
		__( 'Auto-categorize emails', 'mailtrap-mailer' ),
		'mailtrap_mailer_field_auto_categorize',
		'mailtrap-mailer-settings',
		'mailtrap_mailer_advanced_section'
	);

	add_settings_field(
		'mailtrap_mailer_emails_per_hour',
		__( 'Emails per hour', 'mailtrap-mailer' ),
		'mailtrap_mailer_field_emails_per_hour',
		'mailtrap-mailer-settings',
		'mailtrap_mailer_advanced_section'
	);
}

/**
 * Enqueue admin styling for cards layout.
 */
function mailtrap_mailer_admin_assets( $hook ) {
	if ( strpos( $hook, 'mailtrap-mailer' ) === false ) {
		return;
	}

	wp_register_style(
		'mailtrap-mailer-admin',
		plugins_url( 'assets/admin.css', dirname( __FILE__ ) ),
		array(),
		MAILTRAP_MAILER_VERSION
	);
	wp_enqueue_style( 'mailtrap-mailer-admin' );
}

/**
 * Warn if SDK is missing.
 */
function mailtrap_mailer_sdk_notice() {
	// Try loading vendor; if autoload file exists, hide notice.
	if ( mailtrap_mailer_bootstrap_vendor() || mailtrap_mailer_locate_autoload() ) {
		return;
	}

	?>
	<div class="notice notice-warning">
		<p><?php esc_html_e( 'Mailtrap SDK dependencies are missing. Run composer install in the Mailtrap Mailer plugin directory to enable sending and stats.', 'mailtrap-mailer' ); ?></p>
	</div>
	<?php
}

/**
 * Add settings page to menu.
 */
function mailtrap_mailer_register_menu() {
	add_menu_page(
		__( 'Mailtrap', 'mailtrap-mailer' ),
		__( 'Mailtrap', 'mailtrap-mailer' ),
		'manage_options',
		'mailtrap-mailer',
		'mailtrap_mailer_dashboard_page',
		'dashicons-email-alt',
		58
	);

	add_submenu_page(
		'mailtrap-mailer',
		__( 'Dashboard', 'mailtrap-mailer' ),
		__( 'Dashboard', 'mailtrap-mailer' ),
		'manage_options',
		'mailtrap-mailer',
		'mailtrap_mailer_dashboard_page'
	);

	add_submenu_page(
		'mailtrap-mailer',
		__( 'Stats', 'mailtrap-mailer' ),
		__( 'Stats', 'mailtrap-mailer' ),
		'manage_options',
		'mailtrap-mailer-stats',
		'mailtrap_mailer_stats_page'
	);

	add_submenu_page(
		'mailtrap-mailer',
		__( 'Settings', 'mailtrap-mailer' ),
		__( 'Settings', 'mailtrap-mailer' ),
		'manage_options',
		'mailtrap-mailer-settings',
		'mailtrap_mailer_settings_page'
	);
}

/**
 * Register dashboard widget.
 */
function mailtrap_mailer_register_dashboard_widget() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'mailtrap_mailer_dashboard_widget',
		__( 'Mailtrap', 'mailtrap-mailer' ),
		'mailtrap_mailer_dashboard_widget_content'
	);
}

/**
 * Display dashboard widget content.
 */
function mailtrap_mailer_dashboard_widget_content() {
	$settings = mailtrap_mailer_get_settings();
	$is_ready = ! empty( $settings['enabled'] ) && ! empty( $settings['token'] );
	$stats    = mailtrap_mailer_fetch_stats( $settings );
	?>
	<div>
		<div style="margin-bottom: 15px;">
			<div style="margin-bottom: 8px;">
				<div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Status', 'mailtrap-mailer' ); ?></div>
				<div style="font-weight: 600;">
					<?php
					if ( $is_ready ) {
						echo '<span style="color: green;">✓ ' . esc_html__( 'Enabled', 'mailtrap-mailer' ) . '</span>';
					} elseif ( empty( $settings['enabled'] ) ) {
						echo '<span style="color: red;">✗ ' . esc_html__( 'Disabled', 'mailtrap-mailer' ) . '</span>';
					} else {
						echo '<span style="color: orange;">⚠ ' . esc_html__( 'Token missing', 'mailtrap-mailer' ) . '</span>';
					}
					?>
				</div>
			</div>
			<div>
				<div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Sender', 'mailtrap-mailer' ); ?></div>
				<div style="font-weight: 600; font-size: 14px;">
					<?php echo esc_html( $settings['sender_name'] . ' <' . $settings['sender_email'] . '>' ); ?>
				</div>
			</div>
		</div>

		<?php if ( ! is_wp_error( $stats ) ) : ?>
			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
				<div>
					<div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Team', 'mailtrap-mailer' ); ?></div>
					<div style="font-weight: 600; font-size: 14px;"><?php echo esc_html( $stats['team'] ?: '—' ); ?></div>
				</div>
				<div>
					<div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Plan', 'mailtrap-mailer' ); ?></div>
					<div style="font-weight: 600; font-size: 14px;"><?php echo esc_html( $stats['plan'] ?: '—' ); ?></div>
				</div>
				<div>
					<div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Credits', 'mailtrap-mailer' ); ?></div>
					<div style="font-weight: 600; font-size: 14px;">
						<?php
						if ( is_numeric( $stats['balance'] ) ) {
							echo esc_html( number_format_i18n( $stats['balance'] ) );
						} else {
							echo '—';
						}
						?>
					</div>
				</div>
				<div>
					<div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Sent this month', 'mailtrap-mailer' ); ?></div>
					<div style="font-weight: 600; font-size: 14px;"><?php echo is_numeric( $stats['monthly_sent'] ) ? esc_html( number_format_i18n( $stats['monthly_sent'] ) ) : '—'; ?></div>
				</div>
			</div>
		<?php endif; ?>

		<div>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=mailtrap-mailer' ) ); ?>">
				<?php esc_html_e( 'View Dashboard', 'mailtrap-mailer' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mailtrap-mailer-settings' ) ); ?>">
				<?php esc_html_e( 'Settings', 'mailtrap-mailer' ); ?>
			</a>
		</div>
	</div>
	<?php
}

/**
 * Settings section description.
 */
function mailtrap_mailer_section_description() {
	echo '<p>' . esc_html__( 'Send all site emails through the Mailtrap Send API. Provide an API token from your Mailtrap project.', 'mailtrap-mailer' ) . '</p>';
}

/**
 * Logging section description.
 */
function mailtrap_mailer_logging_section_description() {
	echo '<p>' . esc_html__( 'Configure email logging to keep track of sent messages.', 'mailtrap-mailer' ) . '</p>';
}

/**
 * Field: enable Mailtrap.
 */
function mailtrap_mailer_field_enabled() {
	$settings = mailtrap_mailer_get_settings();
	?>
	<label>
		<input type="checkbox" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[enabled]" value="1" <?php checked( $settings['enabled'], 1 ); ?> />
		<?php esc_html_e( 'Route wp_mail() through Mailtrap', 'mailtrap-mailer' ); ?>
	</label>
	<?php
}

/**
 * Field: API token.
 */
function mailtrap_mailer_field_token() {
	$settings = mailtrap_mailer_get_settings();
	?>
	<input type="password" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[token]" value="<?php echo esc_attr( $settings['token'] ); ?>" class="regular-text" autocomplete="off" />
	<p class="description"><?php esc_html_e( 'Use the "Send API token" from your Mailtrap inbox or project.', 'mailtrap-mailer' ); ?></p>
	<?php
}

/**
 * Field: sender email.
 */
function mailtrap_mailer_field_sender_email() {
	$settings = mailtrap_mailer_get_settings();
	?>
	<input type="email" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[sender_email]" value="<?php echo esc_attr( $settings['sender_email'] ); ?>" class="regular-text" />
	<p class="description"><?php esc_html_e( 'Address shown in the From header. Defaults to the site admin email.', 'mailtrap-mailer' ); ?></p>
	<?php
}

/**
 * Field: sender name.
 */
function mailtrap_mailer_field_sender_name() {
	$settings = mailtrap_mailer_get_settings();
	?>
	<input type="text" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[sender_name]" value="<?php echo esc_attr( $settings['sender_name'] ); ?>" class="regular-text" />
	<p class="description"><?php esc_html_e( 'Display name for the From header. Defaults to the site title.', 'mailtrap-mailer' ); ?></p>
	<?php
}

/**
 * Field: API endpoint.
 */
function mailtrap_mailer_field_endpoint() {
	$settings = mailtrap_mailer_get_settings();
	?>
	<input type="text" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[endpoint]" value="<?php echo esc_attr( $settings['endpoint'] ); ?>" class="regular-text code" />
	<p class="description"><?php esc_html_e( 'Override only if Mailtrap provides a custom endpoint.', 'mailtrap-mailer' ); ?></p>
	<?php
}

/**
 * Field: Log emails.
 */
function mailtrap_mailer_field_log_emails() {
	$settings = mailtrap_mailer_get_settings();
	?>
	<label>
		<input type="checkbox" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[log_emails]" value="1" <?php checked( $settings['log_emails'], 1 ); ?> />
		<?php esc_html_e( 'Keep detailed logs of all sent emails', 'mailtrap-mailer' ); ?>
	</label>
	<p class="description"><?php esc_html_e( 'Logs will be stored in wp-content/uploads/mailtrap-mailer/mailtrap-emails.log', 'mailtrap-mailer' ); ?></p>
	<?php
}

/**
 * Field: Log retention days.
 */
function mailtrap_mailer_field_log_retention_days() {
	$settings = mailtrap_mailer_get_settings();
	?>
	<input type="number" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[log_retention_days]" value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>" class="small-text" min="1" max="365" />
	<p class="description"><?php esc_html_e( 'How long to keep email logs (1-365 days). Old logs are automatically deleted.', 'mailtrap-mailer' ); ?></p>
	<?php
}

/**
 * Field: Logs per page.
 */
function mailtrap_mailer_field_logs_per_page() {
	$settings = mailtrap_mailer_get_settings();
	?>
	<input type="number" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[logs_per_page]" value="<?php echo esc_attr( $settings['logs_per_page'] ); ?>" class="small-text" min="5" max="100" />
	<p class="description"><?php esc_html_e( 'Number of email logs to display per page on Stats page (5-100).', 'mailtrap-mailer' ); ?></p>
	<?php
}

/**
 * Settings page markup.
 */
function mailtrap_mailer_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = mailtrap_mailer_get_settings();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Mailtrap Settings', 'mailtrap-mailer' ); ?></h1>
		<?php settings_errors(); ?>
        <form action="options.php" method="post">
            <?php settings_fields( 'mailtrap_mailer' ); ?>

            <div class="mailtrap-grid">
                <!-- Mailtrap Delivery Section -->
                <div class="mailtrap-card">
                    <h3><?php esc_html_e( 'Mailtrap Delivery', 'mailtrap-mailer' ); ?></h3>
                    <p><?php esc_html_e( 'Send all site emails through the Mailtrap Send API. Provide an API token from your Mailtrap project.', 'mailtrap-mailer' ); ?></p>

                    <!-- Enable Mailtrap -->
                    <div class="mailtrap-field-group">
                        <label class="mailtrap-checkbox-label">
                            <input type="checkbox" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[enabled]" value="1" <?php checked( $settings['enabled'], 1 ); ?> />
                            <?php esc_html_e( 'Route wp_mail() through Mailtrap', 'mailtrap-mailer' ); ?>
                        </label>
                    </div>

                    <!-- API Token -->
                    <div class="mailtrap-field-group">
                        <label class="mailtrap-field-label"><?php esc_html_e( 'API Token', 'mailtrap-mailer' ); ?></label>
                        <input type="password" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[token]" value="<?php echo esc_attr( $settings['token'] ); ?>" class="mailtrap-input-full" autocomplete="off" />
                        <p class="mailtrap-field-help"><?php esc_html_e( 'Use the "Send API token" from your Mailtrap inbox or project.', 'mailtrap-mailer' ); ?></p>
                    </div>

                    <!-- Sender Email -->
                    <div class="mailtrap-field-group">
                        <label class="mailtrap-field-label"><?php esc_html_e( 'Sender email', 'mailtrap-mailer' ); ?></label>
                        <input type="email" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[sender_email]" value="<?php echo esc_attr( $settings['sender_email'] ); ?>" class="mailtrap-input-full" />
                        <p class="mailtrap-field-help"><?php esc_html_e( 'Address shown in the From header. Defaults to the site admin email.', 'mailtrap-mailer' ); ?></p>
                    </div>

                    <!-- Sender Name -->
                    <div class="mailtrap-field-group">
                        <label class="mailtrap-field-label"><?php esc_html_e( 'Sender name', 'mailtrap-mailer' ); ?></label>
                        <input type="text" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[sender_name]" value="<?php echo esc_attr( $settings['sender_name'] ); ?>" class="mailtrap-input-full" />
                        <p class="mailtrap-field-help"><?php esc_html_e( 'Display name for the From header. Defaults to the site title.', 'mailtrap-mailer' ); ?></p>
                    </div>

                    <!-- API Endpoint -->
                    <div class="mailtrap-field-group">
                        <label class="mailtrap-field-label"><?php esc_html_e( 'API endpoint', 'mailtrap-mailer' ); ?></label>
                        <input type="text" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[endpoint]" value="<?php echo esc_attr( $settings['endpoint'] ); ?>" class="mailtrap-input-full mailtrap-input-mono" />
                        <p class="mailtrap-field-help"><?php esc_html_e( 'Override only if Mailtrap provides a custom endpoint.', 'mailtrap-mailer' ); ?></p>
                    </div>
                </div>

                <!-- Email Logging Section -->
                <div class="mailtrap-card">
                    <h3><?php esc_html_e( 'Email Logging', 'mailtrap-mailer' ); ?></h3>
                    <p><?php esc_html_e( 'Configure email logging to keep track of sent messages.', 'mailtrap-mailer' ); ?></p>

                    <!-- Log Emails -->
                    <div class="mailtrap-field-group">
						<label class="mailtrap-checkbox-label">
							<input type="checkbox" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[log_emails]" value="1" <?php checked( $settings['log_emails'], 1 ); ?> />
							<?php esc_html_e( 'Keep detailed logs of all sent emails', 'mailtrap-mailer' ); ?>
						</label>
						<p class="mailtrap-field-help"><?php esc_html_e( 'Logs will be stored in wp-content/uploads/mailtrap-mailer/mailtrap-emails.log', 'mailtrap-mailer' ); ?></p>
                    </div>

                    <!-- Log Retention Days -->
                    <div class="mailtrap-field-group">
                        <label class="mailtrap-field-label"><?php esc_html_e( 'Log retention (days)', 'mailtrap-mailer' ); ?></label>
                        <input type="number" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[log_retention_days]" value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>" class="mailtrap-input-small" min="1" max="365" />
                        <p class="mailtrap-field-help"><?php esc_html_e( 'How long to keep email logs (1-365 days). Old logs are automatically deleted.', 'mailtrap-mailer' ); ?></p>
                    </div>

                    <!-- Logs Per Page -->
                    <div class="mailtrap-field-group">
                        <label class="mailtrap-field-label"><?php esc_html_e( 'Logs per page', 'mailtrap-mailer' ); ?></label>
                        <input type="number" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[logs_per_page]" value="<?php echo esc_attr( $settings['logs_per_page'] ); ?>" class="mailtrap-input-small" min="5" max="100" />
                        <p class="mailtrap-field-help"><?php esc_html_e( 'Number of email logs to display per page on Stats page (5-100).', 'mailtrap-mailer' ); ?></p>
                    </div>
                </div>

                <!-- Advanced Settings Section -->
                <div class="mailtrap-card">
                    <h3><?php esc_html_e( 'Advanced Settings', 'mailtrap-mailer' ); ?></h3>
                    <p><?php esc_html_e( 'Advanced email delivery options for more control over Mailtrap streams.', 'mailtrap-mailer' ); ?></p>

                    <!-- Email Categories -->
                    <div class="mailtrap-field-group">
                        <label class="mailtrap-checkbox-label">
                            <input type="checkbox" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[enable_categories]" value="1" <?php checked( $settings['enable_categories'], 1 ); ?> />
                            <?php esc_html_e( 'Add category tags to emails in Mailtrap', 'mailtrap-mailer' ); ?>
                        </label>
                        <p class="mailtrap-field-help"><?php esc_html_e( 'Categories help organize emails in Mailtrap dashboard (welcome, password-reset, notification, etc.).', 'mailtrap-mailer' ); ?></p>
                    </div>

                    <!-- Auto-categorize -->
                    <div class="mailtrap-field-group">
                        <label class="mailtrap-checkbox-label">
                            <input type="checkbox" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[auto_categorize]" value="1" <?php checked( $settings['auto_categorize'], 1 ); ?> />
                            <?php esc_html_e( 'Automatically detect email type from subject line', 'mailtrap-mailer' ); ?>
                        </label>
                        <p class="mailtrap-field-help"><?php esc_html_e( 'When enabled, emails are categorized by keywords in subject: "reset" → password-reset, "confirm" → verification, "welcome" → welcome, etc.', 'mailtrap-mailer' ); ?></p>
                    </div>

                    <!-- Emails Per Hour -->
                    <div class="mailtrap-field-group">
                        <label class="mailtrap-field-label"><?php esc_html_e( 'Emails per hour', 'mailtrap-mailer' ); ?></label>
                        <input type="number" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[emails_per_hour]" value="<?php echo esc_attr( $settings['emails_per_hour'] ); ?>" class="mailtrap-input-small" min="0" max="10000" />
                        <p class="mailtrap-field-help"><?php esc_html_e( 'Maximum emails to send per hour. Default is 150 (Mailtrap Basic plan limit). Set to 0 to disable rate limiting. Adjust based on your plan limits.', 'mailtrap-mailer' ); ?></p>
                    </div>
                </div>
            </div>

            <?php submit_button(); ?>
        </form>
	</div>
	<?php
}

/**
 * Dashboard page markup.
 */
function mailtrap_mailer_dashboard_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = mailtrap_mailer_get_settings();
	$is_ready = ! empty( $settings['enabled'] ) && ! empty( $settings['token'] );
	$stats    = mailtrap_mailer_fetch_stats( $settings );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Mailtrap Dashboard', 'mailtrap-mailer' ); ?></h1>
		<div class="mailtrap-grid">
			<div class="mailtrap-card">
				<h2><?php esc_html_e( 'Account', 'mailtrap-mailer' ); ?></h2>

				<div class="mailtrap-account-section">
					<div class="mailtrap-info-block">
						<div class="mailtrap-info-label"><?php esc_html_e( 'Status', 'mailtrap-mailer' ); ?></div>
						<div class="mailtrap-info-value">
							<?php
							if ( $is_ready ) {
								echo '<span class="mailtrap-status-enabled">✓ ' . esc_html__( 'Enabled', 'mailtrap-mailer' ) . '</span>';
							} elseif ( empty( $settings['enabled'] ) ) {
								echo '<span class="mailtrap-status-disabled">✗ ' . esc_html__( 'Disabled', 'mailtrap-mailer' ) . '</span>';
							} else {
								echo '<span class="mailtrap-status-warning">⚠ ' . esc_html__( 'Token missing', 'mailtrap-mailer' ) . '</span>';
							}
							?>
						</div>
					</div>
					<div class="mailtrap-info-block">
						<div class="mailtrap-info-label"><?php esc_html_e( 'Sender', 'mailtrap-mailer' ); ?></div>
						<div class="mailtrap-info-value">
							<?php echo esc_html( $settings['sender_name'] . ' <' . $settings['sender_email'] . '>' ); ?>
						</div>
					</div>
					<div class="mailtrap-account-buttons">
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=mailtrap-mailer-settings' ) ); ?>">
							<?php esc_html_e( 'Settings', 'mailtrap-mailer' ); ?>
						</a>
					</div>
				</div>

				<?php if ( ! is_wp_error( $stats ) ) : ?>
					<div class="mailtrap-stats-grid">
						<div>
							<div class="mailtrap-info-label"><?php esc_html_e( 'Team', 'mailtrap-mailer' ); ?></div>
							<div class="mailtrap-info-value"><?php echo esc_html( $stats['team'] ?: '—' ); ?></div>
						</div>
						<div>
							<div class="mailtrap-info-label"><?php esc_html_e( 'Plan', 'mailtrap-mailer' ); ?></div>
							<div class="mailtrap-info-value"><?php echo esc_html( $stats['plan'] ?: '—' ); ?></div>
						</div>
						<div>
							<div class="mailtrap-info-label"><?php esc_html_e( 'Credits', 'mailtrap-mailer' ); ?></div>
							<div class="mailtrap-info-value">
								<?php
								if ( is_numeric( $stats['balance'] ) ) {
									echo esc_html( number_format_i18n( $stats['balance'] ) );
								} else {
									echo '—';
								}
								?>
							</div>
						</div>
						<div>
							<div class="mailtrap-info-label"><?php esc_html_e( 'Sent this month', 'mailtrap-mailer' ); ?></div>
							<div class="mailtrap-info-value"><?php echo is_numeric( $stats['monthly_sent'] ) ? esc_html( number_format_i18n( $stats['monthly_sent'] ) ) : '—'; ?></div>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Format email log entry for display
 *
 * @param array $entry Log entry from mailtrap-emails.log.
 *
 * @return array Formatted entry with display strings.
 */
function mailtrap_mailer_format_log_entry( $entry ) {
	if ( ! is_array( $entry ) ) {
		return null;
	}

	$status_color = ( 'success' === $entry['status'] ) ? '#28a745' : '#dc3545';
	$status_label = ( 'success' === $entry['status'] ) ? __( 'Success', 'mailtrap-mailer' ) : __( 'Failed', 'mailtrap-mailer' );

	// Format recipient list.
	$recipients = array();
	if ( ! empty( $entry['to'] ) && is_array( $entry['to'] ) ) {
		foreach ( $entry['to'] as $to ) {
			$email = $to['email'] ?? '';
			$name  = $to['name'] ?? '';
			$recipients[] = $name ? "{$name} <{$email}>" : $email;
		}
	}

	return array(
		'timestamp'   => $entry['timestamp'] ? date( 'd.m.Y H:i', strtotime( $entry['timestamp'] ) ) : '',
		'status'      => $status_label,
		'status_color' => $status_color,
		'from'        => $entry['from'] ?? '',
		'to'          => implode( ', ', $recipients ),
		'subject'     => $entry['subject'] ?? '',
		'http_status' => $entry['http_status'] ?? '—',
		'message'     => $entry['message'] ?? '',
	);
}

/**
 * Stats page placeholder.
 */
function mailtrap_mailer_stats_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = mailtrap_mailer_get_settings();
	$stats    = mailtrap_mailer_fetch_stats( $settings );

	// Get pagination parameters.
	$page = max( 1, (int) ( $_GET['mailtrap_page'] ?? 1 ) );
	$per_page = $settings['logs_per_page'];

	// Read all logs and calculate pagination.
	$all_logs = mailtrap_mailer_read_email_logs( 500 );
	$total_logs = count( $all_logs );
	$total_pages = max( 1, (int) ceil( $total_logs / $per_page ) );
	$page = min( $page, $total_pages );

	// Get logs for current page.
	$start_index = ( $page - 1 ) * $per_page;
	$logs = array_slice( $all_logs, $start_index, $per_page );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Mailtrap Stats', 'mailtrap-mailer' ); ?></h1>
		<div class="mailtrap-grid">
			<div class="mailtrap-card">
				<h2><?php esc_html_e( 'Usage', 'mailtrap-mailer' ); ?></h2>
				<?php if ( is_wp_error( $stats ) ) : ?>
					<p><?php echo esc_html( $stats->get_error_message() ); ?></p>
				<?php else : ?>
					<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
						<div>
							<div style="font-size: 13px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Sent this month', 'mailtrap-mailer' ); ?></div>
							<div style="font-weight: 600; font-size: 15px;"><?php echo is_numeric( $stats['monthly_sent'] ) ? esc_html( number_format_i18n( $stats['monthly_sent'] ) ) : '—'; ?></div>
						</div>
						<div>
							<div style="font-size: 13px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Credits', 'mailtrap-mailer' ); ?></div>
							<div style="font-weight: 600; font-size: 15px;">
								<?php
								if ( is_numeric( $stats['balance'] ) ) {
									echo esc_html( number_format_i18n( $stats['balance'] ) );
								} else {
									echo '—';
								}
								?>
							</div>
						</div>
						<div>
							<div style="font-size: 13px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Plan', 'mailtrap-mailer' ); ?></div>
							<div style="font-weight: 600; font-size: 15px;"><?php echo esc_html( $stats['plan'] ?: '—' ); ?></div>
						</div>
						<div>
							<div style="font-size: 13px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Team', 'mailtrap-mailer' ); ?></div>
							<div style="font-weight: 600; font-size: 15px;"><?php echo esc_html( $stats['team'] ?: '—' ); ?></div>
						</div>
					</div>
				<?php endif; ?>
			</div>
			<div class="mailtrap-card">
				<h2><?php esc_html_e( 'Mailtrap', 'mailtrap-mailer' ); ?></h2>
				<p><?php esc_html_e( 'For full logs, events, and deliverability reports open Mailtrap.', 'mailtrap-mailer' ); ?></p>
				<p><a class="button" href="https://mailtrap.io/home" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Mailtrap', 'mailtrap-mailer' ); ?></a></p>
			</div>
		</div>

		<div style="margin-top: 30px;">
			<div class="mailtrap-card">
				<div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
					<h2 style="margin: 0; padding: 0;"><?php esc_html_e( 'Recent Email Logs', 'mailtrap-mailer' ); ?></h2>
					<span style="font-size: 12px; color: #666;">
						<?php
						$timezone = get_option( 'timezone_string' );
						if ( empty( $timezone ) ) {
							$offset = get_option( 'gmt_offset' );
							$timezone = 'UTC' . ( $offset >= 0 ? '+' : '' ) . $offset;
						}
						printf( esc_html__( 'Times in %s', 'mailtrap-mailer' ), esc_html( $timezone ) );
						?>
					</span>
				</div>
				<?php if ( empty( $logs ) ) : ?>
					<p><?php esc_html_e( 'No email logs found. Enable logging in Settings.', 'mailtrap-mailer' ); ?></p>
				<?php else : ?>
					<div style="overflow-x: auto;">
						<table style="width: 100%; font-size: 13px;">
							<thead>
								<tr style="border-bottom: 2px solid #eee;">
									<th style="padding: 12px; text-align: left; font-weight: 600; color: #333; width: 30%;"><?php esc_html_e( 'To', 'mailtrap-mailer' ); ?></th>
									<th style="padding: 12px; text-align: left; font-weight: 600; color: #333; width: 20%;"><?php esc_html_e( 'From', 'mailtrap-mailer' ); ?></th>
									<th style="padding: 12px; text-align: left; font-weight: 600; color: #333; width: 25%;"><?php esc_html_e( 'Date', 'mailtrap-mailer' ); ?></th>
									<th style="padding: 12px; text-align: left; font-weight: 600; color: #333; width: 15%;"><?php esc_html_e( 'Status', 'mailtrap-mailer' ); ?></th>
									<th style="padding: 12px; text-align: left; font-weight: 600; color: #333; width: 10%;"><?php esc_html_e( 'HTTP', 'mailtrap-mailer' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $logs as $index => $log_entry ) : ?>
									<?php $formatted = mailtrap_mailer_format_log_entry( $log_entry ); ?>
									<?php if ( $formatted ) : ?>
										<tr style="border-bottom: 1px solid #eee; hover-background: #f9f9f9;">
											<td style="padding: 12px; color: #333;">
												<span title="<?php echo esc_attr( $formatted['to'] ); ?>">
													<?php echo esc_html( wp_html_excerpt( $formatted['to'], 40, '...' ) ); ?>
												</span>
											</td>
											<td style="padding: 12px; color: #666;">
												<?php echo esc_html( wp_html_excerpt( $formatted['from'], 25, '...' ) ); ?>
											</td>
											<td style="padding: 12px; color: #666; font-size: 12px;">
												<?php echo esc_html( $formatted['timestamp'] ); ?>
											</td>
											<td style="padding: 12px;">
												<strong style="color: <?php echo esc_attr( $formatted['status_color'] ); ?>;">
													<?php echo esc_html( $formatted['status'] ); ?>
												</strong>
											</td>
											<td style="padding: 12px; color: #999; font-size: 12px;">
												<?php echo esc_html( $formatted['http_status'] ); ?>
											</td>
										</tr>
									<?php endif; ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<?php if ( $total_pages > 1 ) : ?>
					<div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; text-align: center;">
						<nav style="display: flex; justify-content: center; gap: 10px; align-items: center; margin-bottom: 15px;">
							<?php if ( $page > 1 ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'mailtrap_page', 1 ) ); ?>" class="button"><?php esc_html_e( 'First', 'mailtrap-mailer' ); ?></a>
								<a href="<?php echo esc_url( add_query_arg( 'mailtrap_page', $page - 1 ) ); ?>" class="button"><?php esc_html_e( 'Previous', 'mailtrap-mailer' ); ?></a>
							<?php endif; ?>

							<span style="color: #666; font-size: 14px;">
								<?php printf( esc_html__( 'Page %1$d of %2$d', 'mailtrap-mailer' ), $page, $total_pages ); ?>
							</span>

							<?php if ( $page < $total_pages ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'mailtrap_page', $page + 1 ) ); ?>" class="button"><?php esc_html_e( 'Next', 'mailtrap-mailer' ); ?></a>
								<a href="<?php echo esc_url( add_query_arg( 'mailtrap_page', $total_pages ) ); ?>" class="button"><?php esc_html_e( 'Last', 'mailtrap-mailer' ); ?></a>
							<?php endif; ?>
						</nav>
						<p style="color: #666; font-size: 12px; margin: 0;">
							<?php printf( esc_html__( 'Showing %1$d-%2$d of %3$d total logs', 'mailtrap-mailer' ),
								$total_logs > 0 ? $start_index + 1 : 0,
								min( $start_index + $per_page, $total_logs ),
								$total_logs
							); ?>
						</p>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Advanced section description.
 */
function mailtrap_mailer_advanced_section_description() {
	echo '<p>' . esc_html__( 'Advanced email delivery options for more control over Mailtrap streams.', 'mailtrap-mailer' ) . '</p>';
}

/**
 * Field: Enable categories.
 */
function mailtrap_mailer_field_enable_categories() {
	$settings = mailtrap_mailer_get_settings();
	?>
	<label>
		<input type="checkbox" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[enable_categories]" value="1" <?php checked( $settings['enable_categories'], 1 ); ?> />
		<?php esc_html_e( 'Add category tags to emails in Mailtrap', 'mailtrap-mailer' ); ?>
	</label>
	<p class="description"><?php esc_html_e( 'Categories help organize emails in Mailtrap dashboard (welcome, password-reset, notification, etc.).', 'mailtrap-mailer' ); ?></p>
	<?php
}

/**
 * Field: Auto-categorize.
 */
function mailtrap_mailer_field_auto_categorize() {
	$settings = mailtrap_mailer_get_settings();
	?>
	<label>
		<input type="checkbox" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[auto_categorize]" value="1" <?php checked( $settings['auto_categorize'], 1 ); ?> />
		<?php esc_html_e( 'Automatically detect email type from subject line', 'mailtrap-mailer' ); ?>
	</label>
	<p class="description"><?php esc_html_e( 'When enabled, emails are categorized by keywords in subject: "reset" → password-reset, "confirm" → verification, "welcome" → welcome, etc.', 'mailtrap-mailer' ); ?></p>
	<?php
}

/**
 * Field: Email rate limiting - emails per hour.
 */
function mailtrap_mailer_field_emails_per_hour() {
	$settings = mailtrap_mailer_get_settings();
	?>
	<input type="number" name="<?php echo esc_attr( MAILTRAP_MAILER_OPTION_KEY ); ?>[emails_per_hour]" value="<?php echo esc_attr( $settings['emails_per_hour'] ); ?>" class="small-text" min="0" max="10000" />
	<p class="description"><?php esc_html_e( 'Maximum emails to send per hour. Default is 150 (Mailtrap Basic plan limit). Set to 0 to disable rate limiting. Adjust based on your plan limits.', 'mailtrap-mailer' ); ?></p>
	<?php
}

/**
 * Sanitize settings before save.
 *
 * @param array $settings .
 *
 * @return array
 */
function mailtrap_mailer_sanitize_settings( $settings ) {
	$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), mailtrap_mailer_default_settings() );

	$settings['enabled'] = empty( $settings['enabled'] ) ? 0 : 1;
	$settings['token']   = sanitize_text_field( $settings['token'] );

	$settings['sender_email'] = sanitize_email( $settings['sender_email'] );
	$settings['sender_name']  = sanitize_text_field( $settings['sender_name'] );

	$endpoint = trim( $settings['endpoint'] );
	$endpoint = $endpoint ? esc_url_raw( $endpoint ) : '';
	if ( '' === $endpoint ) {
		$endpoint = mailtrap_mailer_default_settings()['endpoint'];
	}

	$settings['endpoint'] = $endpoint;

	// Logging settings.
	$settings['log_emails'] = empty( $settings['log_emails'] ) ? 0 : 1;
	$retention_days = (int) ( $settings['log_retention_days'] ?? 30 );
	$settings['log_retention_days'] = max( 1, min( 365, $retention_days ) );

	// Display settings.
	$logs_per_page = (int) ( $settings['logs_per_page'] ?? 10 );
	$settings['logs_per_page'] = max( 5, min( 100, $logs_per_page ) );

	// Advanced settings.
	$settings['enable_categories'] = empty( $settings['enable_categories'] ) ? 0 : 1;
	$settings['auto_categorize'] = empty( $settings['auto_categorize'] ) ? 0 : 1;

	// Rate limiting settings - convert emails_per_hour to delay in milliseconds.
	$emails_per_hour = (int) ( $settings['emails_per_hour'] ?? 150 );
	$emails_per_hour = max( 0, min( 10000, $emails_per_hour ) );
	$settings['emails_per_hour'] = $emails_per_hour;

	// Calculate delay: 3600000ms per hour / emails_per_hour = milliseconds per email
	// If emails_per_hour is 0, disable rate limiting by setting delay to 0
	if ( $emails_per_hour > 0 ) {
		$settings['email_delay_ms'] = intval( 3600000 / $emails_per_hour );
	} else {
		$settings['email_delay_ms'] = 0;
	}

	return $settings;
}
