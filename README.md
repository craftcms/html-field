# Base HTML Field

This package provides a base class for Craft CMS field types that store HTML values, such as Redactor and CKEditor.

## Upgrading from 3.x

4.x requires Craft CMS 6 and PHP 8.5.

- The namespace has changed from `craft\htmlfield` to `CraftCms\HtmlField`.
- `HtmlField` now extends `CraftCms\Cms\Field\Field`, and `HtmlFieldData` implements `Illuminate\Contracts\Support\Htmlable`.
- Field values are sanitized with Craft’s HTML sanitizers (`CraftCms\Cms\Support\Facades\HtmlSanitizers`) instead of HTML Purifier.
  - The `purifyHtml` and `purifierConfig` settings have been replaced by `sanitizeHtml` and `htmlSanitizer`. Existing field settings are mapped automatically. For example, `purifierConfig: 'Simple.json'` becomes `htmlSanitizer: 'Simple'`, which matches the name Craft gives to a legacy `config/craft/htmlpurifier/Simple.json` file.
  - `purifierConfig()` and `defaultPurifierOptions()` have been removed. Override `htmlSanitizer()` to return a custom `HtmlSanitizerInterface` instead, and use `htmlSanitizerOptions()` to list the available sanitizers in field settings.
  - `craft\htmlfield\events\ModifyPurifierConfigEvent` has been removed.
  - Craft’s default sanitizer removes `<iframe>` tags, which the 3.x default HTML Purifier config allowed for YouTube and Vimeo. `<oembed>` and `<div data-oembed-url>` tags are still allowed. It also removes custom elements and attributes that aren’t on its allowlist (such as `data-*` attributes), so subclasses that add their own markup need to allow it in `htmlSanitizer()`.
- `HtmlField` implements `TracksReferencesFieldInterface`, so Craft tracks the elements referenced by ref tags in field values.
- `configOptions()` and `config()` now look for files in `config/craft/`.
