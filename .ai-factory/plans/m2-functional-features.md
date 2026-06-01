# M2 — Release 2.4.0: functional feature expansion

**Created:** 2026-05-31
**Mode:** full · **Milestone:** M2 (after M1 ships)
**Branch:** feature/m2-features
**Executor:** Antigravity. **Reviewer:** Sonnet subagent. **Release/deploy:** Claude (post-review).
**Depends on:** `m1-php8-wp7-hardening.md` merged/released first (assumes PHP 8.0 baseline + cron + test harness in place).
**Source:** every item here is from `../ROADMAP.md`.

## Settings
- **Testing:** Yes — extend the M1 PHPUnit suite per feature.
- **Logging:** New runtime paths (webhook, fallback, CLI) — log failures; `WP_DEBUG` for verbose.
- **Docs:** `readme.txt` features/FAQ/hooks/REST/CLI. Stable tag/changelog = Claude at release.

## Goal
Turn SwiftTrap from "send + read-only stats" into a delivery-tracking, self-healing plugin: real delivery
status via Mailtrap webhooks, suppression management, graceful fallback, richer log UX, analytics, CLI.

## Scope
### Files modified
- `swifttrap-for-mailtrap.php` — fallback to native `wp_mail` after retries; auto-skip suppressed recipients; attachment-size guard; webhook + CLI bootstrap.
- `includes/swifttrap-api.php` — suppression add/remove; analytics aggregation; log schema extension (message id + status).
- `includes/admin.php` — log UI upgrade (search/filter/view-payload/resend); category→stream rules UI; suppression management UI; analytics widget; CSV export.
- `readme.txt` — document features/hooks/REST/CLI.
### New files
- `includes/swifttrap-webhook.php` — REST endpoint for Mailtrap events → update log rows.
- `includes/swifttrap-cli.php` — WP-CLI commands.
- `includes/swifttrap-site-health.php` — Site Health test.
### Files NOT touched (Antigravity)
- Version number / `Stable tag` / `== Changelog ==` — **Claude at release.**

## Work items
### 1. Delivery tracking & events (highest value)
- **Webhook receiver** (`swifttrap-webhook.php`): `register_rest_route` for Mailtrap events (delivered/bounce/spam/open/click); verify a shared secret; update the matching log row's status. Extend log schema/format (`swifttrap-api.php` logging) with a message id + status field. Add a settings field showing the webhook URL.
- **Suppression management** (`swifttrap-api.php:244` + `admin.php`): add/remove suppressions via Mailtrap API from the Stats page (today read-only).
- **Auto-skip suppressed recipients** pre-send (`swifttrap-for-mailtrap.php` pipeline); log skip reason.
### 2. Reliability features
- **Graceful fallback to native `wp_mail()`** after retries (`swifttrap-for-mailtrap.php`): re-enter WP's default handler without the `pre_wp_mail` short-circuit; log fallback.
- **Site Health test** (`swifttrap-site-health.php`): `site_status_tests` for token validity + sender-domain verification.
### 3. Admin UX & reporting
- **Log UI upgrade** (`admin.php`): search/filter by recipient/category/status/date; view-payload modal; **resend** a failed entry. Build on `read_email_logs`/`format_log_entry`.
- **Analytics widget** (`admin.php` + `compute_log_stats`, `swifttrap-api.php:587`): sends-per-day by category/status.
- **CSV export** (`admin.php`, cap+nonce).
- **Category→stream rules UI** (`admin.php`): per-category mapping (today filter-only).
### 4. Power users
- **WP-CLI** (`swifttrap-cli.php`): `wp swifttrap test|stats|prune-logs|send-suppression-sync` (guard `WP_CLI`).
- **Attachment-size guard** (`swifttrap-for-mailtrap.php:350–414`): warn/skip over a configurable cap.
- **Multiple sender identities** (L): per-category From override.
### 5. Tests
- Extend suite: fallback, suppression-skip, webhook status-update, CSV row building, analytics aggregation.

## Constraints
- Every new admin/REST/AJAX surface: escaping + nonce + capability; webhook verifies a secret; resend/export behind `manage_options`.
- No new runtime dependency; SDK-free. 8.0 floor: no 8.1+-only syntax. Each feature behind a clean file/function seam.

## Verification
- Webhook: POST a sample event → matching log row flips to delivered/bounced.
- Fallback: API down → mail still sends natively; log shows fallback.
- Suppression add/remove reflects via API; suppressed recipient skipped pre-send.
- Log UI filter/view/resend/CSV work behind nonce+cap. Each CLI command runs. Site Health test appears.
- `php -l` clean; `vendor/bin/phpunit` green.
