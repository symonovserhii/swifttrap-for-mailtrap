# M1 — Release 2.3.0: PHP 8.0 floor, WP 7.0, code-style & hardening

**Created:** 2026-05-31
**Mode:** full · **Milestone:** M1 (ship first; low risk)
**Branch:** feature/m1-php8-wp7-harden
**Executor:** Antigravity. **Reviewer:** Sonnet subagent. **Release/deploy:** Claude (post-review).
**Sibling:** M2 features → `m2-functional-features.md` (later release 2.4.0).

## Settings
- **Testing:** Yes — stand up PHPUnit + Brain Monkey here; M2 extends it.
- **Logging:** Minimal new paths; guard verbose under `WP_DEBUG`.
- **Docs:** `readme.txt` stale-string fix only. Stable tag/changelog = Claude at release.
- **Roadmap linkage:** the "Reliability/robustness" hardening slice; feature ROADMAP is M2.

## Goal
Clean PHP 8.0-minimum / WP 7.0-tested baseline plus the silent-failure fixes from code review, shipped as a
low-risk maintenance release. No net-new product surface except the tiny verify-token button. Code quality is
already strong (escaping, nonces, caps) — this is hardening only.

## Scope
### Files modified
- `swifttrap-for-mailtrap.php` — `list()`→`[]`; return types; multisite-safe settings cache; send retry/backoff; cron registration for log cleanup.
- `includes/swifttrap-api.php` — JSON error handling; token-versioned transient keys; deterministic cleanup (cron callback, drop random gate); return types.
- `includes/admin.php` — verify-token button + AJAX (reuse nonce + `manage_options`).
- `readme.txt` — fix stale changelog line only.
- **New:** `composer.json` (dev), `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/**`.
### Files NOT touched (Antigravity)
- Version number / `Stable tag` / `== Changelog ==` — **Claude at release.**
- `.distignore` (verify excludes `tests`/`composer.*`/`vendor`), `.wordpress-org/`, `svn/`.
- Anything in M2 (webhook, suppression mgmt, fallback, log UI, CLI, analytics, CSV).

## Work items
### 1. Code style / PHP 8.0 / WP 7.0
- `swifttrap-for-mailtrap.php:170` `list()`→`[]`. Add return/param types to ~12 untyped functions (`get_settings(): array`, `normalize_atts(): array|WP_Error`, …). 8.0-safe only; `php -l` clean.
- `readme.txt:119` `6.9.4`→`7.0`. (Other headers already consistent: WP 6.0 / 7.0, PHP 8.0.)
### 2. Robustness (code review)
- **JSON error handling** `swifttrap-api.php:67,142,206,277` — check `json_last_error()`; degrade gracefully.
- **Token-versioned cache** `swifttrap-api.php:48,114,174,245` — `substr(md5($token),0,8)` in keys.
- **Deterministic log retention** `swifttrap-api.php:411–415` — daily WP-Cron (activation/deactivation), drop `wp_rand(1,100)===1`.
- **Send retry/backoff** `swifttrap-for-mailtrap.php:514–554` — ~2 retries on timeout/429/5xx, honor `Retry-After`; `WP_Error` after exhaustion.
- **Multisite-safe settings** `swifttrap-for-mailtrap.php:87–113` — key static cache by `get_current_blog_id()`.
- **Verify-token button** `admin.php:335–338` — OK/Fail via `swifttrap_mailtrap_get_account_data()`.
### 3. Tests
- Unit: recipient/attachment normalization, `build_payload`, `should_use_bulk_stream`, category detection, log read/stats parsing, new JSON-error + retry paths. Brain Monkey mocks.

## Constraints
- Preserve escaping/nonce/capability discipline. No new runtime dependency. 8.0 floor: no 8.1+-only syntax.

## Verification
- `php -l` clean; `vendor/bin/phpunit` green.
- Grep: no `6.9.4`; no `wp_rand( 1, 100 )` gate; every `json_decode` followed by an error check.
- Manual: test email → log + correct stream; token switch → cached stats refresh; force 5xx → retry; cron scheduled.
