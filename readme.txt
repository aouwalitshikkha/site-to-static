=== WP Static Export ===
Contributors: aouwal
Tags: static, export, static-site, cloudflare-pages, github
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export your WordPress site to static HTML with localized assets, ready to host on GitHub + Cloudflare Pages.

By [Abdul Aouwal](https://abdulaouwal.com) — Technical SEO Consultant.

== Description ==

WP Static Export crawls your site, captures the fully rendered HTML of every page, downloads and localizes all assets (CSS, JS, images, fonts), and packages the result into a portable ZIP.

The output is a plain static site you can commit to GitHub and deploy on Cloudflare Pages (or any static host).

**Features**

* Exports posts, pages, custom post types, taxonomy archives, author archives and date archives.
* Downloads and localizes all assets, including recursive CSS `url()` and `@import` references.
* Rewrites internal links to portable relative (or root-absolute) URLs.
* Removes server-side features that cannot work statically (search forms, comment forms, comment feeds).
* Strips WordPress version tags and staging "noindex" robots meta so the exported site is indexable.
* Generates `404.html`, `sitemap.xml`, `robots.txt` and Cloudflare Pages `_redirects` / `_headers` files.
* Chunked, timeout-safe export with a live progress log.
* Optional WP-CLI command: `wp static-export run`.

== Installation ==

1. Upload the `wp-static-export` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Open the **Static Export** menu, configure settings, then run an export.
4. Download the ZIP and follow the Deploy tab instructions.

== Frequently Asked Questions ==

= Does it work with page builders? =

Sites that rely heavily on client-side JavaScript (WooCommerce cart, some page builders) may not work fully once static. Standard themes work well.

= What happens to search and comments? =

They are server-side features and cannot work on a static host, so they are removed from the export.

== Changelog ==

= 1.0.0 =
* Initial release.
