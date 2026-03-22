<?php
/**
 * Admin settings and UI for SwiftTrap for Mailtrap.
 *
 * @package SwiftTrapForMailtrap
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


add_action( 'admin_init', 'swifttrap_mailtrap_register_settings' );
add_action( 'admin_menu', 'swifttrap_mailtrap_register_menu' );
add_action( 'admin_enqueue_scripts', 'swifttrap_mailtrap_admin_assets' );
add_action( 'wp_dashboard_setup', 'swifttrap_mailtrap_register_dashboard_widget' );

/**
 * Register settings and fields.
 */
function swifttrap_mailtrap_register_settings() {
	register_setting(
		'swifttrap_mailtrap',
		SWIFTTRAP_MAILTRAP_OPTION_KEY,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'swifttrap_mailtrap_sanitize_settings',
			'show_option_none'  => false,
			'default'           => swifttrap_mailtrap_default_settings(),
		)
	);
}

/**
 * Enqueue admin styling for cards layout.
 */
function swifttrap_mailtrap_admin_assets( $hook ) {
	$is_swifttrap_page = strpos( $hook, 'swifttrap-for-mailtrap' ) !== false;
	$is_dashboard     = 'index.php' === $hook;

	if ( ! $is_swifttrap_page && ! $is_dashboard ) {
		return;
	}

	wp_register_style(
		'swifttrap-for-mailtrap-admin',
		plugins_url( 'assets/admin.css', dirname( __FILE__ ) ),
		array(),
		SWIFTTRAP_MAILTRAP_VERSION
	);
	wp_enqueue_style( 'swifttrap-for-mailtrap-admin' );

	if ( ! $is_swifttrap_page ) {
		return;
	}

	wp_add_inline_script( 'jquery-core', '
		jQuery(function($){
			// Send Test Email
			$(document).on("click","#swifttrap-send-test",function(){
				var btn = $(this), result = $("#swifttrap-test-result");
				btn.prop("disabled",true).text("' . esc_js( __( 'Sending...', 'swifttrap-for-mailtrap' ) ) . '");
				result.text("").removeClass("swifttrap-test-result--success swifttrap-test-result--error");
				$.post(ajaxurl,{action:"swifttrap_send_test_email",_nonce:btn.data("nonce")},function(r){
					btn.prop("disabled",false).text("' . esc_js( __( 'Send Test Email', 'swifttrap-for-mailtrap' ) ) . '");
					if(r.success){
						result.addClass("swifttrap-test-result--success").text(r.data.message);
					}else{
						result.addClass("swifttrap-test-result--error").text(r.data.message);
					}
				}).fail(function(){
					btn.prop("disabled",false).text("' . esc_js( __( 'Send Test Email', 'swifttrap-for-mailtrap' ) ) . '");
					result.addClass("swifttrap-test-result--error").text("' . esc_js( __( 'Request failed.', 'swifttrap-for-mailtrap' ) ) . '");
				});
			});
			// Clear Logs
			$(document).on("click","#swifttrap-clear-logs",function(){
				if(!confirm("' . esc_js( __( 'Are you sure you want to clear all email logs?', 'swifttrap-for-mailtrap' ) ) . '")) return;
				var btn = $(this);
				btn.prop("disabled",true);
				$.post(ajaxurl,{action:"swifttrap_clear_logs",_nonce:btn.data("nonce")},function(r){
					if(r.success){ location.reload(); }
					else{ alert(r.data.message); btn.prop("disabled",false); }
				}).fail(function(){ btn.prop("disabled",false); });
			});
		});
	' );

	// Stats page: localized strings for AJAX API loading.
	wp_localize_script( 'jquery-core', 'swifttrapStats', array(
		'nonce' => wp_create_nonce( 'swifttrap_load_api_data' ),
		'l10n'  => array(
			'sent'           => __( 'sent', 'swifttrap-for-mailtrap' ),
			'limit'          => __( 'limit', 'swifttrap-for-mailtrap' ),
			'plan'           => __( 'Plan', 'swifttrap-for-mailtrap' ),
			'team'           => __( 'Team', 'swifttrap-for-mailtrap' ),
			'usageError'     => __( 'Unable to load usage data.', 'swifttrap-for-mailtrap' ),
			'noDomains'      => __( 'No sending domains found.', 'swifttrap-for-mailtrap' ),
			'verified'       => __( 'Verified', 'swifttrap-for-mailtrap' ),
			'pending'        => __( 'Pending', 'swifttrap-for-mailtrap' ),
			'domainsError'   => __( 'Unable to load domains.', 'swifttrap-for-mailtrap' ),
			'bounce'         => __( 'Bounce', 'swifttrap-for-mailtrap' ),
			'complaint'      => __( 'Complaint', 'swifttrap-for-mailtrap' ),
			'unsub'          => __( 'Unsub', 'swifttrap-for-mailtrap' ),
			'manual'         => __( 'Manual', 'swifttrap-for-mailtrap' ),
			'noSuppressions' => __( 'No suppressions found.', 'swifttrap-for-mailtrap' ),
			'supError'       => __( 'Unable to load suppressions.', 'swifttrap-for-mailtrap' ),
			'loadError'      => __( 'Failed to load data. Please refresh the page.', 'swifttrap-for-mailtrap' ),
		),
	) );

	// Stats page: load Mailtrap API data asynchronously.
	wp_add_inline_script( 'jquery-core', '
		jQuery(function($){
			var $u=$("#swifttrap-usage-card .swifttrap-card__body");
			if(!$u.length)return;
			var L=swifttrapStats.l10n;
			function esc(t){return $("<span>").text(t).html();}
			function err(m){return "<p class=\"swifttrap-error\">"+esc(m)+"</p>";}
			$.post(ajaxurl,{action:"swifttrap_load_api_data",_nonce:swifttrapStats.nonce},function(r){
				if(!r.success){
					var e=err(r.data&&r.data.message||L.loadError);
					$u.html(e);$("#swifttrap-domains-card .swifttrap-card__body").html(e);
					$("#swifttrap-suppressions-card .swifttrap-card__body").html(e);return;
				}
				var api=r.data;
				/* --- Usage --- */
				if(api.stats&&!api.stats.error){
					var s=api.stats,sent=s.monthly_sent||0,lim=s.monthly_limit,
						pct=lim?Math.round(sent/lim*100):0,
						mod=pct>90?" swifttrap-quota-bar__fill--critical":pct>70?" swifttrap-quota-bar__fill--warning":"",
						h="";
					if(lim){
						h+="<div class=\"swifttrap-quota-bar\"><div class=\"swifttrap-quota-bar__fill"+mod+"\" style=\"width:"+Math.min(pct,100)+"%\"></div></div>";
						h+="<div class=\"swifttrap-quota-bar__label\"><span>"+sent.toLocaleString()+" "+esc(L.sent)+"</span><span>"+lim.toLocaleString()+" "+esc(L.limit)+"</span></div>";
					}
					h+="<div class=\"swifttrap-stats-grid\">";
					h+="<div class=\"swifttrap-info-block\"><div class=\"swifttrap-info-label\">"+esc(L.plan)+"</div><div class=\"swifttrap-info-value\">"+esc(s.plan||"\u2014")+"</div></div>";
					h+="<div class=\"swifttrap-info-block\"><div class=\"swifttrap-info-label\">"+esc(L.team)+"</div><div class=\"swifttrap-info-value\">"+esc(s.team||"\u2014")+"</div></div>";
					h+="</div>";
					$u.html(h);
				}else{
					$u.html(err(api.stats&&api.stats.error||L.usageError));
				}
				/* --- Domains --- */
				var $d=$("#swifttrap-domains-card .swifttrap-card__body");
				if(api.domains&&!api.domains.error){
					if(!api.domains.length){$d.html("<p>"+esc(L.noDomains)+"</p>");}
					else{
						var dh="<ul class=\"swifttrap-domain-list\">";
						$.each(api.domains,function(i,dm){
							dh+="<li><div class=\"swifttrap-domain-header\">";
							dh+="<span class=\"swifttrap-domain-name\">"+esc(dm.name)+"</span>";
							var sc=dm.verified?"swifttrap-domain-status--verified":"swifttrap-domain-status--pending";
							dh+="<span class=\""+sc+"\">"+(dm.verified?esc(L.verified):esc(L.pending))+"</span>";
							dh+="</div>";
							if(dm.dns){
								dh+="<div class=\"swifttrap-dns-records\">";
								$.each(dm.dns,function(k,v){
									dh+="<span class=\""+(v==="valid"?"swifttrap-dns-pass":"swifttrap-dns-fail")+"\">"+esc(k+": "+v)+"</span>";
								});
								dh+="</div>";
							}
							dh+="</li>";
						});
						dh+="</ul>";$d.html(dh);
					}
				}else{
					$d.html(err(api.domains&&api.domains.error||L.domainsError));
				}
				/* --- Suppressions --- */
				var $s=$("#swifttrap-suppressions-card .swifttrap-card__body");
				if(api.suppressions&&!api.suppressions.error){
					var sp=api.suppressions,sh="";
					if(sp.summary){
						var sm=sp.summary;
						sh+="<div class=\"swifttrap-suppressions-summary\">";
						sh+="<div class=\"swifttrap-stats-summary__item\"><span class=\"swifttrap-stats-summary__label\">"+esc(L.bounce)+"</span><span class=\"swifttrap-stats-summary__value\">"+sm.bounce+"</span></div>";
						sh+="<div class=\"swifttrap-stats-summary__item\"><span class=\"swifttrap-stats-summary__label\">"+esc(L.complaint)+"</span><span class=\"swifttrap-stats-summary__value\">"+sm.complaint+"</span></div>";
						sh+="<div class=\"swifttrap-stats-summary__item\"><span class=\"swifttrap-stats-summary__label\">"+esc(L.unsub)+"</span><span class=\"swifttrap-stats-summary__value\">"+sm.unsubscribe+"</span></div>";
						sh+="<div class=\"swifttrap-stats-summary__item\"><span class=\"swifttrap-stats-summary__label\">"+esc(L.manual)+"</span><span class=\"swifttrap-stats-summary__value\">"+sm.manual+"</span></div>";
						sh+="</div>";
					}
					if(sp.items&&sp.items.length>0){
						sh+="<ul class=\"swifttrap-suppression-list\">";
						for(var i=0,mx=Math.min(sp.items.length,5);i<mx;i++){
							var it=sp.items[i];
							sh+="<li><span class=\"swifttrap-suppression-email\" title=\""+esc(it.email)+"\">"+esc(it.email)+"</span>";
							sh+="<span class=\"swifttrap-suppression-meta\">";
							sh+="<span class=\"swifttrap-suppression-reason swifttrap-suppression-reason--"+it.reason+"\">"+esc(it.reason)+"</span>";
							if(it.created_at){sh+="<span class=\"swifttrap-suppression-date\">"+new Date(it.created_at).toLocaleDateString()+"</span>";}
							sh+="</span></li>";
						}
						sh+="</ul>";
					}else if(!sp.summary||sp.summary.total===0){
						sh="<p>"+esc(L.noSuppressions)+"</p>";
					}
					$s.html(sh);
				}else{
					$s.html(err(api.suppressions&&api.suppressions.error||L.supError));
				}
			}).fail(function(){
				var e=err(L.loadError);
				$u.html(e);$("#swifttrap-domains-card .swifttrap-card__body").html(e);
				$("#swifttrap-suppressions-card .swifttrap-card__body").html(e);
			});
		});
	' );
}

/**
 * Add settings page to menu.
 */
function swifttrap_mailtrap_register_menu() {
	add_menu_page(
		__( 'Mailtrap', 'swifttrap-for-mailtrap' ),
		__( 'Mailtrap', 'swifttrap-for-mailtrap' ),
		'manage_options',
		'swifttrap-for-mailtrap',
		'swifttrap_mailtrap_stats_page',
		'dashicons-email-alt',
		58
	);

	add_submenu_page(
		'swifttrap-for-mailtrap',
		__( 'Stats', 'swifttrap-for-mailtrap' ),
		__( 'Stats', 'swifttrap-for-mailtrap' ),
		'manage_options',
		'swifttrap-for-mailtrap',
		'swifttrap_mailtrap_stats_page'
	);

	add_submenu_page(
		'swifttrap-for-mailtrap',
		__( 'Settings', 'swifttrap-for-mailtrap' ),
		__( 'Settings', 'swifttrap-for-mailtrap' ),
		'manage_options',
		'swifttrap-for-mailtrap-settings',
		'swifttrap_mailtrap_settings_page'
	);
}

/**
 * Register dashboard widget.
 */
function swifttrap_mailtrap_register_dashboard_widget() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'swifttrap_mailtrap_dashboard_widget',
		__( 'Mailtrap', 'swifttrap-for-mailtrap' ),
		'swifttrap_mailtrap_dashboard_widget_content'
	);
}

/**
 * Display dashboard widget content.
 */
function swifttrap_mailtrap_dashboard_widget_content() {
	$settings = swifttrap_mailtrap_get_settings();
	$is_ready = ! empty( $settings['enabled'] ) && ! empty( $settings['token'] );
	?>
	<div class="swifttrap-widget">
		<div class="swifttrap-widget-info">
			<div class="swifttrap-info-block">
				<div class="swifttrap-info-label"><?php esc_html_e( 'Status', 'swifttrap-for-mailtrap' ); ?></div>
				<div class="swifttrap-info-value">
					<?php
					if ( $is_ready ) {
						echo '<span class="swifttrap-status-enabled">' . esc_html__( 'Enabled', 'swifttrap-for-mailtrap' ) . '</span>';
					} elseif ( empty( $settings['enabled'] ) ) {
						echo '<span class="swifttrap-status-disabled">' . esc_html__( 'Disabled', 'swifttrap-for-mailtrap' ) . '</span>';
					} else {
						echo '<span class="swifttrap-status-warning">' . esc_html__( 'Token missing', 'swifttrap-for-mailtrap' ) . '</span>';
					}
					?>
				</div>
			</div>
			<div class="swifttrap-info-block">
				<div class="swifttrap-info-label"><?php esc_html_e( 'Sender', 'swifttrap-for-mailtrap' ); ?></div>
				<div class="swifttrap-info-value">
					<?php echo esc_html( $settings['sender_name'] . ' <' . $settings['sender_email'] . '>' ); ?>
				</div>
			</div>
		</div>
		<div class="swifttrap-widget-actions">
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=swifttrap-for-mailtrap' ) ); ?>">
				<?php esc_html_e( 'View Stats', 'swifttrap-for-mailtrap' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=swifttrap-for-mailtrap-settings' ) ); ?>">
				<?php esc_html_e( 'Settings', 'swifttrap-for-mailtrap' ); ?>
			</a>
		</div>
	</div>
	<?php
}


/**
 * Settings page markup.
 */
function swifttrap_mailtrap_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = swifttrap_mailtrap_get_settings();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Mailtrap Settings', 'swifttrap-for-mailtrap' ); ?></h1>
		<?php settings_errors(); ?>
        <form action="options.php" method="post">
            <?php settings_fields( 'swifttrap_mailtrap' ); ?>

            <div class="swifttrap-grid">
                <!-- Mailtrap Delivery Section -->
                <div class="swifttrap-card">
                    <h3><?php esc_html_e( 'Mailtrap Delivery', 'swifttrap-for-mailtrap' ); ?></h3>
                    <p><?php esc_html_e( 'Send all site emails through the Mailtrap Send API. Provide an API token from your Mailtrap project.', 'swifttrap-for-mailtrap' ); ?></p>

                    <!-- Enable Mailtrap -->
                    <div class="swifttrap-field-group">
                        <label class="swifttrap-checkbox-label">
                            <input type="checkbox" name="<?php echo esc_attr( SWIFTTRAP_MAILTRAP_OPTION_KEY ); ?>[enabled]" value="1" <?php checked( $settings['enabled'], 1 ); ?> />
                            <?php esc_html_e( 'Route wp_mail() through Mailtrap', 'swifttrap-for-mailtrap' ); ?>
                        </label>
                    </div>

                    <!-- API Token -->
                    <div class="swifttrap-field-group">
                        <label class="swifttrap-field-label"><?php esc_html_e( 'API Token', 'swifttrap-for-mailtrap' ); ?></label>
                        <input type="password" name="<?php echo esc_attr( SWIFTTRAP_MAILTRAP_OPTION_KEY ); ?>[token]" value="<?php echo esc_attr( $settings['token'] ); ?>" class="swifttrap-input-full" autocomplete="off" />
                        <p class="swifttrap-field-help"><?php esc_html_e( 'Use the "Send API token" from your Mailtrap inbox or project.', 'swifttrap-for-mailtrap' ); ?></p>
                    </div>

                    <!-- Sender Email -->
                    <div class="swifttrap-field-group">
                        <label class="swifttrap-field-label"><?php esc_html_e( 'Sender email', 'swifttrap-for-mailtrap' ); ?></label>
                        <input type="email" name="<?php echo esc_attr( SWIFTTRAP_MAILTRAP_OPTION_KEY ); ?>[sender_email]" value="<?php echo esc_attr( $settings['sender_email'] ); ?>" class="swifttrap-input-full" />
                        <p class="swifttrap-field-help"><?php esc_html_e( 'Address shown in the From header. Defaults to the site admin email.', 'swifttrap-for-mailtrap' ); ?></p>
                    </div>

                    <!-- Sender Name -->
                    <div class="swifttrap-field-group">
                        <label class="swifttrap-field-label"><?php esc_html_e( 'Sender name', 'swifttrap-for-mailtrap' ); ?></label>
                        <input type="text" name="<?php echo esc_attr( SWIFTTRAP_MAILTRAP_OPTION_KEY ); ?>[sender_name]" value="<?php echo esc_attr( $settings['sender_name'] ); ?>" class="swifttrap-input-full" />
                        <p class="swifttrap-field-help"><?php esc_html_e( 'Display name for the From header. Defaults to the site title.', 'swifttrap-for-mailtrap' ); ?></p>
                    </div>

                </div>

                <!-- Email Logging Section -->
                <div class="swifttrap-card">
                    <h3><?php esc_html_e( 'Email Logging', 'swifttrap-for-mailtrap' ); ?></h3>
                    <p><?php esc_html_e( 'Configure email logging to keep track of sent messages.', 'swifttrap-for-mailtrap' ); ?></p>

                    <!-- Log Emails -->
                    <div class="swifttrap-field-group">
						<label class="swifttrap-checkbox-label">
							<input type="checkbox" name="<?php echo esc_attr( SWIFTTRAP_MAILTRAP_OPTION_KEY ); ?>[log_emails]" value="1" <?php checked( $settings['log_emails'], 1 ); ?> />
							<?php esc_html_e( 'Keep detailed logs of all sent emails', 'swifttrap-for-mailtrap' ); ?>
						</label>
						<p class="swifttrap-field-help"><?php esc_html_e( 'Logs will be stored in wp-content/uploads/swifttrap-for-mailtrap/swifttrap-emails.log', 'swifttrap-for-mailtrap' ); ?></p>
                    </div>

                    <!-- Log Retention Days -->
                    <div class="swifttrap-field-group">
                        <label class="swifttrap-field-label"><?php esc_html_e( 'Log retention (days)', 'swifttrap-for-mailtrap' ); ?></label>
                        <input type="number" name="<?php echo esc_attr( SWIFTTRAP_MAILTRAP_OPTION_KEY ); ?>[log_retention_days]" value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>" class="swifttrap-input-small" min="1" max="365" />
                        <p class="swifttrap-field-help"><?php esc_html_e( 'How long to keep email logs (1-365 days). Old logs are automatically deleted.', 'swifttrap-for-mailtrap' ); ?></p>
                    </div>

                    <!-- Logs Per Page -->
                    <div class="swifttrap-field-group">
                        <label class="swifttrap-field-label"><?php esc_html_e( 'Logs per page', 'swifttrap-for-mailtrap' ); ?></label>
                        <input type="number" name="<?php echo esc_attr( SWIFTTRAP_MAILTRAP_OPTION_KEY ); ?>[logs_per_page]" value="<?php echo esc_attr( $settings['logs_per_page'] ); ?>" class="swifttrap-input-small" min="5" max="100" />
                        <p class="swifttrap-field-help"><?php esc_html_e( 'Number of email logs to display per page on Stats page (5-100).', 'swifttrap-for-mailtrap' ); ?></p>
                    </div>
                </div>

                <!-- Advanced Settings Section -->
                <div class="swifttrap-card">
                    <h3><?php esc_html_e( 'Advanced Settings', 'swifttrap-for-mailtrap' ); ?></h3>
                    <p><?php esc_html_e( 'Advanced email delivery options for more control over Mailtrap streams.', 'swifttrap-for-mailtrap' ); ?></p>

                    <!-- Email Categories -->
                    <div class="swifttrap-field-group">
                        <label class="swifttrap-checkbox-label">
                            <input type="checkbox" name="<?php echo esc_attr( SWIFTTRAP_MAILTRAP_OPTION_KEY ); ?>[enable_categories]" value="1" <?php checked( $settings['enable_categories'], 1 ); ?> />
                            <?php esc_html_e( 'Add category tags to emails in Mailtrap', 'swifttrap-for-mailtrap' ); ?>
                        </label>
                        <p class="swifttrap-field-help"><?php esc_html_e( 'Categories help organize emails in Mailtrap dashboard (welcome, password-reset, notification, etc.).', 'swifttrap-for-mailtrap' ); ?></p>
                    </div>

                    <!-- Auto-categorize -->
                    <div class="swifttrap-field-group">
                        <label class="swifttrap-checkbox-label">
                            <input type="checkbox" name="<?php echo esc_attr( SWIFTTRAP_MAILTRAP_OPTION_KEY ); ?>[auto_categorize]" value="1" <?php checked( $settings['auto_categorize'], 1 ); ?> />
                            <?php esc_html_e( 'Automatically detect email type from subject line', 'swifttrap-for-mailtrap' ); ?>
                        </label>
                        <p class="swifttrap-field-help"><?php esc_html_e( 'When enabled, emails are categorized by keywords in subject: "reset" → password-reset, "confirm" → verification, "welcome" → welcome, etc.', 'swifttrap-for-mailtrap' ); ?></p>
                    </div>
                </div>
            </div>

            <?php submit_button(); ?>
        </form>

		<div class="swifttrap-grid" style="margin-top: 20px;">
			<div class="swifttrap-card">
				<h3><?php esc_html_e( 'Test Email', 'swifttrap-for-mailtrap' ); ?></h3>
				<p><?php esc_html_e( 'Send a test email to the configured sender address to verify your setup.', 'swifttrap-for-mailtrap' ); ?></p>
				<button type="button" id="swifttrap-send-test" class="button button-secondary" data-nonce="<?php echo esc_attr( wp_create_nonce( 'swifttrap_send_test_email' ) ); ?>">
					<?php esc_html_e( 'Send Test Email', 'swifttrap-for-mailtrap' ); ?>
				</button>
				<span id="swifttrap-test-result" class="swifttrap-test-result"></span>
			</div>
		</div>

	</div>
	<?php
}

/**
 * Format email log entry for display.
 *
 * @param array $entry Log entry from swifttrap-emails.log.
 *
 * @return array|null Formatted entry with display strings.
 */
function swifttrap_mailtrap_format_log_entry( $entry ) {
	if ( ! is_array( $entry ) ) {
		return null;
	}

	$status_label = ( 'success' === $entry['status'] ) ? __( 'Success', 'swifttrap-for-mailtrap' ) : __( 'Failed', 'swifttrap-for-mailtrap' );
	$status_class = ( 'success' === $entry['status'] ) ? 'swifttrap-log-status--success' : 'swifttrap-log-status--failed';

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
		'timestamp'    => $entry['timestamp'] ? wp_date( 'd.m.Y H:i', strtotime( $entry['timestamp'] ) ) : '',
		'status'       => $status_label,
		'status_class' => $status_class,
		'from'         => $entry['from'] ?? '',
		'to'           => implode( ', ', $recipients ),
		'subject'      => $entry['subject'] ?? '',
		'http_status'  => $entry['http_status'] ?? '—',
		'message'      => $entry['message'] ?? '',
	);
}

/**
 * Stats page with usage, analytics, categories, daily chart, and logs.
 */
function swifttrap_mailtrap_stats_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings  = swifttrap_mailtrap_get_settings();
	$log_stats = swifttrap_mailtrap_compute_log_stats( 7 );

	// Get pagination parameters.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter.
	$page     = isset( $_GET['swifttrap_page'] ) ? max( 1, absint( wp_unslash( $_GET['swifttrap_page'] ) ) ) : 1;
	$per_page = $settings['logs_per_page'];

	// Read logs with offset-based pagination (newest first).
	$start_index = ( $page - 1 ) * $per_page;
	$log_result  = swifttrap_mailtrap_read_email_logs( $per_page, $start_index );
	$logs        = $log_result['entries'];
	$total_logs  = $log_result['total'];
	$total_pages = max( 1, (int) ceil( $total_logs / $per_page ) );
	$page        = min( $page, $total_pages );
	?>
	<div class="wrap" data-api-nonce="<?php echo esc_attr( wp_create_nonce( 'swifttrap_load_api_data' ) ); ?>">
		<h1><?php esc_html_e( 'Mailtrap Stats', 'swifttrap-for-mailtrap' ); ?></h1>

		<!-- Row 1: Usage + Mailtrap link -->
		<div class="swifttrap-grid">
			<div class="swifttrap-card" id="swifttrap-usage-card">
				<h2><?php esc_html_e( 'Usage', 'swifttrap-for-mailtrap' ); ?></h2>
				<div class="swifttrap-card__body">
					<p class="swifttrap-loading"><?php esc_html_e( 'Loading...', 'swifttrap-for-mailtrap' ); ?></p>
				</div>
			</div>
			<div class="swifttrap-card">
				<h2><?php esc_html_e( 'Mailtrap', 'swifttrap-for-mailtrap' ); ?></h2>
				<p><?php esc_html_e( 'For full logs, events, and deliverability reports open Mailtrap.', 'swifttrap-for-mailtrap' ); ?></p>
				<p><a class="button" href="https://mailtrap.io/home" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Mailtrap', 'swifttrap-for-mailtrap' ); ?></a></p>
			</div>
		</div>

		<!-- Row 2: Sending Domains + Suppressions -->
		<div class="swifttrap-grid">
			<div class="swifttrap-card" id="swifttrap-domains-card">
				<h2><?php esc_html_e( 'Sending Domains', 'swifttrap-for-mailtrap' ); ?></h2>
				<div class="swifttrap-card__body">
					<p class="swifttrap-loading"><?php esc_html_e( 'Loading...', 'swifttrap-for-mailtrap' ); ?></p>
				</div>
			</div>
			<div class="swifttrap-card" id="swifttrap-suppressions-card">
				<h2><?php esc_html_e( 'Suppressions', 'swifttrap-for-mailtrap' ); ?></h2>
				<div class="swifttrap-card__body">
					<p class="swifttrap-loading"><?php esc_html_e( 'Loading...', 'swifttrap-for-mailtrap' ); ?></p>
				</div>
			</div>
		</div>

		<!-- Row 3: Email Analytics + Categories -->
		<div class="swifttrap-grid">
			<div class="swifttrap-card">
				<h2><?php esc_html_e( 'Email Analytics', 'swifttrap-for-mailtrap' ); ?></h2>
				<?php if ( $log_stats['total_sent'] > 0 ) : ?>
					<div class="swifttrap-stats-summary">
						<div class="swifttrap-stats-summary__item">
							<span class="swifttrap-stats-summary__label"><?php esc_html_e( 'Sent', 'swifttrap-for-mailtrap' ); ?></span>
							<span class="swifttrap-stats-summary__value swifttrap-stats-summary__value--success"><?php echo esc_html( number_format_i18n( $log_stats['total_success'] ) ); ?></span>
							<span class="swifttrap-stats-summary__sub"><?php
							/* translators: %d: total number of emails sent */
							printf( esc_html__( '%d total', 'swifttrap-for-mailtrap' ), absint( $log_stats['total_sent'] ) );
						?></span>
						</div>
						<div class="swifttrap-stats-summary__item">
							<span class="swifttrap-stats-summary__label"><?php esc_html_e( 'Failed', 'swifttrap-for-mailtrap' ); ?></span>
							<span class="swifttrap-stats-summary__value swifttrap-stats-summary__value--failed"><?php echo esc_html( number_format_i18n( $log_stats['total_failed'] ) ); ?></span>
						</div>
						<div class="swifttrap-stats-summary__item">
							<span class="swifttrap-stats-summary__label"><?php esc_html_e( 'Success Rate', 'swifttrap-for-mailtrap' ); ?></span>
							<span class="swifttrap-stats-summary__value"><?php echo esc_html( $log_stats['success_rate'] . '%' ); ?></span>
						</div>
					</div>

					<?php if ( ! empty( $log_stats['daily_volume'] ) ) :
						$max_volume = max( 1, max( $log_stats['daily_volume'] ) );
					?>
						<h3><?php esc_html_e( 'Daily Volume (7 days)', 'swifttrap-for-mailtrap' ); ?></h3>
						<div class="swifttrap-daily-chart">
							<?php foreach ( $log_stats['daily_volume'] as $date => $count ) :
								$bar_pct = round( ( $count / $max_volume ) * 100 );
							?>
								<div class="swifttrap-daily-chart__bar">
									<div class="swifttrap-daily-chart__bar-fill" style="height: <?php echo esc_attr( $bar_pct ); ?>%;" title="<?php echo esc_attr( $count ); ?>"></div>
								</div>
							<?php endforeach; ?>
						</div>
						<div class="swifttrap-daily-chart__labels">
							<?php foreach ( $log_stats['daily_volume'] as $date => $count ) : ?>
								<span><?php echo esc_html( wp_date( 'D', strtotime( $date ) ) ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				<?php else : ?>
					<p><?php esc_html_e( 'No log data. Enable logging in Settings.', 'swifttrap-for-mailtrap' ); ?></p>
				<?php endif; ?>
			</div>
			<div class="swifttrap-card">
				<h2><?php esc_html_e( 'Categories', 'swifttrap-for-mailtrap' ); ?></h2>
				<?php if ( ! empty( $log_stats['by_category'] ) ) : ?>
					<ul class="swifttrap-category-list">
						<?php foreach ( $log_stats['by_category'] as $cat_name => $cat_count ) : ?>
							<li>
								<span class="swifttrap-category-badge"><?php echo esc_html( $cat_name ); ?></span>
								<span class="swifttrap-category-count"><?php echo esc_html( number_format_i18n( $cat_count ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p><?php esc_html_e( 'No category data available.', 'swifttrap-for-mailtrap' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<!-- Row 4: Recent Email Logs -->
		<div class="swifttrap-logs-section">
			<div class="swifttrap-card">
				<div class="swifttrap-logs-header">
					<h2><?php esc_html_e( 'Recent Email Logs', 'swifttrap-for-mailtrap' ); ?></h2>
					<button type="button" id="swifttrap-clear-logs" class="button" data-nonce="<?php echo esc_attr( wp_create_nonce( 'swifttrap_clear_logs' ) ); ?>">
						<?php esc_html_e( 'Clear Logs', 'swifttrap-for-mailtrap' ); ?>
					</button>
					<span class="swifttrap-timezone">
						<?php
						$timezone = get_option( 'timezone_string' );
						if ( empty( $timezone ) ) {
							$offset = get_option( 'gmt_offset' );
							$timezone = 'UTC' . ( $offset >= 0 ? '+' : '' ) . $offset;
						}
						/* translators: %s: timezone name */
					printf( esc_html__( 'Times in %s', 'swifttrap-for-mailtrap' ), esc_html( $timezone ) );
						?>
					</span>
				</div>
				<?php if ( empty( $logs ) ) : ?>
					<p><?php esc_html_e( 'No email logs found. Enable logging in Settings.', 'swifttrap-for-mailtrap' ); ?></p>
				<?php else : ?>
					<div class="swifttrap-logs-wrapper">
						<table class="swifttrap-logs-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'To', 'swifttrap-for-mailtrap' ); ?></th>
									<th><?php esc_html_e( 'Subject', 'swifttrap-for-mailtrap' ); ?></th>
									<th><?php esc_html_e( 'Category', 'swifttrap-for-mailtrap' ); ?></th>
									<th><?php esc_html_e( 'Date', 'swifttrap-for-mailtrap' ); ?></th>
									<th><?php esc_html_e( 'Status', 'swifttrap-for-mailtrap' ); ?></th>
									<th><?php esc_html_e( 'HTTP', 'swifttrap-for-mailtrap' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $logs as $log_entry ) : ?>
									<?php $formatted = swifttrap_mailtrap_format_log_entry( $log_entry ); ?>
									<?php if ( $formatted ) : ?>
										<tr>
											<td class="swifttrap-log-to">
												<span title="<?php echo esc_attr( $formatted['to'] ); ?>">
													<?php echo esc_html( wp_html_excerpt( $formatted['to'], 30, '...' ) ); ?>
												</span>
											</td>
											<td class="swifttrap-log-to">
												<span title="<?php echo esc_attr( $formatted['subject'] ); ?>">
													<?php echo esc_html( wp_html_excerpt( $formatted['subject'], 40, '...' ) ); ?>
												</span>
											</td>
											<td>
												<?php if ( ! empty( $log_entry['category'] ) ) : ?>
													<span class="swifttrap-category-badge"><?php echo esc_html( $log_entry['category'] ); ?></span>
												<?php else : ?>
													<span class="swifttrap-category-badge--empty">&mdash;</span>
												<?php endif; ?>
											</td>
											<td class="swifttrap-log-date">
												<?php echo esc_html( $formatted['timestamp'] ); ?>
											</td>
											<td>
												<strong class="swifttrap-log-status <?php echo esc_attr( $formatted['status_class'] ); ?>">
													<?php echo esc_html( $formatted['status'] ); ?>
												</strong>
											</td>
											<td class="swifttrap-log-http">
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
					<div class="swifttrap-pagination">
						<nav class="swifttrap-pagination-nav">
							<?php if ( $page > 1 ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'swifttrap_page', 1 ) ); ?>" class="button"><?php esc_html_e( 'First', 'swifttrap-for-mailtrap' ); ?></a>
								<a href="<?php echo esc_url( add_query_arg( 'swifttrap_page', $page - 1 ) ); ?>" class="button"><?php esc_html_e( 'Previous', 'swifttrap-for-mailtrap' ); ?></a>
							<?php endif; ?>

							<span class="swifttrap-pagination-info">
								<?php
								/* translators: %1$d: current page number, %2$d: total number of pages */
								printf( esc_html__( 'Page %1$d of %2$d', 'swifttrap-for-mailtrap' ), absint( $page ), absint( $total_pages ) );
								?>
							</span>

							<?php if ( $page < $total_pages ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'swifttrap_page', $page + 1 ) ); ?>" class="button"><?php esc_html_e( 'Next', 'swifttrap-for-mailtrap' ); ?></a>
								<a href="<?php echo esc_url( add_query_arg( 'swifttrap_page', $total_pages ) ); ?>" class="button"><?php esc_html_e( 'Last', 'swifttrap-for-mailtrap' ); ?></a>
							<?php endif; ?>
						</nav>
						<p class="swifttrap-pagination-info">
							<?php
							printf(
								/* translators: %1$d: first log index, %2$d: last log index, %3$d: total number of logs */
								esc_html__( 'Showing %1$d-%2$d of %3$d total logs', 'swifttrap-for-mailtrap' ),
								$total_logs > 0 ? absint( $start_index + 1 ) : 0,
								absint( min( $start_index + $per_page, $total_logs ) ),
								absint( $total_logs )
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Sanitize settings before save.
 *
 * @param array $settings .
 *
 * @return array
 */
function swifttrap_mailtrap_sanitize_settings( $settings ) {
	$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), swifttrap_mailtrap_default_settings() );

	$settings['enabled'] = empty( $settings['enabled'] ) ? 0 : 1;
	$settings['token']   = sanitize_text_field( $settings['token'] );

	$settings['sender_email'] = sanitize_email( $settings['sender_email'] );
	$settings['sender_name']  = sanitize_text_field( $settings['sender_name'] );

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

	return $settings;
}
