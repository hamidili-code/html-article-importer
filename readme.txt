=== HTML Article Importer ===
Contributors: hamidili
Tags: html to elementor, elementor importer, convert html, zip importer, draft creator
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Requires Plugins: elementor
Stable tag: 10.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate Elementor-based draft posts from a ZIP package. Reduces article publishing time from hours to minutes.

== Description ==

**HTML Article Importer** is a powerful, lightweight, and developer-friendly plugin designed to streamline your content publishing workflow. If you generate articles or layouts in HTML format along with assets, this plugin allows you to pack them into a single ZIP file and instantly convert them into beautiful, fully-editable Elementor draft posts.

By parsing standard HTML tags and structure, the plugin automatically maps headings, paragraphs, tables, images, and custom layouts directly into native Elementor containers and widgets.

= Key Features =
* **Instant ZIP Import:** Upload a unified ZIP package containing your `article.html` and image files.
* **Smart Asset Mapping:** Automatically uploads images into the WordPress Media Library and links them seamlessly within the newly created Elementor layout.
* **Elementor Container & Widget Generation:** Maps HTML components natively to Elementor headings, text editors, tables, custom boxed layouts, and complex accordion modules.
* **Automatic Draft Creation:** Automatically sets up a WordPress post draft with the appropriate title extracted from your `<h1>` tags.
* **Isolated Temporary Cleanup:** Automatically manages and purges temporary data and extractions to keep your server directory safe and clutter-free.

== Installation ==

1. Upload the `html-article-importer` folder to the `/wp-content/plugins/` directory, or upload the ZIP file directly via the WordPress admin dashboard (`Plugins > Add New > Upload Plugin`).
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Make sure you have the **Elementor** plugin installed and active on your site.
4. Navigate to the new **HTML Article Importer** menu in your admin dashboard to begin importing.

== Frequently Asked Questions ==

= What should the ZIP package structure look like? =
Your ZIP file must contain an `article.html` file at its core. Images referenced in the document should be located inside the same archive. For example:
`
article.zip
├── article.html
├── hero.webp
└── infographic.png
`

= Which HTML components are currently supported? =
The parser recognizes standard semantic headings (`<h1>` to `<h6>`), `<p>` tags for textual body paragraphs, tables, highlighted box classes (`<div class="box">`), and dynamic accordion components (`<div class="faq">`).

= How do I add image placeholders in the HTML file? =
You can use standard `<img>` tags pointing to the image filename, or leverage the plugin's native placeholder system by writing `[[IMAGE:filename_without_extension]]` anywhere inside your HTML.

== Screenshots ==

1. The clean and minimal ZIP upload dashboard under the WordPress admin panel.
2. Structure format and supported HTML components documentation overview.

== Changelog ==

= 10.1.2 =
* Renamed plugin to "HTML Article Importer" for WordPress.org trademark compliance.
* Updated slug to `html-article-importer`, text domain, namespace, and all constants accordingly.
* Updated Plugin URI.
* Bumped version to 10.1.2.


= 10.1.1 =
* Renamed plugin slug, name, and text domain to `html-article-importer`.
* Updated Plugin URI to https://github.com/hamidili-code/html-article-importer.
* Updated PHP namespace to `HtmlArticleImporter`.
* Updated all plugin constants to `HTML_ARTICLE_IMPORTER_` prefix.
* Bumped version to 10.1.1.


= 10.1.0 =
* Renamed plugin to "HTML to Elementor Importer" for WordPress.org compliance.
* Updated text domain from `widgee` to `html-article-importer` throughout all files.
* Updated PHP namespace from `Widgee` to `HtmlArticleImporter`.
* Added `Requires Plugins: elementor` header for WordPress 6.5+ dependency declaration.
* Fixed contributor username to `hamidili`.
* Removed invalid Plugin URI; replaced with valid GitHub URL.
* Removed unnecessary `require_once` for `wp-admin/includes/media.php` in ImageUploader.
* Fixed escaping of CSS class attribute in admin notice output.
* Fixed unescaped `post_id` in admin page URL construction.
* Updated plugin constants to use `HTML_ARTICLE_IMPORTER_` prefix.
* Aligned admin menu slug with plugin slug (`html-article-importer`).
* Fixed indentation in `save_elementor_meta()` for WPCS alignment rules.
* Incremented version to 10.1.0.

= 10.0.1 =
* Initial stable release.
* Added native Elementor data compilation and structural container handling.
* Fully compliant with WordPress.org security, sanitization, and filesystem guidelines.
* Removed development logging functions for a secure production lifecycle.
