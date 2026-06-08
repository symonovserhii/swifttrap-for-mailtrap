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
		'emails_nonce'             => wp_create_nonce( 'swifttrap_load_emails' ),
		'add_suppression_nonce'    => wp_create_nonce( 'swifttrap_add_suppression' ),
		'delete_suppression_nonce' => wp_create_nonce( 'swifttrap_delete_suppression' ),
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
			'emailLogs'      => __( 'Email Logs', 'swifttrap-for-mailtrap' ),
			'colFrom'        => __( 'From', 'swifttrap-for-mailtrap' ),
			'colTo'          => __( 'To', 'swifttrap-for-mailtrap' ),
			'colSubject'     => __( 'Subject', 'swifttrap-for-mailtrap' ),
			'colCategory'    => __( 'Category', 'swifttrap-for-mailtrap' ),
			'colStatus'      => __( 'Status', 'swifttrap-for-mailtrap' ),
			'colDate'        => __( 'Date', 'swifttrap-for-mailtrap' ),
			'noEmails'       => __( 'No emails found.', 'swifttrap-for-mailtrap' ),
			'emailsError'    => __( 'Unable to load email logs.', 'swifttrap-for-mailtrap' ),
			'prevPage'       => __( '&laquo; Prev', 'swifttrap-for-mailtrap' ),
			'nextPage'       => __( 'Next &raquo;', 'swifttrap-for-mailtrap' ),
			'search'         => __( 'Search...', 'swifttrap-for-mailtrap' ),
			'allStatuses'    => __( 'All statuses', 'swifttrap-for-mailtrap' ),
			'pageOf'         => __( 'Page %1$s of %2$s', 'swifttrap-for-mailtrap' ),
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
					h+="<div class=\"swifttrap-info-block\"><div class=\"swifttrap-info-label\">"+esc(L.plan)+"</div><div class=\"swifttrap-info-value\">"+esc(s.plan||"—")+"</div></div>";
					h+="<div class=\"swifttrap-info-block\"><div class=\"swifttrap-info-label\">"+esc(L.team)+"</div><div class=\"swifttrap-info-value\">"+esc(s.team||"—")+"</div></div>";
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
							if(it.bounce_category){sh+="<span class=\"swifttrap-suppression-bounce-cat\">"+esc(it.bounce_category)+"</span>";}
							if(it.created_at_fmt){sh+="<span class=\"swifttrap-suppression-date\">"+esc(it.created_at_fmt)+"</span>";}
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

		/* --- Email Logs (independent of stats AJAX) --- */
		jQuery(function($){
			var $et=$("#swifttrap-emails-table-wrap"), $ep=$("#swifttrap-emails-pagination");
			if(!$et.length)return;
			var L=swifttrapStats.l10n;
			function esc(t){return $("<span>").text(t).html();}
			function err(m){return "<p class=\"swifttrap-error\">"+esc(m)+"</p>";}

			var STATUS_CLASSES={
				delivered:"swifttrap-status--delivered",
				not_delivered:"swifttrap-status--bounced",
				enqueued:"swifttrap-status--opened",
				opted_out:"swifttrap-status--unsub"
			};

			var PAGE_SIZE=20;
			/* allEntries: buffer of all fetched entries; localPage: current display offset */
			var allEntries=[], apiTotal=0, nextApiCursor=null, localPage=0, loading=false;

			function renderTable(){
				var start=localPage*PAGE_SIZE,
					slice=allEntries.slice(start,start+PAGE_SIZE);
				if(!slice.length){$et.html("<p>"+esc(L.noEmails)+"</p>");$ep.html("");return;}
				var th=function(t){return "<th>"+esc(t)+"</th>";};
				var h="<table class=\"wp-list-table widefat fixed striped\">";
				h+="<thead><tr>"+th(L.colDate)+th(L.colFrom)+th(L.colTo)+th(L.colSubject)+th(L.colCategory)+th(L.colStatus)+"</tr></thead><tbody>";
				$.each(slice,function(i,em){
					var status=em.status||"—",sc=STATUS_CLASSES[status]||"",
						sentAt=em.sent_at||"",
						dateStr=sentAt?new Date(sentAt).toLocaleString():"—",
						fromAddr=em.from||"—",
						toAddr=em.to||"—";
					if(Array.isArray(toAddr))toAddr=toAddr.join(", ");
					h+="<tr>";
					h+="<td>"+esc(dateStr)+"</td>";
					h+="<td>"+esc(fromAddr)+"</td>";
					h+="<td title=\""+esc(toAddr)+"\">"+esc(toAddr.length>40?toAddr.substring(0,40)+"…":toAddr)+"</td>";
					h+="<td title=\""+esc(em.subject||"—")+"\">"+esc(em.subject?em.subject.substring(0,50)+(em.subject.length>50?"…":""):"—")+"</td>";
					h+="<td>"+esc(em.category||em.sending_stream||"—")+"</td>";
					h+="<td><span class=\"swifttrap-email-status "+sc+"\">"+esc(status)+"</span></td>";
					h+="</tr>";
				});
				h+="</tbody></table>";
				$et.html(h);
				var from=start+1,to=Math.min(start+PAGE_SIZE,allEntries.length),
					total=apiTotal||allEntries.length,pag="";
				if(localPage>0)
					pag+="<button class=\"button\" id=\"swifttrap-email-prev\">"+L.prevPage+"</button>";
				pag+="<span style=\"margin:0 8px;\">"+esc(from+"–"+to+" / "+total.toLocaleString())+"</span>";
				var hasNextLocal=allEntries.length>start+PAGE_SIZE,
					hasNextApi=nextApiCursor!==null;
				if(hasNextLocal||hasNextApi)
					pag+="<button class=\"button\" id=\"swifttrap-email-next\">"+L.nextPage+"</button>";
				$ep.html(pag);
			}

			function fetchFromApi(cursor,onDone){
				loading=true;
				$et.html("<p class=\"swifttrap-loading\">Loading...</p>");$ep.html("");
				$.post(ajaxurl,{
					action:"swifttrap_load_emails",
					_nonce:swifttrapStats.emails_nonce,
					cursor:cursor||"",
					search:$("#swifttrap-email-search").val(),
					status:$("#swifttrap-email-status").val(),
					date_from:$("#swifttrap-email-date-from").val(),
					date_to:$("#swifttrap-email-date-to").val()
				},function(r){
					loading=false;
					if(!r.success){$et.html(err(r.data&&r.data.message||L.emailsError));return;}
					var d=r.data;
					allEntries=allEntries.concat(d.entries||[]);
					apiTotal=d.total||0;
					nextApiCursor=d.next_cursor||null;
					if(onDone)onDone();
					renderTable();
				}).fail(function(){loading=false;$et.html(err(L.emailsError));$ep.html("");});
			}

			function reset(){
				allEntries=[];apiTotal=0;nextApiCursor=null;localPage=0;
				fetchFromApi("",null);
			}

			reset();

			$(document).on("click","#swifttrap-email-next",function(){
				if(loading)return;
				var nextStart=(localPage+1)*PAGE_SIZE;
				if(allEntries.length>nextStart){
					localPage++;renderTable();
				}else if(nextApiCursor){
					localPage++;
					fetchFromApi(nextApiCursor,null);
				}
			});
			$(document).on("click","#swifttrap-email-prev",function(){
				if(localPage>0){localPage--;renderTable();}
			});
			$(document).on("click","#swifttrap-email-filter-btn",function(){reset();});
			$(document).on("keypress","#swifttrap-email-search",function(e){
				if(e.which===13)reset();
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

				<!-- Attachments Section -->
				<div class="swifttrap-card">
					<h3><?php esc_html_e( 'Attachments', 'swifttrap-for-mailtrap' ); ?></h3>
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
 * Stats page with usage, domains, and suppressions from Mailtrap API.
 */
function swifttrap_mailtrap_stats_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = swifttrap_mailtrap_get_settings();
	?>
	<div class="wrap">
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
				<h2>
					<span><?php esc_html_e( 'Suppressions', 'swifttrap-for-mailtrap' ); ?></span>
					<a class="button button-secondary" href="https://mailtrap.io/suppressions" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View all in Mailtrap', 'swifttrap-for-mailtrap' ); ?></a>
				</h2>
				<div class="swifttrap-card__body">
					<p class="swifttrap-loading"><?php esc_html_e( 'Loading...', 'swifttrap-for-mailtrap' ); ?></p>
				</div>
			</div>
		</div>

		<!-- Row 3: Email Logs -->
		<div class="swifttrap-card swifttrap-card--full" id="swifttrap-emails-card">
			<h2><?php esc_html_e( 'Email Logs', 'swifttrap-for-mailtrap' ); ?></h2>
			<div class="swifttrap-email-filters" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
				<input type="text" id="swifttrap-email-search" placeholder="<?php esc_attr_e( 'Search by recipient email...', 'swifttrap-for-mailtrap' ); ?>" style="flex:1;min-width:160px;" />
				<select id="swifttrap-email-status" style="min-width:140px;">
					<option value=""><?php esc_html_e( 'All statuses', 'swifttrap-for-mailtrap' ); ?></option>
					<option value="delivered"><?php esc_html_e( 'Delivered', 'swifttrap-for-mailtrap' ); ?></option>
					<option value="not_delivered"><?php esc_html_e( 'Not Delivered', 'swifttrap-for-mailtrap' ); ?></option>
					<option value="enqueued"><?php esc_html_e( 'Enqueued', 'swifttrap-for-mailtrap' ); ?></option>
					<option value="opted_out"><?php esc_html_e( 'Opted Out', 'swifttrap-for-mailtrap' ); ?></option>
				</select>
				<input type="date" id="swifttrap-email-date-from" title="<?php esc_attr_e( 'From date', 'swifttrap-for-mailtrap' ); ?>" style="width:140px;" />
				<input type="date" id="swifttrap-email-date-to" title="<?php esc_attr_e( 'To date', 'swifttrap-for-mailtrap' ); ?>" style="width:140px;" />
				<button class="button" id="swifttrap-email-filter-btn"><?php esc_html_e( 'Filter', 'swifttrap-for-mailtrap' ); ?></button>
			</div>
			<div id="swifttrap-emails-table-wrap">
				<p class="swifttrap-loading"><?php esc_html_e( 'Loading...', 'swifttrap-for-mailtrap' ); ?></p>
			</div>
			<div id="swifttrap-emails-pagination" style="display:flex;align-items:center;gap:12px;margin-top:10px;"></div>
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
