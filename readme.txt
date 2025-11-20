=== Mailtrap Mailer ===
Contributors: CrowdSpace
Tags: mailtrap, email, wp_mail, smtp, api
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Route all wp_mail() calls through the Mailtrap Send API with an easy settings page.

== Description ==

Mailtrap Mailer replaces the default mail transport with Mailtrap's Send API so all WordPress emails land in your Mailtrap inbox. Configure the API token, sender email/name, and endpoint directly in the admin panel—no environment variables or SMTP plugins required.

== Installation ==

1. Upload the plugin and activate it.
2. Go to Settings → Mailtrap Mailer.
3. Paste your Mailtrap Send API token and adjust the sender details if needed.

== Frequently Asked Questions ==

= Does it work alongside other SMTP plugins? =
When enabled and a token is present, Mailtrap Mailer intercepts wp_mail() before other transports. Disable the plugin to fall back to your previous mailer.

= Are attachments supported? =
Yes, attachments are base64-encoded and sent through the Mailtrap API.

== Changelog ==

= 1.1.0 =
* Added built-in Mailtrap SDK support with WordPress HTTP fallback, dashboard and stats pages.
* Improved admin UI styling.

= 1.0.0 =
* Initial release.
