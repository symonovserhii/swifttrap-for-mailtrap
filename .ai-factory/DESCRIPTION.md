# SwiftTrap for Mailtrap

## Overview

*A drop-in `wp_mail()` replacement that routes WordPress email through the Mailtrap HTTP API — not SMTP.*

SwiftTrap short-circuits WordPress's `wp_mail()` and delivers every message via the **Mailtrap Email Sending API** (`send.api.mailtrap.io` for transactional, `bulk.api.mailtrap.io` for bulk). It is purpose-built for Mailtrap rather than a generic SMTP plugin with a Mailtrap preset, so it exposes Mailtrap-native capabilities that SMTP cannot: bulk vs transactional stream routing, email categories, custom tracking variables, suppression lists, and sending-domain verification status. It uses only the WordPress HTTP API (`wp_remote_post`) — no Mailtrap PHP SDK — and ships at roughly 30 KB.

Target user: any WordPress site owner who already sends through Mailtrap and wants API-grade deliverability and reporting instead of SMTP round-trips. Works transparently with Contact Form 7, WooCommerce, Gravity Forms, and anything that calls `wp_mail()`.

Current version: **2.2.3** (published on wordpress.org, slug `swifttrap-for-mailtrap`).

## Core Features (implemented)

### Mail delivery
- Hooks `pre_wp_mail` (priority 1) to intercept all outgoing mail; falls back to the default WordPress handler when disabled or the API token is empty.
- Normalizes `wp_mail()` attributes (recipients, subject, HTML/plain detection, attachments, inline embeds) into the Mailtrap payload shape.
- Transactional vs **bulk stream** routing, chosen automatically by category or forced via filter.
- Mailtrap **template** support via `template_uuid`.

### Categorization & tracking
- Automatic email categorization (welcome, password-reset, notification, marketing, …) from the message context.
- Custom variables attached to outgoing messages for tracking/reporting in Mailtrap.

### Logging & reporting
- File-based email log with retention/cleanup management (what was sent, when, to whom, success/failure, category).
- Log stats computed over a rolling window; dashboard widget with at-a-glance integration status.
- Stats page: sending-domain verification status + live suppression list (bounces, complaints, unsubscribes), fetched from `mailtrap.io/api/accounts`.

### Admin UX
- Settings page (token, verified sender email/name, enable toggle) with a **test email** button (AJAX).
- Admin assets (`assets/admin.css`); registered settings with sanitization.
- AJAX endpoints: send test email, clear logs, load live API data (stats/domains/suppressions).

## Extensibility (filters)

- `swifttrap_mailtrap_email_category` — override the auto-detected category.
- `swifttrap_mailtrap_use_bulk_stream` — force bulk vs transactional stream.
- `swifttrap_mailtrap_template` — send via a Mailtrap template `template_uuid`.
- `swifttrap_mailtrap_custom_variables` — attach tracking metadata.

## Tech Stack

- **PHP** ≥ 8.0, **WordPress** ≥ 6.0 (tested to 7.0).
- Procedural, prefixed-function architecture (`swifttrap_mailtrap_*`) — no OOP, no Composer runtime dependencies.
- WordPress HTTP API (`wp_remote_post`) for all Mailtrap calls; `WP_Filesystem` for log storage.
- No third-party SDK; total footprint ~30 KB.
