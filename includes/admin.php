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
add_action( 'admin_init', 'swifttrap_mailtrap_handle_csv_export' );
add_action( 'admin_menu', 'swifttrap_mailtrap_register_menu' );
add_action( 'admin_enqueue_scripts', 'swifttrap_mailtrap_admin_assets' );
add_action( 'wp_dashboard_setup', 'swifttrap_mailtrap_register_dashboard_widget' );
add_action( 'wp_ajax_swifttrap_verify_token', 'swifttrap_mailtrap_ajax_verify_token' );

/**
 * Register settings and fields.
 */
function swifttrap_mailtrap_register_settings(): void {
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
 * Handle CSV export request.
 */
function swifttrap_mailtrap_handle_csv_export(): void {
	if ( ! isset( $_GET['swifttrap_export_csv'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'swifttrap-for-mailtrap' ) );
	}

	check_admin_referer( 'swifttrap_export_csv', '_nonce' );

	$log_result = swifttrap_mailtrap_read_email_logs( 9999, 0 );
	$logs       = $log_result['entries'];

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=swifttrap-email-logs-' . wp_date( 'Y-m-d' ) . '.csv' );

	$output = fopen( 'php://output', 'w' );
	fputcsv( $output, array( 'Timestamp', 'Status', 'From', 'To', 'Subject', 'Category', 'HTTP Status', 'Message' ) );

	foreach ( $logs as $entry ) {
		fputcsv( $output, swifttrap_mailtrap_build_csv_row( $entry ) );
	}

	fclose( $output );
	exit;
}

/**
 * Format a log entry into a CSV row.
 *
 * @param array $entry Log entry.
 *
 * @return array CSV row fields.
 */
function swifttrap_mailtrap_build_csv_row( array $entry ): array {
	$recipients = array();
	if ( ! empty( $entry['to'] ) && is_array( $entry['to'] ) ) {
		foreach ( $entry['to'] as $to ) {
			$email        = $to['email'] ?? '';
			$name         = $to['name'] ?? '';
			$recipients[] = $name ? "{$name} <{$email}>" : $email;
		}
	}

	return array(
		swifttrap_mailtrap_escape_csv_cell( $entry['timestamp'] ?? '' ),
		swifttrap_mailtrap_escape_csv_cell( $entry['status'] ?? '' ),
		swifttrap_mailtrap_escape_csv_cell( $entry['from'] ?? '' ),
		swifttrap_mailtrap_escape_csv_cell( implode( ', ', $recipients ) ),
		swifttrap_mailtrap_escape_csv_cell( $entry['subject'] ?? '' ),
		swifttrap_mailtrap_escape_csv_cell( $entry['category'] ?? '' ),
		swifttrap_mailtrap_escape_csv_cell( $entry['http_status'] ?? '' ),
		swifttrap_mailtrap_escape_csv_cell( $entry['message'] ?? '' ),
	);
}

/**
 * Escape a cell value for CSV export to prevent Formula Injection (CSV Injection).
 *
 * @param mixed $value Cell value.
 *
 * @return string Escaped cell value.
 */
function swifttrap_mailtrap_escape_csv_cell( $value ): string {
	$value = (string) $value;
	if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
		return "'" . $value;
	}
	return $value;
}

/**
 * Enqueue admin styling for cards layout.
 */
function swifttrap_mailtrap_admin_assets( string $hook ): void {
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

	wp_localize_script( 'jquery-core', 'swifttrapStats', array(
		'nonce'                    => wp_create_nonce( 'swifttrap_load_api_data' ),
		'add_suppression_nonce'    => wp_create_nonce( 'swifttrap_add_suppression' ),
		'delete_suppression_nonce' => wp_create_nonce( 'swifttrap_delete_suppression' ),
		'get_log_details_nonce'    => wp_create_nonce( 'swifttrap_get_log_details' ),
		'resend_email_nonce'       => wp_create_nonce( 'swifttrap_resend_email' ),
		'l10n'                     => array(
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
			'remove'         => __( 'Remove', 'swifttrap-for-mailtrap' ),
		),
	) );

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
			// Verify API Token
			$(document).on("click","#swifttrap-verify-token",function(){
				var btn = $(this), result = $("#swifttrap-verify-result"),
					token = $("input[name=\"swifttrap_mailtrap_settings[token]\"]").val();
				btn.prop("disabled",true).text("' . esc_js( __( 'Verifying...', 'swifttrap-for-mailtrap' ) ) . '");
				result.text("").removeClass("swifttrap-test-result--success swifttrap-test-result--error");
				$.post(ajaxurl,{action:"swifttrap_verify_token",token:token,_nonce:btn.data("nonce")},function(r){
					btn.prop("disabled",false).text("' . esc_js( __( 'Verify Token', 'swifttrap-for-mailtrap' ) ) . '");
					if(r.success){
						result.addClass("swifttrap-test-result--success").text(r.data.message);
					}else{
						result.addClass("swifttrap-test-result--error").text(r.data.message);
					}
				}).fail(function(){
					btn.prop("disabled",false).text("' . esc_js( __( 'Verify Token', 'swifttrap-for-mailtrap' ) ) . '");
					result.addClass("swifttrap-test-result--error").text("' . esc_js( __( 'Request failed.', 'swifttrap-for-mailtrap' ) ) . '");
				});
			});

			// Add Suppression Form Submit
			$(document).on("submit","#swifttrap-add-suppression-form",function(e){
				e.preventDefault();
				var form = $(this), email = $("#swifttrap-new-suppression-email").val(),
					domain = $("#swifttrap-new-suppression-domain").val(),
					stream = $("#swifttrap-new-suppression-stream").val(),
					btn = form.find("button");
				btn.prop("disabled",true).text("' . esc_js( __( 'Adding...', 'swifttrap-for-mailtrap' ) ) . '");
				$.post(ajaxurl,{
					action: "swifttrap_add_suppression",
					email: email,
					domain_id: domain,
					sending_stream: stream,
					_nonce: swifttrapStats.add_suppression_nonce
				},function(r){
					btn.prop("disabled",false).text("' . esc_js( __( 'Add Suppression', 'swifttrap-for-mailtrap' ) ) . '");
					if(r.success){
						alert(r.data.message);
						location.reload();
					}else{
						alert(r.data.message);
					}
				}).fail(function(){
					btn.prop("disabled",false).text("' . esc_js( __( 'Add Suppression', 'swifttrap-for-mailtrap' ) ) . '");
					alert("' . esc_js( __( 'Request failed.', 'swifttrap-for-mailtrap' ) ) . '");
				});
			});

			// Delete Suppression
			$(document).on("click",".swifttrap-delete-suppression",function(e){
				e.preventDefault();
				if(!confirm("' . esc_js( __( 'Are you sure you want to remove this suppression?', 'swifttrap-for-mailtrap' ) ) . '")) return;
				var link = $(this), id = link.data("id");
				link.css("pointer-events", "none").css("opacity", "0.5");
				$.post(ajaxurl,{
					action: "swifttrap_delete_suppression",
					id: id,
					_nonce: swifttrapStats.delete_suppression_nonce
				},function(r){
					if(r.success){
						location.reload();
					}else{
						link.css("pointer-events", "auto").css("opacity", "1");
						alert(r.data.message);
					}
				}).fail(function(){
					link.css("pointer-events", "auto").css("opacity", "1");
					alert("' . esc_js( __( 'Request failed.', 'swifttrap-for-mailtrap' ) ) . '");
				});
			});

			// View Log Payload
			$(document).on("click",".swifttrap-view-log",function(e){
				e.preventDefault();
				var link = $(this), id = link.data("id"), nonce = link.data("nonce");
				$.post(ajaxurl,{action:"swifttrap_get_log_details",id:id,_nonce:nonce},function(r){
					if(r.success){
						$("#swifttrap-modal-subject").text(r.data.subject);
						var bodyContent = r.data.body;
						if (r.data.content_type.indexOf("text/html") !== -1) {
							var escapedBody = $("<div>").text(bodyContent).html().replace(/"/g, "&quot;").replace(/\x27/g, "&#39;");
							$("#swifttrap-modal-body").html("<iframe sandbox=\"\" srcdoc=\"" + escapedBody + "\" style=\"width:100%;height:300px;border:1px solid #ddd;\"></iframe>");
						} else {
							$("#swifttrap-modal-body").html("<pre style=\"white-space:pre-wrap;background:#f9f9f9;padding:10px;border:1px solid #ddd;\">" + $("<span>").text(bodyContent).html() + "</pre>");
						}
						$("#swifttrap-log-modal").fadeIn(200);
					}else{
						alert(r.data.message);
					}
				});
			});

			// Resend Log
			$(document).on("click",".swifttrap-resend-log",function(e){
				e.preventDefault();
				if(!confirm("' . esc_js( __( 'Are you sure you want to resend this email?', 'swifttrap-for-mailtrap' ) ) . '")) return;
				var link = $(this), id = link.data("id"), nonce = link.data("nonce");
				link.css("pointer-events", "none").css("opacity", "0.5");
				$.post(ajaxurl,{action:"swifttrap_resend_email",id:id,_nonce:nonce},function(r){
					link.css("pointer-events", "auto").css("opacity", "1");
					if(r.success){
						alert(r.data.message);
						location.reload();
					}else{
						alert(r.data.message);
					}
				}).fail(function(){
					link.css("pointer-events", "auto").css("opacity", "1");
					alert("' . esc_js( __( 'Request failed.', 'swifttrap-for-mailtrap' ) ) . '");
				});
			});

			// Close Modal
			$(document).on("click",".swifttrap-modal-close, .swifttrap-modal-close-btn",function(){
				$("#swifttrap-log-modal").fadeOut(200);
			});
		});
	' );

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
							if(it.id){
								sh+="<a href=\"#\" class=\"swifttrap-delete-suppression\" data-id=\""+esc(it.id)+"\" style=\"margin-left:8px;color:#a00;text-decoration:none;font-weight:bold;\" title=\""+esc(L.remove)+"\">&times;</a>";
							}
							sh+="</span></li>";
						}
						sh+="</ul>";
					}else if(!sp.summary||sp.summary.total===0){
						sh+="<p>"+esc(L.noSuppressions)+"</p>";
					}

					// Form to add suppression
					sh+="<form id=\"swifttrap-add-suppression-form\" style=\"margin-top:15px;display:flex;flex-direction:column;gap:8px;\">";
					sh+="<input type=\"email\" id=\"swifttrap-new-suppression-email\" placeholder=\"Email address\" required class=\"swifttrap-input-full\" style=\"margin:0;\" />";
					sh+="<div style=\"display:flex;gap:8px;\">";
					sh+="<select id=\"swifttrap-new-suppression-domain\" style=\"flex:1;margin:0;\" required></select>";
					sh+="<select id=\"swifttrap-new-suppression-stream\" style=\"flex:1;margin:0;\" required>";
					sh+="<option value=\"transactional\">Transactional</option>";
					sh+="<option value=\"bulk\">Bulk</option>";
					sh+="</select>";
					sh+="</div>";
					sh+="<button type=\"submit\" class=\"button button-secondary\" style=\"margin:0;\">Add Suppression</button>";
					sh+="</form>";

					$s.html(sh);

					// Populate domain dropdown
					var $domSel = $("#swifttrap-new-suppression-domain");
					$domSel.empty();
					if (api.domains && api.domains.length) {
						$.each(api.domains, function(i, d) {
							if(d.id){
								$domSel.append($("<option>").val(d.id).text(d.name));
							}
						});
					}
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
function swifttrap_mailtrap_register_menu(): void {
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
function swifttrap_mailtrap_register_dashboard_widget(): void {
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
function swifttrap_mailtrap_dashboard_widget_content(): void {
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
function swifttrap_mailtrap_settings_page(): void {
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
						<div style="margin-top: 10px;">
							<button type="button" id="swifttrap-verify-token" class="button button-secondary" data-nonce="<?php echo esc_attr( wp_create_nonce( 'swifttrap_verify_token' ) ); ?>">
								<?php esc_html_e( 'Verify Token', 'swifttrap-for-mailtrap' ); ?>
							</button>
							<span id="swifttrap-verify-result" class="swifttrap-test-result"></span>
						</div>
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

					<!-- Max Attachment Size -->
					<div class="swifttrap-field-group">
						<label class="swifttrap-field-label"><?php esc_html_e( 'Max attachment size (MB)', 'swifttrap-for-mailtrap' ); ?></label>
						<input type="number" name="<?php echo esc_attr( SWIFTTRAP_MAILTRAP_OPTION_KEY ); ?>[max_attachment_size_mb]" value="<?php echo esc_attr( $settings['max_attachment_size_mb'] ?? 25 ); ?>" class="swifttrap-input-small" min="1" max="25" />
						<p class="swifttrap-field-help"><?php esc_html_e( 'Maximum size permitted for individual email attachments (1 to 25 MB).', 'swifttrap-for-mailtrap' ); ?></p>
					</div>
				</div>

				<!-- Advanced Settings Section & Webhooks -->
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

					<!-- Webhooks Config -->
					<div class="swifttrap-field-group">
						<label class="swifttrap-field-label"><?php esc_html_e( 'Webhook URL', 'swifttrap-for-mailtrap' ); ?></label>
						<input type="text" class="swifttrap-input-full swifttrap-input-mono" value="<?php echo esc_url( rest_url( 'swifttrap/v1/webhook' ) ); ?>" readonly onclick="this.select();" />
						<p class="swifttrap-field-help"><?php esc_html_e( 'Register this URL in your Mailtrap settings under Integration Webhooks. Select delivered, bounce, spam, open, and click events.', 'swifttrap-for-mailtrap' ); ?></p>
					</div>

					<div class="swifttrap-field-group">
						<label class="swifttrap-field-label"><?php esc_html_e( 'Webhook Secret', 'swifttrap-for-mailtrap' ); ?></label>
						<input type="text" name="<?php echo esc_attr( SWIFTTRAP_MAILTRAP_OPTION_KEY ); ?>[webhook_secret]" value="<?php echo esc_attr( $settings['webhook_secret'] ?? '' ); ?>" class="swifttrap-input-full" />
						<p class="swifttrap-field-help"><?php esc_html_e( 'Verification key for incoming webhook events. Configure it in Mailtrap Webhook settings as the X-Mailtrap-Secret HTTP header.', 'swifttrap-for-mailtrap' ); ?></p>
					</div>
				</div>
			</div>

			<!-- Unified mapping table -->
			<div class="swifttrap-grid" style="grid-template-columns: 1fr;">
				<div class="swifttrap-card">
					<h3><?php esc_html_e( 'Category Stream Mapping & Sender Override', 'swifttrap-for-mailtrap' ); ?></h3>
					<p><?php esc_html_e( 'Map auto-detected categories to sending streams or override From identities per category.', 'swifttrap-for-mailtrap' ); ?></p>
					<div style="overflow-x: auto;">
						<table class="wp-list-table widefat fixed striped" style="margin-top: 10px; width: 100%;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Category', 'swifttrap-for-mailtrap' ); ?></th>
									<th><?php esc_html_e( 'Sending Stream', 'swifttrap-for-mailtrap' ); ?></th>
									<th><?php esc_html_e( 'Custom From Name', 'swifttrap-for-mailtrap' ); ?></th>
									<th><?php esc_html_e( 'Custom From Email', 'swifttrap-for-mailtrap' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								$categories         = array( 'verification', 'password-reset', 'welcome', 'notification', 'transactional', 'promotional', 'general' );
								$configured_streams = $settings['category_streams'] ?? array();
								$configured_senders = $settings['category_senders'] ?? array();
								foreach ( $categories as $cat ) :
									$stream       = $configured_streams[ $cat ] ?? 'transactional';
									$custom_name  = $configured_senders[ $cat ]['name'] ?? '';
									$custom_email = $configured_senders[ $cat ]['email'] ?? '';
								?>
									<tr>
										<td><strong><?php echo esc_html( $cat ); ?></strong></td>
										<td>
											<select name="<?php echo esc_attr( SWIFTTRAP_MAILTRAP_OPTION_KEY ); ?>[category_streams][<?php echo esc_attr( $cat ); ?>]" style="max-width: 150px;">
												<option value="transactional" <?php selected( $stream, 'transactional' ); ?>><?php esc_html_e( 'Transactional', 'swifttrap-for-mailtrap' ); ?></option>
												<option value="bulk" <?php selected( $stream, 'bulk' ); ?>><?php esc_html_e( 'Bulk', 'swifttrap-for-mailtrap' ); ?></option>
											</select>
										</td>
										<td>
											<input type="text" name="<?php echo esc_attr( SWIFTTRAP_MAILTRAP_OPTION_KEY ); ?>[category_senders][<?php echo esc_attr( $cat ); ?>][name]" value="<?php echo esc_attr( $custom_name ); ?>" class="swifttrap-input-full" placeholder="<?php esc_attr_e( 'e.g. Support Team', 'swifttrap-for-mailtrap' ); ?>" style="max-width: 200px;" />
										</td>
										<td>
											<input type="email" name="<?php echo esc_attr( SWIFTTRAP_MAILTRAP_OPTION_KEY ); ?>[category_senders][<?php echo esc_attr( $cat ); ?>][email]" value="<?php echo esc_attr( $custom_email ); ?>" class="swifttrap-input-full" placeholder="<?php esc_attr_e( 'e.g. support@domain.com', 'swifttrap-for-mailtrap' ); ?>" style="max-width: 250px;" />
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
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
function swifttrap_mailtrap_format_log_entry( mixed $entry ): ?array {
	if ( ! is_array( $entry ) ) {
		return null;
	}

	$status_key = $entry['status'] ?? 'failed';
	switch ( $status_key ) {
		case 'delivered':
			$status_label = __( 'Delivered', 'swifttrap-for-mailtrap' );
			$status_class = 'swifttrap-log-status--delivered';
			break;
		case 'bounce':
		case 'bounced':
			$status_label = __( 'Bounced', 'swifttrap-for-mailtrap' );
			$status_class = 'swifttrap-log-status--bounced';
			break;
		case 'spam':
			$status_label = __( 'Spam', 'swifttrap-for-mailtrap' );
			$status_class = 'swifttrap-log-status--spam';
			break;
		case 'open':
		case 'opened':
			$status_label = __( 'Opened', 'swifttrap-for-mailtrap' );
			$status_class = 'swifttrap-log-status--opened';
			break;
		case 'click':
		case 'clicked':
			$status_label = __( 'Clicked', 'swifttrap-for-mailtrap' );
			$status_class = 'swifttrap-log-status--clicked';
			break;
		case 'success':
			$status_label = __( 'Success', 'swifttrap-for-mailtrap' );
			$status_class = 'swifttrap-log-status--success';
			break;
		case 'failed':
		default:
			$status_label = __( 'Failed', 'swifttrap-for-mailtrap' );
			$status_class = 'swifttrap-log-status--failed';
			break;
	}

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
function swifttrap_mailtrap_stats_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings  = swifttrap_mailtrap_get_settings();
	$log_stats = swifttrap_mailtrap_compute_log_stats( 7 );

	// Get filter parameters.
	$filters = array(
		'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		'category' => isset( $_GET['cat'] ) ? sanitize_text_field( wp_unslash( $_GET['cat'] ) ) : '',
		'status'   => isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '',
		'date'     => isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '',
	);

	// Get pagination parameters.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter.
	$page     = isset( $_GET['swifttrap_page'] ) ? max( 1, absint( wp_unslash( $_GET['swifttrap_page'] ) ) ) : 1;
	$per_page = $settings['logs_per_page'];

	// Read logs with offset-based pagination (newest first).
	$start_index = ( $page - 1 ) * $per_page;
	$log_result  = swifttrap_mailtrap_read_email_logs( $per_page, $start_index, $filters );
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
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=swifttrap-for-mailtrap&swifttrap_export_csv=1' ), 'swifttrap_export_csv', '_nonce' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Export to CSV', 'swifttrap-for-mailtrap' ); ?></a>
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

				<!-- Search / Filter Form -->
				<form method="get" action="" style="margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
					<input type="hidden" name="page" value="swifttrap-for-mailtrap" />
					<input type="text" name="s" placeholder="<?php esc_attr_e( 'Search recipient / subject...', 'swifttrap-for-mailtrap' ); ?>" value="<?php echo esc_attr( $filters['search'] ); ?>" style="max-width: 250px; margin: 0;" />
					
					<select name="cat" style="max-width: 160px; margin: 0;">
						<option value=""><?php esc_html_e( 'All Categories', 'swifttrap-for-mailtrap' ); ?></option>
						<?php foreach ( array( 'verification', 'password-reset', 'welcome', 'notification', 'transactional', 'promotional', 'general' ) as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $filters['category'], $cat ); ?>><?php echo esc_html( $cat ); ?></option>
						<?php endforeach; ?>
					</select>

					<select name="status" style="max-width: 140px; margin: 0;">
						<option value=""><?php esc_html_e( 'All Statuses', 'swifttrap-for-mailtrap' ); ?></option>
						<option value="success" <?php selected( $filters['status'], 'success' ); ?>><?php esc_html_e( 'Success', 'swifttrap-for-mailtrap' ); ?></option>
						<option value="failed" <?php selected( $filters['status'], 'failed' ); ?>><?php esc_html_e( 'Failed', 'swifttrap-for-mailtrap' ); ?></option>
						<option value="delivered" <?php selected( $filters['status'], 'delivered' ); ?>><?php esc_html_e( 'Delivered', 'swifttrap-for-mailtrap' ); ?></option>
						<option value="bounce" <?php selected( $filters['status'], 'bounce' ); ?>><?php esc_html_e( 'Bounced', 'swifttrap-for-mailtrap' ); ?></option>
						<option value="spam" <?php selected( $filters['status'], 'spam' ); ?>><?php esc_html_e( 'Spam', 'swifttrap-for-mailtrap' ); ?></option>
						<option value="open" <?php selected( $filters['status'], 'open' ); ?>><?php esc_html_e( 'Opened', 'swifttrap-for-mailtrap' ); ?></option>
						<option value="click" <?php selected( $filters['status'], 'click' ); ?>><?php esc_html_e( 'Clicked', 'swifttrap-for-mailtrap' ); ?></option>
					</select>

					<input type="date" name="date" value="<?php echo esc_attr( $filters['date'] ); ?>" style="max-width: 150px; margin: 0;" />

					<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'swifttrap-for-mailtrap' ); ?></button>
					<?php if ( ! empty( $filters['search'] ) || ! empty( $filters['category'] ) || ! empty( $filters['status'] ) || ! empty( $filters['date'] ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=swifttrap-for-mailtrap' ) ); ?>" class="button"><?php esc_html_e( 'Clear Filters', 'swifttrap-for-mailtrap' ); ?></a>
					<?php endif; ?>
				</form>

				<?php if ( empty( $logs ) ) : ?>
					<p><?php esc_html_e( 'No email logs found matching the filter criteria.', 'swifttrap-for-mailtrap' ); ?></p>
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
									<th><?php esc_html_e( 'Actions', 'swifttrap-for-mailtrap' ); ?></th>
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
											<td>
												<?php if ( ! empty( $log_entry['id'] ) ) : ?>
													<a href="#" class="swifttrap-view-log" data-id="<?php echo esc_attr( $log_entry['id'] ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'swifttrap_get_log_details' ) ); ?>"><?php esc_html_e( 'View', 'swifttrap-for-mailtrap' ); ?></a>
													|
													<a href="#" class="swifttrap-resend-log" data-id="<?php echo esc_attr( $log_entry['id'] ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'swifttrap_resend_email' ) ); ?>"><?php esc_html_e( 'Resend', 'swifttrap-for-mailtrap' ); ?></a>
												<?php else : ?>
													&mdash;
												<?php endif; ?>
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

		<!-- Log Details Modal -->
		<div id="swifttrap-log-modal" class="swifttrap-modal" style="display:none;">
			<div class="swifttrap-modal-content">
				<span class="swifttrap-modal-close">&times;</span>
				<h2 id="swifttrap-modal-subject" style="border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;"></h2>
				<div id="swifttrap-modal-body" class="swifttrap-modal-body-content"></div>
				<div style="margin-top: 20px; text-align: right;">
					<button type="button" class="button swifttrap-modal-close-btn"><?php esc_html_e( 'Close', 'swifttrap-for-mailtrap' ); ?></button>
				</div>
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
function swifttrap_mailtrap_sanitize_settings( mixed $settings ): array {
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

	// Webhook / attachments size.
	$settings['webhook_secret'] = sanitize_text_field( $settings['webhook_secret'] ?? '' );
	$max_att = (int) ( $settings['max_attachment_size_mb'] ?? 25 );
	$settings['max_attachment_size_mb'] = max( 1, min( 25, $max_att ) );

	// Mappings.
	$category_streams = $settings['category_streams'] ?? array();
	$sanitized_streams = array();
	if ( is_array( $category_streams ) ) {
		foreach ( $category_streams as $cat => $stream ) {
			$sanitized_streams[ sanitize_key( $cat ) ] = ( 'bulk' === $stream ) ? 'bulk' : 'transactional';
		}
	}
	$settings['category_streams'] = $sanitized_streams;

	$category_senders = $settings['category_senders'] ?? array();
	$sanitized_senders = array();
	if ( is_array( $category_senders ) ) {
		foreach ( $category_senders as $cat => $sender ) {
			$sanitized_senders[ sanitize_key( $cat ) ] = array(
				'name'  => sanitize_text_field( $sender['name'] ?? '' ),
				'email' => sanitize_email( $sender['email'] ?? '' ),
			);
		}
	}
	$settings['category_senders'] = $sanitized_senders;

	return $settings;
}

/**
 * AJAX handler: verify Mailtrap token.
 *
 * @since 2.3.0
 */
function swifttrap_mailtrap_ajax_verify_token(): void {
	check_ajax_referer( 'swifttrap_verify_token', '_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'swifttrap-for-mailtrap' ) ) );
	}

	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

	if ( empty( $token ) ) {
		wp_send_json_error( array( 'message' => __( 'Please enter an API token.', 'swifttrap-for-mailtrap' ) ) );
	}

	// Temporarily construct settings array with the provided token to test it.
	$test_settings = array( 'token' => $token );

	$data = swifttrap_mailtrap_get_account_data( $test_settings );

	if ( is_wp_error( $data ) ) {
		wp_send_json_error( array( 'message' => sprintf( __( 'Verification failed: %s', 'swifttrap-for-mailtrap' ), $data->get_error_message() ) ) );
	}

	wp_send_json_success( array( 'message' => sprintf( __( 'Token verified successfully. Account: %s', 'swifttrap-for-mailtrap' ), $data['name'] ) ) );
}

