# SwiftTrap for Mailtrap

Route WordPress emails through the Mailtrap Send API with stream routing, categories, and logging.

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/swifttrap-for-mailtrap)](https://wordpress.org/plugins/swifttrap-for-mailtrap/)
[![WordPress Plugin Rating](https://img.shields.io/wordpress/plugin/stars/swifttrap-for-mailtrap)](https://wordpress.org/plugins/swifttrap-for-mailtrap/)
[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

## Features

- Send transactional and bulk emails via Mailtrap
- Automatic email categorization (welcome, password-reset, notification, etc.)
- Bulk stream routing for promotional emails
- Email logging with retention management
- Dashboard widget with account stats
- Test email from settings page

**No SDK required** -- uses the WordPress HTTP API (`wp_remote_post`) for zero external dependencies.

## Installation

1. Download from [WordPress.org](https://wordpress.org/plugins/swifttrap-for-mailtrap/) or install via wp-admin
2. Activate the plugin
3. Go to **Mailtrap > Settings**
4. Enter your Mailtrap Send API token
5. Configure sender email and name

## Filters

| Filter | Purpose |
|--------|---------|
| `swifttrap_mailtrap_email_category` | Override email category |
| `swifttrap_mailtrap_use_bulk_stream` | Control bulk stream routing |
| `swifttrap_mailtrap_template` | Send via Mailtrap template |
| `swifttrap_mailtrap_custom_variables` | Attach tracking metadata |

## Development

This plugin has zero build process. Edit PHP/CSS files directly.

### Releasing a New Version

1. Update version in `swifttrap-for-mailtrap.php` (header + `SWIFTTRAP_MAILTRAP_VERSION` constant)
2. Update `Stable tag` in `readme.txt`
3. Add changelog entry in `readme.txt`
4. Commit and push to `main`
5. Create a GitHub Release with tag `X.Y.Z` (e.g., `2.3.0`)
6. GitHub Actions will automatically deploy to WordPress.org SVN

## License

GPL-2.0-or-later
