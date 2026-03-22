=== SwiftTrap for Mailtrap ===
Contributors: simmotorlp
Tags: email, smtp, mailtrap, mail, transactional
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 2.2.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Route WordPress emails through the Mailtrap Send API with stream routing, categories, and logging.

== Description ==

SwiftTrap for Mailtrap replaces the default WordPress `wp_mail()` with the Mailtrap Send API.

**Features:**

* Send transactional and bulk emails via Mailtrap
* Automatic email categorization (welcome, password-reset, notification, etc.)
* Bulk stream routing for promotional emails
* Email logging with retention management
* Dashboard widget with account stats
* Test email from settings page

**No SDK required** — uses the WordPress HTTP API (`wp_remote_post`) for zero external dependencies.

**Extensible via filters:**

* `swifttrap_mailtrap_email_category` — override email category
* `swifttrap_mailtrap_use_bulk_stream` — control bulk stream routing
* `swifttrap_mailtrap_template` — send via Mailtrap template (template_uuid)
* `swifttrap_mailtrap_custom_variables` — attach tracking metadata to emails

== Installation ==

1. Upload the `swifttrap-for-mailtrap` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Go to Mailtrap → Settings
4. Enter your Mailtrap Send API token
5. Configure sender email and name

== Frequently Asked Questions ==

= Where do I get my API token? =
Log in to [mailtrap.io](https://mailtrap.io), go to your sending domain, and copy the API token.

= What data is sent externally? =
This plugin sends email data (recipients, subject, body, attachments) to the Mailtrap API at `send.api.mailtrap.io` and `bulk.api.mailtrap.io`. Account stats are fetched from `mailtrap.io/api/accounts`. See [Mailtrap Privacy Policy](https://mailtrap.io/privacy-policy).

= Does the plugin work without Mailtrap? =
When disabled or if the API token is empty, WordPress falls back to its default mail handler.

== Changelog ==

= 2.2.0 =
* Replaced all file_get_contents/file_put_contents with WP_Filesystem API
* Fixed $_GET sanitization with proper wp_unslash() and phpcs annotations
* Improved PHPDoc headers across all files
* Better WordPress Coding Standards compliance

= 2.1.0 =
* Added sending domain verification status on Stats page
* Added suppression list (bounces, complaints, unsubscribes) on Stats page
* Added `swifttrap_mailtrap_template` filter for Mailtrap template support
* Added `swifttrap_mailtrap_custom_variables` filter for email tracking metadata
* Extracted reusable `swifttrap_mailtrap_get_account_id()` with transient caching

= 2.0.0 =
* Removed Mailtrap SDK dependency — uses WordPress HTTP API directly
* Zero external dependencies, ~30 KB total plugin size
* Improved WP.org compliance

= 1.3.0 =
* Security: protected log directory from direct web access
* Added attachment size validation (25 MB limit)
* Added empty recipient validation
* Fixed timezone handling in log display
* Optimized email category computation
* Improved log file locking

== Upgrade Notice ==

= 2.1.0 =
New Stats page cards: domain verification and suppression list. New filters for template and custom variables support.

= 2.0.0 =
Major update: SDK removed, plugin now uses WordPress HTTP API directly. No configuration changes needed.
