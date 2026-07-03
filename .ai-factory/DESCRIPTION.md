# SwiftTrap for Mailtrap

## Overview

*A drop-in `wp_mail()` replacement that routes WordPress email through the Mailtrap HTTP API — not SMTP.*

SwiftTrap short-circuits WordPress's `wp_mail()` and delivers every message via the **Mailtrap Email Sending API** (`send.api.mailtrap.io` for transactional, `bulk.api.mailtrap.io` for bulk). It is purpose-built for Mailtrap rather than a generic SMTP plugin with a Mailtrap preset, so it exposes Mailtrap-native capabilities that SMTP cannot: bulk vs transactional stream routing, email categories, custom tracking variables, suppression-list management, sending-domain verification status, and delivery-event webhooks. It uses only the WordPress HTTP API (`wp_remote_*`) — no Mailtrap PHP SDK.

Target user: any WordPress site owner who already sends through Mailtrap and wants API-grade deliverability, real delivery status, and suppression hygiene instead of SMTP round-trips. Works transparently with Contact Form 7, WooCommerce, Gravity Forms, and anything that calls `wp_mail()`.

Current version: **3.0.1** (published on wordpress.org, slug `swifttrap-for-mailtrap`).

## Core Features (implemented)

### Mail delivery
- Hooks `pre_wp_mail` (priority 1) to intercept all outgoing mail; falls back to the default WordPress handler when disabled, the API token is empty, or the Mailtrap API call ultimately fails after retries (graceful reliability fallback).
- Normalizes `wp_mail()` attributes (recipients, subject, HTML/plain detection, attachments, inline embeds) into the Mailtrap payload shape.
- Transactional vs **bulk stream** routing per category, with a settings-page mapping table plus a filter override.
- Per-category **custom sender identity** override (name/email) via the same mapping table.
- Send retry with backoff (up to 3 attempts) on timeout / HTTP 429 / 5xx, honoring `Retry-After`.
- Configurable **attachment size guard** (1–25 MB) — oversized attachments fail the send with a clear error instead of silently rejecting at the API gateway.
- Mailtrap **template** support via `template_uuid`.
- `SWIFTTRAP_BLOCK_BULK` constant to hard-block promotional/bulk sends on an environment (e.g. staging).

### Suppression management
- Pre-send **auto-skip of suppressed recipients** (to/cc/bcc individually filtered); if every recipient is suppressed the send fails with a descriptive `WP_Error` instead of silently vanishing.
- Suppression list CRUD from the Stats page: view (with reason/bounce-category/date), add a manual suppression, remove a suppression — backed directly by the Mailtrap Suppressions API.

### Delivery tracking & webhooks
- REST route `swifttrap/v1/webhook` (`includes/swifttrap-webhook.php`) receives Mailtrap delivery events (delivered/bounce/spam/open/click/unsubscribe), verified via HMAC-SHA256 over the raw request body (`Mailtrap-Signature` header, `hash_hmac`/`hash_equals`) against the per-webhook signing secret.
- Verified events fire the `swifttrap_mailtrap_webhook_event` action so other code (or a future feature) can react to real delivery outcomes, not just "we attempted to send."

### Categorization & tracking
- Automatic email categorization (welcome, password-reset, notification, verification, transactional, promotional, general) from subject/body keywords, or forced to `general` when auto-detection is off.
- Custom variables attached to outgoing messages for tracking/reporting in Mailtrap (`swifttrap_mailtrap_custom_variables` filter).

### Reporting — 100% live from the Mailtrap API (no local storage)
- **Email log is not stored locally.** The Stats page's Email Logs table is read live from Mailtrap's `/api/email_logs` endpoint with server-side search (recipient), status filter, date-range filter, and cursor-based pagination.
- Usage card (plan, team, monthly sent/limit with a quota bar), sending-domain verification status with per-record DNS check state, and the suppression summary/list — all fetched from `mailtrap.io/api/accounts/*` and cached in transients (1 hour, keyed by a token hash so switching tokens invalidates cleanly).
- Dashboard widget with at-a-glance enabled/disabled/token-missing status and sender identity.

### Site Health integration
- Registers a direct Site Health test (`includes/swifttrap-site-health.php`) that validates: plugin enabled, token present, Mailtrap API reachable, and the sender email's domain is registered **and** DNS-verified in Mailtrap — surfaced under Tools → Site Health with actionable status text.

### WP-CLI
- `wp swifttrap test [--to=<email>]` — send a test email.
- `wp swifttrap stats` — print team/plan/usage.
- `wp swifttrap prune-logs` — no-op (kept for backward compatibility; email history now lives entirely in Mailtrap).
- `wp swifttrap send-suppression-sync` — force-refresh the suppressions cache.

### Admin UX
- Settings page (token + verify-token AJAX check, verified sender email/name, enable toggle, attachment cap, category/auto-categorize toggles, webhook URL + secret, category→stream & sender mapping table) with a **test email** button (AJAX).
- Stats page (usage, sending domains, suppressions with add/remove, live email log with filters) — all loaded asynchronously after page render.
- Admin assets (`assets/admin.css`); registered settings with sanitization; nonce + `manage_options` capability check on every AJAX/REST/webhook handler.

## Extensibility (filters & actions)

- `swifttrap_mailtrap_email_category` — override the auto-detected category.
- `swifttrap_mailtrap_use_bulk_stream` — force bulk vs transactional stream.
- `swifttrap_mailtrap_template` — send via a Mailtrap template `template_uuid` (+ variables).
- `swifttrap_mailtrap_custom_variables` — attach tracking metadata.
- `swifttrap_mailtrap_webhook_event` (action) — fired per verified inbound Mailtrap delivery event.

## Tech Stack

- **PHP** ≥ 8.0, **WordPress** ≥ 6.0 (tested to 7.0).
- Procedural, prefixed-function architecture (`swifttrap_mailtrap_*`) — no OOP, no Composer runtime dependencies.
- WordPress HTTP API (`wp_remote_get`/`wp_remote_post`/`wp_remote_request`) for all Mailtrap calls; `WP_Filesystem` only for reading attachment contents (no log storage — logs are 100% Mailtrap-API-backed as of 3.0.0).
- WordPress REST API for the webhook receiver; Site Health API for the status test; WP-CLI for command-line management.
- Composer/PHPUnit 9.5 + Brain Monkey for tests (`require-dev` only — no runtime dependency).

## Non-Functional Requirements

- **Security:** every AJAX handler checks a nonce + `current_user_can( 'manage_options' )`; the webhook route verifies a per-site secret with a timing-safe `hash_equals` comparison and has no capability check (it's a server-to-server callback, not user-facing).
- **Privacy:** email payloads (recipients, subject, body, attachments) and account/log/suppression reads are the only data sent to Mailtrap (`mailtrap.io`, `send.api.mailtrap.io`, `bulk.api.mailtrap.io`). No third-party analytics, no local persistence of message content beyond the current request.
- **Reliability:** API failures degrade to native `wp_mail()` rather than dropping the message silently; transient errors get bounded retries with backoff.

## Architecture

See `.ai-factory/ARCHITECTURE.md` for detailed architecture guidelines.
Pattern: Layered Architecture (entry points → business logic → Mailtrap integration layer).
