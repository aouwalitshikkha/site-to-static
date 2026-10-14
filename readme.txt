=== Aouwal Static Site Export ===
Contributors: aouwal
Tags: static, export, static-site, cloudflare-pages, github
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export your WordPress site to static HTML with localized assets, ready to host on GitHub + Cloudflare Pages.

== Description ==

Aouwal Static Site Export crawls your site, captures the fully rendered HTML of every page, downloads and localizes all assets (CSS, JS, images, fonts), and packages the result into a portable ZIP.

The output is a plain static site you can commit to GitHub and deploy on Cloudflare Pages (or any static host).

By [Abdul Aouwal](https://abdulaouwal.com) — Technical SEO Consultant.

**Features**

* Exports posts, pages, custom post types, taxonomy archives, author archives and date archives.
* Downloads and localizes all assets, including recursive CSS `url()` and `@import` references.
* Rewrites internal links to portable relative (or root-absolute) URLs.
* Removes server-side features that cannot work statically (search forms, comment forms, comment feeds).
* Strips WordPress version tags and staging "noindex" robots meta so the exported site is indexable.
* Generates `404.html`, `sitemap.xml`, `robots.txt` and Cloudflare Pages `_redirects` / `_headers` files.
* Chunked, timeout-safe export with a live progress log.
* Optional WP-CLI command: `wp aouwal-static-export run`.
* Optional, invisible author credit (off by default) that can be enabled in Settings.

== Installation ==

1. Upload the `aouwal-static-site-export` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Open the **Static Export** menu, configure settings, then run an export.
4. Download the ZIP and follow the Deploy tab instructions.

== Frequently Asked Questions ==

= Does it work with page builders? =

Sites that rely heavily on client-side JavaScript (WooCommerce cart, some page builders) may not work fully once static. Standard themes work well.

= What happens to search and comments? =

They are server-side features and cannot work on a static host, so they are removed from the export.

= Does the export include any credit or branding? =

No, not unless you enable it. Under Settings → Attribution you can opt in to an invisible, source-code-only credit for Aouwal Static Site Export. It is off by default and never appears in the browser.

== Changelog ==

= 1.0.2 =
* Removed the non-HTTP-API remote fetch fallback from the crawler; all remote requests now use the WordPress HTTP API (`wp_remote_get`) exclusively.

= 1.0.1 =
* Renamed to "Aouwal Static Site Export" (aouwal_) with a distinctive slug. Previous sts_ settings and job table are migrated automatically on activation.
* Export output is written via WP_Filesystem only, inside the plugin's own uploads directory.
* Server-side fetching is restricted to the site's own hosts and public IPs (SSRF hardening).

= 1.0.0 =
* Initial release.
* Renamed from the pre-release "WP Static Export" (wse_) to "Site to Static" (sts_). Leftover pre-release options and database table are removed automatically on activation.
