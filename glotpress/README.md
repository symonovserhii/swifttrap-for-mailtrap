# GlotPress readme translations

Source `.po` files for the **Stable Readme** project on translate.wordpress.org:
https://translate.wordpress.org/projects/wp-plugins/swifttrap-for-mailtrap/stable-readme/

This is a separate translation catalog from `languages/*.po` (which is the plugin's own
bundled gettext domain, extracted from the PHP source via `wp i18n make-pot`). GlotPress
extracts its own string set from `readme.txt` independently — different msgids, different
project, different import target. Nothing here is bundled into the plugin package (see
`.distignore`); these files exist only to be manually uploaded via each locale's
**Import Translations** link on translate.wordpress.org.

## Regenerating

1. Export the current originals (0% translated is fine, we only need the msgid list):
   `https://translate.wordpress.org/projects/wp-plugins/swifttrap-for-mailtrap/stable-readme/<locale>/default/export-translations/?format=po`
2. Fill in `msgstr` for each entry, keeping HTML tags/entities in the msgid verbatim
   (GlotPress extracts rendered HTML from readme.txt — `<strong>`, `<code>`, `&amp;`, etc.).
3. `msgfmt -c stable-readme/<locale>.po -o /dev/null` to validate before uploading.
4. Upload via each locale's **Import Translations** link. Without Project Translation
   Editor (PTE) rights, imports land as "Waiting" pending approval — see the PTE request
   process at https://make.wordpress.org/polyglots/handbook/plugin-theme-authors-guide/pte-request/.

## Locale slugs

GlotPress locale slugs don't always match our `languages/*-XX_YY.po` filenames:
`pt-br` (hyphenated) not `pt_BR`, `uk` not `uk_UA`, `es`/`de`/`fr`/`ru` (bare) not the
`_XX` region-suffixed forms.
