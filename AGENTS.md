# AGENTS.md

> Structural map for AI agents and new developers. Keep this in sync when the project layout changes.

## Project Overview

SwiftTrap for Mailtrap — a WordPress plugin that replaces `wp_mail()` with the Mailtrap HTTP Send API (not SMTP), adding categories, bulk/transactional stream routing, suppression management, delivery-event webhooks, Site Health integration, and a live (Mailtrap-API-backed) email log.

## Tech Stack

- **Programming language:** PHP 8.0+
- **Platform:** WordPress 6.0+ plugin (tested to 7.0)
- **Style:** Procedural, prefixed-function architecture (`swifttrap_mailtrap_*`) — no classes in production code, no autoloader
- **External API:** Mailtrap Email Sending API (`send.api.mailtrap.io`, `bulk.api.mailtrap.io`) + account API (`mailtrap.io/api/accounts/*`, `/api/email_logs`) — all via WordPress HTTP API (`wp_remote_*`), no SDK
- **Storage:** single `wp_options` row (`swifttrap_mailtrap_settings`); no custom tables, no local email log (removed in 3.0.0 — email history is read live from Mailtrap)
- **Build / dependencies:** no runtime Composer dependency; `require-dev` only (PHPUnit 9.5 + Brain Monkey for tests)

## Project Structure

```
swifttrap-for-mailtrap/
├── swifttrap-for-mailtrap.php     # Bootstrap + send pipeline: constants, requires, pre_wp_mail hook,
│                                  # settings, normalize_atts, build_payload, send (retry/backoff)
├── includes/
│   ├── swifttrap-api.php          # Mailtrap account/stats/domains/suppressions/email-logs (integration
│   │                              # layer), categorization + suppression-check (business logic), AJAX
│   ├── admin.php                  # Settings + Stats pages, menu, dashboard widget, sanitization, admin assets
│   ├── swifttrap-webhook.php      # REST route swifttrap/v1/webhook — Mailtrap delivery-event receiver
│   ├── swifttrap-site-health.php  # Site Health test (token + sender-domain verification)
│   └── swifttrap-cli.php          # WP-CLI commands (wp swifttrap ...), guarded on WP_CLI
├── assets/admin.css                # Admin UI styles
├── tests/                          # PHPUnit + Brain Monkey unit tests
│   ├── bootstrap.php
│   ├── wp-stubs.php
│   └── PluginTest.php
├── languages/                      # .pot translation template
├── uninstall.php                   # Cleanup on uninstall
├── readme.txt / README.md          # wp.org listing + GitHub readme
├── .wordpress-org/                 # wp.org assets (icons/banners) — pushed to SVN /assets, not bundled
├── .distignore                     # release exclusions
├── AGENTS.md                       # This file
└── .ai-factory/
    ├── config.yaml
    ├── DESCRIPTION.md
    ├── ARCHITECTURE.md
    ├── ROADMAP.md
    ├── plans/
    ├── archive/plans/               # Completed plans (M1, M2)
    └── rules/base.md
```

## Key Entry Points

| File | Purpose |
|------|---------|
| `swifttrap-for-mailtrap.php` | Plugin header + composition root: defines constants, `require_once`s the four `includes/` modules, registers activation/deactivation hooks, hooks `pre_wp_mail` (priority 1) to short-circuit `wp_mail()`, and owns the full send pipeline (normalize → suppression filter → category → payload → send with retry/backoff → fallback to native `wp_mail()` on failure). |
| `includes/swifttrap-api.php` | All Mailtrap HTTP calls (account, stats, domains, suppressions, live email log) with token-hashed transient caching; email categorization/stream-routing logic; suppression add/remove/check; every Stats-page AJAX handler. |
| `includes/admin.php` | Settings page (token, sender, categories, attachment cap, webhook secret, category→stream/sender mapping table) and Stats page (usage, domains, suppressions, live email log with search/filter/pagination); dashboard widget; verify-token AJAX. |
| `includes/swifttrap-webhook.php` | REST route `swifttrap/v1/webhook` — verifies the `Mailtrap-Signature` HMAC-SHA256 header against the raw body, fires `swifttrap_mailtrap_webhook_event` per delivery event. |
| `includes/swifttrap-site-health.php` | Registers a Site Health direct test validating enablement, token, API connectivity, and sender-domain verification. |
| `includes/swifttrap-cli.php` | `wp swifttrap test\|stats\|prune-logs\|send-suppression-sync`. |

## Documentation

| Document | Path | Description |
|----------|------|-------------|
| README | `README.md` | GitHub project landing page |
| wp.org listing | `readme.txt` | wordpress.org plugin directory listing (features, FAQ, changelog) |

## AI Context Files

| File | Purpose |
|------|---------|
| `AGENTS.md` | Project map and entry points for AI agents. |
| `.ai-factory/DESCRIPTION.md` | Detailed project specification (features, stack, NFRs). |
| `.ai-factory/ARCHITECTURE.md` | Architecture pattern (Layered Architecture), folder structure, dependency rules. |
| `.ai-factory/ROADMAP.md` | Milestones; next up: email log detail & resend, sends-per-day analytics widget, CSV export. |
| `.ai-factory/rules/base.md` | Detected naming, structure, error-handling, and security conventions. |
| `.ai-factory/config.yaml` | AI Factory configuration (language, git workflow, paths). |

## Agent Rules

- Decompose shell commands instead of chaining with `&&` so each step can be reviewed independently.
  - Incorrect: `git checkout main && git pull`
  - Correct: first `git checkout main`, then `git pull origin main`.
- Every PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- Every admin page function additionally checks `if ( ! current_user_can( 'manage_options' ) ) { return; }`.
- Every AJAX handler calls `check_ajax_referer( '<action>', '_nonce' )` before the capability check.
- New `wp_remote_*` calls to Mailtrap go in `includes/swifttrap-api.php` next to the existing fetch functions (token-hashed transient cache pattern) — never inline in `admin.php` or another entry point.
- Do not reintroduce local email-log storage. As of 3.0.0 the email log is read live from Mailtrap's `/api/email_logs`; new "history" features should extend that live fetch, not write local files/options.
- Version bumps touch three places in lockstep: `swifttrap-for-mailtrap.php` (`Version:` header + `SWIFTTRAP_MAILTRAP_VERSION`), `readme.txt` (`Stable tag` + changelog), and `composer.json` is version-agnostic (no bump needed there).
- New functions are prefixed `swifttrap_mailtrap_`; new options/nonce actions/transient keys follow the same prefix convention documented in `.ai-factory/rules/base.md`.
