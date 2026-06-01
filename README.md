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

No build step for the plugin itself — edit PHP/CSS directly. Dev tooling (PHPUnit) is managed via Composer.

### Tests

```bash
composer install
vendor/bin/phpunit
```

### Releasing a new version

The project is split into a GitHub working copy (`github/`) and a WordPress.org SVN working copy (`svn/`).
Releases are pushed to SVN **manually** (no CI auto-deploy).

1. Bump the version in `swifttrap-for-mailtrap.php` (header + `SWIFTTRAP_MAILTRAP_VERSION`) and `Stable tag` in `readme.txt`; add a changelog entry.
2. Commit and push to `main`; tag `vX.Y.Z`.
3. Sync to SVN trunk, tag, and commit:

```bash
../sync-to-svn.sh                 # rsync github/ → svn/trunk honoring .distignore
cd ../svn && svn cp trunk tags/X.Y.Z && svn ci -m "Release X.Y.Z"
```

## License

GPL-2.0-or-later
