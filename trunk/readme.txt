=== Aouwal Static Site Export ===
Contributors: aouwal
Tags: static, export, static-site, cloudflare-pages, github
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export your WordPress site to a static HTML bundle with localized assets, ready to host on GitHub and Cloudflare Pages.

== Description ==

Aouwal Static Site Export turns your WordPress site into a static HTML site. It crawls every public page, captures the fully rendered HTML, downloads and localizes every asset (CSS, JS, images and fonts), and packages the result into one portable ZIP file.

The export is a plain folder of HTML files and assets. You can commit it to GitHub and deploy it on Cloudflare Pages, or host it on any static host or CDN. No WordPress, PHP or database is needed to serve it.

Author: [Abdul Aouwal](https://abdulaouwal.com), Technical SEO Consultant.

= What you can export =

* Posts, pages and custom post types.
* Taxonomy archives, author archives and date archives.
* RSS feeds.
* A `404.html` page, plus `sitemap.xml` and `robots.txt`.
* A Cloudflare Pages `_redirects` file and a `_headers` file for caching.

= How the export works =

1. The plugin discovers every public URL on your site.
2. It fetches the fully rendered HTML of each page through the WordPress HTTP API.
3. It downloads and localizes assets, including recursive CSS `url()` and `@import` references.
4. It rewrites internal links to portable relative or root-absolute URLs.
5. It removes server-side features that cannot run on a static host.
6. It packages everything into a single ZIP file you can download.

= What the export cleans up =

* Removes comment forms, comment sections and comment feeds.
* Removes search forms.
* Strips WordPress version tags.
* Strips staging "noindex" robots meta so the exported site stays indexable.

= Key features =

* Chunked, timeout-safe export with a live progress log.
* Optional WP-CLI command: `wp aouwal-static-export run`.
* Optional, invisible author credit. It is off by default and can be enabled in Settings.

== Installation ==

1. Upload the `aouwal-static-site-export` folder to `/wp-content/plugins/`.
2. Activate the plugin in the Plugins screen.
3. Open the Static Export menu and configure the settings.
4. Run an export, then download the ZIP file.
5. Follow the Deploy tab to publish the site.

== Frequently Asked Questions ==

= What does Aouwal Static Site Export do? =

It converts your WordPress site into a static HTML site. The plugin crawls each public page, saves the rendered HTML, downloads and localizes all assets, and packages the result into a ZIP file. You can then host the site anywhere without WordPress, PHP or a database.

= Does it work with page builders? =

Standard themes work well. Sites that depend on client-side JavaScript, such as WooCommerce carts and some page builders, may not work fully once they are static. Always test the exported site before you publish it.

= What happens to search and comments? =

Search and comments are server-side features, so they cannot run on a static host. The plugin removes search forms, comment forms and comment feeds from the export. There is no built-in replacement for them.

= Does the export include any credit or branding? =

No, not unless you enable it. In Settings, under Attribution, you can turn on an invisible, source-code-only credit. It is off by default and never appears in the browser or in the exported pages your visitors see.

= Which hosts can I deploy the export to? =

You can host the exported folder on any static host or CDN. The plugin generates files for Cloudflare Pages, and the Deploy tab includes step-by-step GitHub and Cloudflare Pages instructions. Netlify, GitHub Pages and Amazon S3 also work with the same output.

= Do I need to know how to code? =

No. You run the export from the WordPress admin and download a ZIP file. The Deploy tab gives you copy-and-paste commands for GitHub and Cloudflare Pages, so you can publish the site without writing any code.

= Does the plugin change my live site? =

No. The plugin only reads your content and writes the export to a files folder inside your own WordPress uploads directory. Your live site, database and settings stay unchanged, and you can delete the export files at any time.

= Where are export files stored, and are they public? =

Exports are written to `wp-content/uploads/aouwal-static-site-export/`, the same area WordPress uses for its own export files. The folder is protected from browsing with an `index.php` file and, on Apache, a `.htaccess` deny rule. The ZIP is served only through a signed, expiring admin link, never a permanent public URL, and the file disappears when the job is pruned. Nginx ignores `.htaccess`, so on Nginx either add a deny rule for `wp-content/uploads/aouwal-static-site-export/` or move exports outside the web root. Developers can relocate the directory with the `aouwal_export_dir` filter.

= Which WordPress and PHP versions are supported? =

The plugin requires WordPress 6.0 or later and PHP 7.4 or later. It is tested up to WordPress 7.1. It uses only the WordPress HTTP API and the WordPress filesystem, so it works on standard shared hosting.

= Can I update the exported site later? =

Yes. Run a new export to create a fresh ZIP, then push the updated files. On Cloudflare Pages, every push to your connected repository redeploys the site automatically, so updates take only a few minutes.

= Does the plugin work on large sites? =

Yes. The export runs in batches, which keeps each request short and avoids server timeouts. If your server is slow, lower the batch size in Settings. Very large sites take more time and produce a larger ZIP file.

= Is the exported site good for SEO? =

Yes. The export keeps your page URLs, titles and content, generates a `sitemap.xml` and `robots.txt`, and strips version tags and staging "noindex" meta. Because the pages are static HTML, they load quickly and are easy for search engines and AI systems to crawl.

== Screenshots ==

1. Configure what to export: post types, archives, asset handling, URL style, cleanup options and generated files.
2. Run the export and follow the live progress log, then download the finished ZIP.
3. Deploy with the built-in GitHub and Cloudflare Pages steps, including the generated _redirects and _headers notes.

== Changelog ==

= 1.1.0 =
* Security: export ZIP downloads now use a signed, expiring admin link instead of a permanent public URL. A shared or leaked link stops working when the nonce expires and when the job is pruned.
* Security: the export folder is protected from direct browsing with an index.php guard and, on Apache, a .htaccess deny rule.
* New aouwal_export_dir filter to store export files outside the web root (recommended on Nginx).
* The Deploy tab shows a ready-to-paste Nginx deny rule when the server runs Nginx.
* WP-CLI prints the local ZIP path instead of a public URL.
* Fixed the Deploy tab and README instructions for exports that have no wrapper folder.

= 1.0.3 =
* Fixed link hint tags (preconnect, dns-prefetch, prerender, profile) being downloaded and rewritten to broken local files.
* Fixed multi-token rel values (for example "preconnect dns-prefetch") being misclassified.
* Localize web app manifest icon and shortcut URLs so they work on the static site.

= 1.0.2 =
* Removed the non-HTTP-API remote fetch fallback from the crawler; all remote requests now use the WordPress HTTP API (`wp_remote_get`) exclusively.

= 1.0.1 =
* Renamed to "Aouwal Static Site Export" (aouwal_) with a distinctive slug. Previous sts_ settings and job table are migrated automatically on activation.
* Export output is written via WP_Filesystem only, inside the plugin's own uploads directory.
* Server-side fetching is restricted to the site's own hosts and public IPs (SSRF hardening).

= 1.0.0 =
* Initial release.
* Renamed from the pre-release "WP Static Export" (wse_) to "Site to Static" (sts_). Leftover pre-release options and database table are removed automatically on activation.

== Upgrade Notice ==

= 1.1.0 =
Security release. Export ZIPs now download through signed, expiring links instead of a permanent public URL, and the export folder is protected from direct access. Recommended for every site.

= 1.0.3 =
Fixes link hint handling and localizes web app manifest icons in exports. Update recommended.

= 1.0.2 =
All remote requests now use the WordPress HTTP API. Update to stay secure and compliant with the WordPress.org plugin guidelines.
