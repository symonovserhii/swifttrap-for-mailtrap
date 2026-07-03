# Architecture: Layered Architecture

## Overview

SwiftTrap is a small, single-purpose WordPress plugin (5 files, procedural PHP, no classes, no Composer runtime dependency). Its code already separates cleanly into three horizontal layers even though nothing enforces that separation formally: WordPress **entry points** (hooks, REST route, AJAX actions, WP-CLI commands, Site Health), **business logic** (mail normalization/categorization/suppression/payload building — the actual send pipeline), and an **integration layer** (every `wp_remote_*` call to the Mailtrap HTTP API). Layered Architecture is the right level of formalism here: the plugin is maintained by one author, the domain is narrow (send an email, read Mailtrap account data), and a heavier pattern (Structured Modules, Explicit Architecture) would add ceremony a 5-file plugin doesn't need.

The plugin has **no database of its own** — as of 3.0.0, all "reporting" state (email log, suppressions, domains, stats) is fetched live from the Mailtrap API and cached only in short-lived WordPress transients. The only persistent local state is a single `wp_options` row (`swifttrap_mailtrap_settings`).

## Decision Rationale

- **Project type:** WordPress plugin, single integration (Mailtrap), no multi-tenant domain complexity.
- **Tech stack:** PHP 8.0+, WordPress 6.0+ (7.0-tested), procedural (no OOP), WordPress HTTP/REST/CLI/Site-Health APIs only — no framework, no ORM, no runtime Composer packages.
- **Key factor:** small, single-maintainer surface area (~5 files) with one external dependency (the Mailtrap API). Structured Modules or Explicit Architecture would introduce interfaces/DI/DTO ceremony with no corresponding payoff at this scale; Layered Architecture matches what the codebase already does in practice.

## Folder Structure

```
swifttrap-for-mailtrap.php     # ENTRY POINT + BUSINESS LOGIC (composition root + send pipeline)
                                #   - register_activation_hook / register_deactivation_hook
                                #   - add_filter( 'pre_wp_mail', ... )   ← the one "route" this plugin owns
                                #   - swifttrap_mailtrap_get_settings()/_default_settings()  (settings "model")
                                #   - swifttrap_mailtrap_normalize_atts() + helpers            (business logic)
                                #   - swifttrap_mailtrap_build_payload()                       (business logic)
                                #   - swifttrap_mailtrap_send()                                (integration layer: wp_remote_post + retry/backoff)
includes/
  swifttrap-api.php            # BUSINESS LOGIC + INTEGRATION LAYER
                                #   - _should_use_bulk_stream, _detect_email_category, _get_email_category  (business logic)
                                #   - _is_recipient_suppressed                                              (business logic)
                                #   - _get_account_data/_fetch_stats/_fetch_domains/_fetch_suppressions/
                                #     _fetch_emails/_add_suppression/_delete_suppression                    (integration layer: all wp_remote_* calls to mailtrap.io)
                                #   - AJAX handlers (_ajax_send_test_email, _ajax_load_api_data,
                                #     _ajax_load_emails, _ajax_add_suppression, _ajax_delete_suppression)   (entry points)
  admin.php                    # ENTRY POINTS (presentation): settings/stats pages, menu, dashboard widget,
                                #   settings sanitization, admin assets, verify-token AJAX
  swifttrap-webhook.php        # ENTRY POINT: REST route swifttrap/v1/webhook → verifies secret → fires
                                #   swifttrap_mailtrap_webhook_event action per delivery event
  swifttrap-site-health.php    # ENTRY POINT: site_status_tests → reuses integration-layer functions
                                #   (get_account_data, fetch_domains) to report status
  swifttrap-cli.php            # ENTRY POINT: WP-CLI commands, thin wrappers over business-logic/
                                #   integration-layer functions; class-guarded behind WP_CLI
assets/admin.css                # Presentation-layer styling
languages/                      # .pot translation template
uninstall.php                   # Cleanup on uninstall
readme.txt / README.md          # wp.org listing + GitHub readme
.wordpress-org/                 # wp.org assets (icons/banners) — pushed to SVN /assets, not bundled
.distignore                     # release exclusions
tests/                          # PHPUnit + Brain Monkey unit tests (business-logic + integration-layer functions)
```

There is no `models/`, `repositories/`, or `controllers/` directory — a plugin this size keeps everything in per-responsibility files rather than per-layer directories, and the mapping above is logical, not physical. **New code should still respect the logical boundary**: don't put a new `wp_remote_*` call inside `admin.php`; add it next to the other integration-layer functions in `swifttrap-api.php` and have the entry point (AJAX/admin page/CLI command) call it.

## Dependency Rules

```
WordPress hook / REST / AJAX / CLI (entry point)
        │
        ▼
Business logic (normalize, categorize, suppression check, payload build)
        │
        ▼
Integration layer (wp_remote_get/wp_remote_post → mailtrap.io)
```

- ✅ Entry points (`pre_wp_mail` filter, AJAX handlers, REST callback, CLI commands, Site Health test) call business-logic functions, which in turn call integration-layer functions.
- ✅ Integration-layer functions (`_fetch_stats`, `_fetch_domains`, `_fetch_suppressions`, `_fetch_emails`, `_add_suppression`, `_delete_suppression`, `_get_account_data`) are the ONLY functions that call `wp_remote_get`/`wp_remote_post`/`wp_remote_request` against `mailtrap.io`.
- ✅ Business-logic functions (`_normalize_atts`, `_get_email_category`, `_should_use_bulk_stream`, `_is_recipient_suppressed`, `_build_payload`) contain no HTTP calls of their own — they take settings/normalized data in and return plain arrays or `WP_Error`.
- ❌ Entry points (`admin.php`, `swifttrap-cli.php`, `swifttrap-webhook.php`) must not call `wp_remote_*` directly — go through the matching `swifttrap-api.php` function so caching (transient, token-hashed) and error handling stay in one place.
- ❌ Integration-layer functions must not read `$_POST`/`$_GET`/request superglobals — those belong to the entry point, which sanitizes input and passes plain values down.

## Layer/Module Communication

- Entry points and business logic communicate through plain associative arrays (`$settings`, `$normalized`, `$atts`) and `WP_Error` for failure — no objects, no DTO classes.
- Cross-cutting extension points are WordPress filters/actions, not function calls: `swifttrap_mailtrap_email_category`, `swifttrap_mailtrap_use_bulk_stream`, `swifttrap_mailtrap_template`, `swifttrap_mailtrap_custom_variables`, `swifttrap_mailtrap_webhook_event`.
- The integration layer never talks back to entry points directly; it returns data/`WP_Error` and lets the caller (business logic or the entry point itself, e.g. AJAX handlers) decide how to present it.

## Key Principles

1. **Strict downward calls, no layer skipping.** An AJAX handler in `admin.php` never issues its own `wp_remote_get` — it calls a `swifttrap-api.php` fetch function.
2. **Thin entry points.** `admin.php` page functions and AJAX handlers do capability/nonce checks, call one business/integration function, and format the response (`wp_send_json_*` or HTML). Multi-step orchestration (recipient normalization, suppression filtering, category detection, payload building) lives in `swifttrap-for-mailtrap.php`/`swifttrap-api.php`, not in the entry point.
3. **No local persistence beyond settings.** The integration layer is the only place that talks to Mailtrap, and its results are cached in transients — never written back into a custom table or file. This was a deliberate simplification made in 3.0.0 (removing the old file-based email log) and should not be reversed by adding ad-hoc local storage in a new feature.
4. **Stateless functions.** No function holds cross-request state; the only in-process cache is the static `$cached` array in `swifttrap_mailtrap_get_settings()`, scoped per `blog_id` for multisite safety.

## Code Examples

### Thin entry point calling business logic calling the integration layer

```php
// ENTRY POINT (includes/admin.php) — capability/nonce check, one call, format response
function swifttrap_mailtrap_ajax_load_api_data(): void {
    check_ajax_referer( 'swifttrap_load_api_data', '_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'swifttrap-for-mailtrap' ) ) );
    }

    $settings = swifttrap_mailtrap_get_settings();
    $stats    = swifttrap_mailtrap_fetch_stats( $settings ); // → integration layer
    wp_send_json_success( array( 'stats' => is_wp_error( $stats ) ? array( 'error' => $stats->get_error_message() ) : $stats ) );
}

// INTEGRATION LAYER (includes/swifttrap-api.php) — the only place that calls wp_remote_get()
function swifttrap_mailtrap_fetch_stats( array $settings ): array|WP_Error {
    $cache_key = 'swifttrap_mailtrap_stats_' . substr( md5( $settings['token'] ?? '' ), 0, 8 );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }
    // ... wp_remote_get( 'https://mailtrap.io/api/accounts/.../billing/usage', ... ) ...
}
```

### Business logic stays HTTP-free and returns `WP_Error`, not exceptions

```php
// BUSINESS LOGIC (swifttrap-for-mailtrap.php) — no wp_remote_*, no $_POST; pure data transform
function swifttrap_mailtrap_normalize_atts( array $atts, array $settings ): array|WP_Error {
    $recipients = swifttrap_mailtrap_parse_recipients( $atts['to'] );
    if ( is_wp_error( $recipients ) ) {
        return $recipients; // ← WP_Error propagates up, never thrown
    }
    // ... category detection, header parsing, attachment normalization ...
    return array( 'to' => $recipients, /* ... */ );
}
```

## Anti-Patterns

- ❌ **God entry point.** Don't grow `admin.php` AJAX handlers to do their own category detection or payload building — that logic belongs in `swifttrap-for-mailtrap.php`/`swifttrap-api.php` so it stays testable without WordPress loaded.
- ❌ **Bypassing the integration layer's cache.** Adding a second `wp_remote_get` to `mailtrap.io` outside `swifttrap-api.php` duplicates cache-invalidation logic (the token-hash-keyed transient pattern) and will drift out of sync.
- ❌ **Resurrecting local log storage.** 3.0.0 deliberately removed file-based email logging in favor of reading Mailtrap's `/api/email_logs` live. A new feature needing "history" should extend the live-fetch integration function, not reintroduce a local write path.
- ❌ **Skipping the nonce/capability check in a new entry point.** Every existing AJAX handler and admin page does `check_ajax_referer` + `current_user_can( 'manage_options' )` (or, for the webhook, an `hash_equals` secret check) before doing anything else — a new entry point that skips this is a regression, not a shortcut.

## Architecture Pointer

See `.ai-factory/DESCRIPTION.md` for the feature/NFR specification this architecture supports. Pattern: **Layered Architecture** (logical layering over a small procedural WordPress plugin).
