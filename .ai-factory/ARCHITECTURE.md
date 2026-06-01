# Architecture — SwiftTrap for Mailtrap

## Directory Layout

```
swifttrap-for-mailtrap.php   # Bootstrap: constants, requires, pre_wp_mail hook, payload build/send
includes/
  swifttrap-api.php          # Mailtrap API calls (account/stats/domains/suppressions), logging, categories, AJAX
  admin.php                  # Settings + Stats pages, menu, dashboard widget, settings sanitization, admin assets
assets/admin.css             # Admin UI styles
languages/                   # .pot translation template
uninstall.php                # Cleanup on uninstall
readme.txt / README.md       # wp.org listing + GitHub readme
.wordpress-org/              # wp.org assets (icons/banners) — pushed to SVN /assets, not bundled
.distignore                  # release exclusions
```

Procedural plugin: no classes. All symbols are prefixed `swifttrap_mailtrap_*` and grouped by file responsibility.

## Key Components

**`swifttrap-for-mailtrap.php` (send pipeline)**
- `swifttrap_mailtrap_pre_wp_mail()` — the entry hook on `pre_wp_mail` (prio 1). Short-circuits `wp_mail()` when enabled + token set.
- `swifttrap_mailtrap_get_settings()` / `_default_settings()` — option `swifttrap_mailtrap_settings`.
- `swifttrap_mailtrap_normalize_atts()` + helpers (`_parse_recipients`, `_normalize_attachments`, `_normalize_embeds`, `_message_looks_html`) — turn `wp_mail()` atts into a normalized message.
- `swifttrap_mailtrap_build_payload()` — assemble the Mailtrap JSON body (incl. category, template, custom vars).
- `swifttrap_mailtrap_send()` — POST via `wp_remote_post` to the correct stream host; returns `true` / `WP_Error`.
- `swifttrap_mailtrap_trigger_failed()` — fire `wp_mail_failed` on errors.

**`includes/swifttrap-api.php` (Mailtrap data + logging)**
- `_should_use_bulk_stream()`, `_detect_email_category()`, `_get_email_category()` — routing & categorization logic.
- `_get_account_data` / `_get_account_id` / `_fetch_stats` / `_fetch_domains` / `_fetch_suppressions` — read-side Mailtrap account API.
- Logging: `_get_log_dir/_file`, `_log_email`, `_cleanup_logs`, `_read_email_logs`, `_compute_log_stats`.
- AJAX handlers: `_ajax_send_test_email`, `_ajax_clear_logs`, `_ajax_load_api_data`.

**`includes/admin.php` (UI)**
- `_register_settings` + `_sanitize_settings`, `_register_menu`, `_admin_assets`.
- `_settings_page`, `_stats_page`, `_format_log_entry`.
- Dashboard widget: `_register_dashboard_widget`, `_dashboard_widget_content`.

## Data Flow

1. Any code calls `wp_mail()` → WP fires `pre_wp_mail`.
2. `swifttrap_mailtrap_pre_wp_mail()` checks settings; if disabled/no token → returns `$pre_wp_mail` (default handler runs).
3. Otherwise: `normalize_atts()` → category detection → `should_use_bulk_stream()` picks host → `build_payload()` → `send()` (`wp_remote_post`).
4. Result logged via `log_email()`; failures raise `wp_mail_failed`. Hook returns `true` to tell WP the mail was handled.
5. Admin Stats page pulls live account data (stats/domains/suppressions) on demand via AJAX → Mailtrap account API.

## Hooks & Extension Points

- **Consumes:** `pre_wp_mail`, `admin_menu`, `admin_enqueue_scripts`, `admin_init` (settings), `wp_dashboard_setup`, `wp_ajax_*` for the three AJAX actions.
- **Provides (filters):** `swifttrap_mailtrap_email_category`, `swifttrap_mailtrap_use_bulk_stream`, `swifttrap_mailtrap_template`, `swifttrap_mailtrap_custom_variables`.

## Notes

- No runtime Composer deps; release zip excludes `.git/.github/.wordpress-org/.ai-factory*` per `.distignore`.
- Logs live under the uploads dir via `WP_Filesystem`; retention enforced by `cleanup_logs`.
