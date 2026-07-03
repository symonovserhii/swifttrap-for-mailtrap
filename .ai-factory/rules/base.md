# Project Base Rules

> Auto-detected conventions from codebase analysis. Edit as needed.

## Naming Conventions

- Files: WordPress style — `swifttrap-{name}.php` for `includes/` modules (`swifttrap-api.php`, `swifttrap-webhook.php`, `swifttrap-site-health.php`, `swifttrap-cli.php`); bootstrap file is `swifttrap-for-mailtrap.php`
- Variables & functions: `snake_case`, always prefixed `swifttrap_mailtrap_` (e.g. `swifttrap_mailtrap_get_settings`, `swifttrap_mailtrap_fetch_domains`) — no unprefixed globals
- Classes: none in production code (procedural plugin); the one exception is `SwiftTrap_CLI` (`Upper_Snake_Case`), guarded behind `WP_CLI` and only loaded when WP-CLI is active
- Constants: `SWIFTTRAP_MAILTRAP_*` (`SWIFTTRAP_MAILTRAP_VERSION`, `SWIFTTRAP_MAILTRAP_OPTION_KEY`); optional environment override `SWIFTTRAP_BLOCK_BULK`
- AJAX actions / nonces / REST namespace: `swifttrap_*` action names, matching `swifttrap_*` nonce names, REST namespace `swifttrap/v1`
- Transient cache keys: `swifttrap_*_<8-char-token-hash>` — always keyed by `substr( md5( $token ), 0, 8 )` so switching API tokens invalidates stale cache automatically

## Module Structure

- Procedural, no classes, no autoloading — every file is `require_once`'d directly from the bootstrap (`swifttrap-for-mailtrap.php`) in a fixed order: `admin.php`, `swifttrap-api.php`, `swifttrap-webhook.php`, `swifttrap-site-health.php`, `swifttrap-cli.php`
- One responsibility per `includes/` file: `swifttrap-api.php` (Mailtrap account/stats/domains/suppressions + AJAX), `admin.php` (settings/stats pages, menus, dashboard widget, sanitization, inline JS), `swifttrap-webhook.php` (REST receiver), `swifttrap-site-health.php` (Site Health test), `swifttrap-cli.php` (WP-CLI, self-guards on `WP_CLI` constant)
- No runtime Composer dependencies and no Mailtrap SDK — every external call goes through the WordPress HTTP API (`wp_remote_get`/`wp_remote_post`/`wp_remote_request`)
- New Mailtrap-facing behavior extends via WordPress filters/actions (`swifttrap_mailtrap_email_category`, `swifttrap_mailtrap_use_bulk_stream`, `swifttrap_mailtrap_template`, `swifttrap_mailtrap_custom_variables`, `swifttrap_mailtrap_webhook_event`) rather than new hard-coded branches

## Error Handling

- `array|WP_Error` / `bool|WP_Error` return-type unions throughout — no exceptions for expected failure modes (invalid recipient, missing token, API error)
- Guard clauses with early `return` for precondition failures (disabled plugin, missing token, invalid email, empty domain ID)
- Every `json_decode()` is followed by a `JSON_ERROR_NONE !== json_last_error()` check before trusting the result
- Every remote call checks `is_wp_error( $response )` first, then the HTTP status code range (`< 200 || >= 300`) before parsing the body
- `wp_mail_failed` is re-fired via `swifttrap_mailtrap_trigger_failed()` on any send failure so existing WordPress error-logging integrations keep working
- On exhausted retries the plugin returns `null` from `pre_wp_mail` (not a hard failure) so WordPress falls back to its native mail handler — delivery is never silently dropped

## Control Flow

- Prefer flat, readable control flow over deeply nested conditionals. Use guard clauses, early `return`/`continue`, small named helper functions, or explicit classification logic when they make the code easier to follow. Handle edge cases and irrelevant branches early so the main path stays visible.

## Security

- Every PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`
- Every admin page function additionally checks `if ( ! current_user_can( 'manage_options' ) ) { return; }`
- Every AJAX handler calls `check_ajax_referer( '<action>', '_nonce' )` before the capability check
- The webhook REST route has `permission_callback => '__return_true'` (it's a server-to-server callback, not user-facing) but verifies an HMAC-SHA256 signature (`hash_hmac( 'sha256', $raw_body, $secret )` compared with `hash_equals()`) against the `Mailtrap-Signature` header — never a loose `===` comparison, and never a bare shared-secret header
- All output escaped (`esc_html`, `esc_attr`, `esc_url`, `esc_js`); all input sanitized on save (`sanitize_text_field`, `sanitize_email`, `sanitize_key`) via a single `_sanitize_settings` callback
- Secrets (API token, webhook secret) are never logged; the token only appears as a truncated hash in transient cache keys

## Logging

- No custom logging subsystem — the plugin relies on WordPress's own `wp_mail_failed` action for failure visibility and on Mailtrap itself as the source of truth for delivery history (there is intentionally no local email-log storage as of 3.0.0)
- `WP_DEBUG`-style verbosity is not currently used in this plugin; keep it that way unless a feature genuinely needs local diagnostic output

## Testing

- PHPUnit 9.5 + Brain Monkey (`brain/monkey`) — pure unit tests, no WordPress install; WP functions are mocked/stubbed, not loaded
- Single flat `tests/` directory, one `*Test.php` per concern; `tests/bootstrap.php` wires Composer autoload + Brain Monkey; `tests/wp-stubs.php` provides minimal WP constants/functions the mocks don't cover
- Mock the filesystem via `Mockery::mock( 'WP_Filesystem_Base' )` and the global `$wp_filesystem` rather than touching disk
- Run: `vendor/bin/phpunit` (bootstrap: `phpunit.xml.dist`)

## PHP Style

- PHP 8.0+ scalar/union return types on nearly every function (`array|WP_Error`, `bool|WP_Error`, `int|WP_Error`, `void`)
- WordPress Coding Standards formatting (tabs, spaces inside parentheses, Yoda conditions)
- Docblocks state the contract (`@param`, `@return`, `@since`) and translator notes (`/* translators: %s: ... */`) above every `sprintf`/`__()` with placeholders — not restatements of the code
