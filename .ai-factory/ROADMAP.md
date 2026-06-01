# Roadmap — SwiftTrap for Mailtrap

> **Status:** all items below are scheduled in `plans/m2-functional-features.md` (release 2.4.0), to run
> after `plans/m1-php8-wp7-hardening.md` (release 2.3.0) ships. This file is the rationale/source for each.

Functional improvement backlog (what to add/improve), grounded in current capabilities:
drop-in `wp_mail()` → Mailtrap API, categories, bulk/transactional streams, file-based email log,
stats page (domain verification + suppression list), test email, templates, dashboard widget.

Priority: **H** high value / clear demand · **M** solid · **L** nice-to-have.
"(in harden plan)" = already scheduled in `plans/harden-php8-wp7-functional.md`, not repeated here.

## Delivery tracking & events — the biggest gap
- **H — Mailtrap webhook receiver.** Today the log records only the *send attempt*, not the final outcome. Register a REST endpoint to receive Mailtrap delivery events (delivered / bounce / spam / open / click) and write the real status back onto the log row. Turns the log from "we tried" into "what actually happened." *Touches:* new `includes/` REST handler + log schema (`swifttrap-api.php` logging).
- **M — Suppression list management.** Stats page shows suppressions read-only (`swifttrap-api.php:244`). Add add/remove actions (Mailtrap API supports it) so admins can clear a bounce without leaving WP.

## Reliability & deliverability
- **H — Graceful fallback to native `wp_mail()`** when the API is unreachable after retries (in harden plan adds retry/backoff; this adds the final fallback so mail isn't simply lost during an outage). *Touches:* `swifttrap-for-mailtrap.php` send pipeline.
- **M — Site Health test.** Add a `site_status_tests` check validating token + sender-domain verification, surfaced in Tools → Site Health. Reuses `swifttrap_mailtrap_get_account_data` / `fetch_domains`.
- **M — Auto-skip suppressed recipients** before send (short-circuit known bounces/complaints) to protect sender reputation.

## Admin UX & reporting
- **M — Email log UI upgrade.** Search/filter by recipient, category, status, date; "view payload" and **resend** for a failed entry. Builds on `read_email_logs` / `format_log_entry` (`admin.php:435`).
- **M — Analytics widget.** Sends-per-day by category/status chart on the Stats page (extend `compute_log_stats`, `swifttrap-api.php:587`).
- **L — CSV export** of the email log.
- **L — Category→stream rules UI.** Routing is filter-only today (`swifttrap_mailtrap_use_bulk_stream`); expose a simple per-category mapping table in settings.

## Power users
- **M — WP-CLI commands:** `wp swifttrap test`, `wp swifttrap stats`, `wp swifttrap prune-logs`. Wraps existing functions; great for staging/CI.
- **L — Multiple sender identities** (per-category or per-site From) beyond the single sender in settings.
- **L — Attachment guardrails:** warn/skip when total attachment size exceeds a configurable cap (current code reads `filesize()` per attachment at `swifttrap-for-mailtrap.php:368`).

## Already covered by the harden plan
Verify-token button, deterministic log retention (cron), token-versioned cache, multisite-safe settings, JSON-error handling, send retry/backoff, unit tests.
