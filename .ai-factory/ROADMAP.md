# Roadmap — SwiftTrap for Mailtrap

> Ship the most Mailtrap-native `wp_mail()` replacement for WordPress: HTTP API delivery with real
> delivery status, suppression hygiene, and zero local email storage.

## Milestones

- [x] **M1 — PHP 8.0 / WP 7.0 hardening (2.3.0)** — code-style cleanup, JSON error handling, token-versioned cache, deterministic log-retention cron, send retry/backoff, multisite-safe settings, verify-token button, PHPUnit + Brain Monkey test harness. See `archive/plans/m1-php8-wp7-hardening.md`.
- [x] **M2 — Functional feature expansion (2.4.0)** — Mailtrap webhook receiver, suppression management (add/remove + auto-skip pre-send), graceful fallback to native `wp_mail()`, Site Health test, category→stream & sender mapping UI, WP-CLI commands, attachment-size guard. See `archive/plans/m2-functional-features.md`.
- [x] **2.4.1 / 2.4.2 — Suppression & email-log fixes** — suppression edge-case fixes and a fix for an email-log write race that dropped entries under concurrent sends.
- [x] **3.0.0 — Live API-based email log** — replaced local file-based email logging entirely with a live view backed by Mailtrap's `/api/email_logs` endpoint (search/filter/pagination), removing the local write-race class of bugs at the source.
- [ ] **Email log detail & resend** — "view payload" modal and a **resend** action for a failed log entry, built on the existing `swifttrap_mailtrap_fetch_emails` live-log view.
- [ ] **Sends-per-day analytics widget** — a simple chart on the Stats page (by category/status), built on the same live `email_logs` data instead of a local aggregate.
- [ ] **CSV export** of the current (filtered) email log view.

## Completed

| Milestone | Date |
|-----------|------|
| M1 — PHP 8.0 / WP 7.0 hardening | 2.3.0 |
| M2 — Functional feature expansion | 2.4.0 |
| Suppression & email-log fixes | 2.4.1 / 2.4.2 |
| Live API-based email log | 3.0.0 |

## Remaining backlog

Priority: **H** high value / clear demand · **M** solid · **L** nice-to-have.

- **M — Email log detail & resend.** The live log table (`admin.php` Email Logs card) shows list rows only; there's no payload/detail view and no way to resend a `not_delivered` message without leaving WP. *Touches:* `admin.php` (modal + resend AJAX), `swifttrap-api.php` (resend needs the original send payload, which Mailtrap's read API may not return in full — check `email_logs` response fields before committing to inline resend vs. a simpler "send a fresh test to this recipient").
- **M — Sends-per-day analytics widget.** Stats page has usage/domains/suppressions but no time-series view. Aggregate client-side from paginated `email_logs` responses, or add a dedicated summarized fetch if Mailtrap's API supports date-bucketed counts.
- **L — CSV export** of the email log, respecting the current search/status/date filters.
- **L — Multiple sender identities beyond category mapping.** Today `category_senders` covers per-category From override; a per-site or ad-hoc identity picker is not implemented.

## Superseded (kept only as history)

Everything from the original functional backlog — webhook delivery tracking, suppression list management, graceful fallback, Site Health, deterministic log retention, WP-CLI, attachment guardrails, category→stream rules UI, retry/backoff — shipped between 2.3.0 and 3.0.0. See `archive/plans/` for the original scoping documents.
