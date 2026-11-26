=== Aouwal Static Site Export – Static Site Generator ===
Contributors: aouwal
Tags: static site generator, static html, wordpress export, cloudflare pages, jamstack
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.13.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn WordPress into a fast static HTML site. Export pages, CSS, JS, images, fonts and sitemap.xml for Cloudflare Pages, GitHub Pages or Netlify.

== Description ==

Aouwal Static Site Export is a WordPress Static Site Generator that converts dynamic WordPress websites into static HTML websites. Export complete websites including CSS, JavaScript, images, fonts, sitemap.xml and robots.txt for deployment on Cloudflare Pages, GitHub Pages, Netlify or any static web server.

The export is a plain folder of HTML files and assets. You can commit it to GitHub and deploy it on Cloudflare Pages, or host it on any static host or CDN. No WordPress, PHP or database is needed to serve it.

Author: [Abdul Aouwal](https://abdulaouwal.com), Technical SEO Consultant.

= Export WordPress to static HTML =

The plugin turns your published WordPress pages into plain static HTML files. It works in six steps:

1. It discovers every public URL on your site.
2. It requests each URL through the WordPress HTTP API and keeps the fully rendered HTML.
3. It downloads and localizes every asset, including recursive CSS `url()` and `@import` references.
4. It rewrites internal links to portable relative or root-absolute URLs, keeping `<picture>`, `<source>`, `<track>`, `<template>` and custom-element markup intact.
5. It removes server-side features that cannot run on a static host.
6. It packages everything into one ZIP file you can download.

You can export:

* Posts, pages and custom post types.
* Category, tag and custom taxonomy archives, author archives and date archives, including page 2 and later.
* RSS feeds (`feed/index.xml`), removable with one checkbox.
* A `404.html` page (theme template or a built-in page), plus `sitemap.xml` and `robots.txt`.
* Cloudflare Pages `_redirects` and `_headers` files, both on by default.

= WordPress static site generator features =

* Chunked export with a live progress log, so large sites do not hit PHP timeouts.
* Every page is fetched as WordPress renders it, so theme templates, shortcodes and plugin output are included.
* Images, CSS, JavaScript and fonts are downloaded and localized, including `srcset` and `<picture>` sources.
* Internal links become relative or root-absolute URLs, so the static site works on any domain and in any subfolder.
* HTML5 markup stays valid: `<picture>`, `<source>`, `<video>`, `<track>`, `<template>` and custom elements survive the export.
* The Maximum asset size setting (25 MB by default) skips oversized files instead of exhausting memory.
* Optional HTML minification and pre-compressed `.gz` copies for HTML, CSS, JS, JSON, SVG and XML. Both are off by default; protected blocks stay byte-identical.
* Every write is checked. Pages or assets that cannot be written are reported in the log instead of being marked as done.
* The crawler fetches only public URLs. Every redirect hop and both A and AAAA records are validated.
* Network controls for awkward hosting: request timeout, delay between requests, custom crawl origin, TLS verification modes and optional HTTP Basic Auth.
* Optional path rewrite: move /wp-content/uploads/ to a shorter folder such as /uploads/ or /media/ in the export. Off by default; changes URLs only and does not remove other WordPress fingerprints.
* Pause, resume and cancel controls, so a long export can be stopped without losing progress and a wrong export can be aborted and cleaned up.
* Optional changed-only export: reuse the last export and re-export only pages, archives and assets that changed. Full export stays the default.
* Optional direct deployment to GitHub: push the finished export to a branch with one commit per run. Off by default, token-only, no OAuth app.
* Optional direct deployment to Cloudflare Pages: upload only changed files to a Pages project with an API token. Off by default.
* Optional direct deployment to Netlify: file-digest deploy, only missing hashes uploaded, atomic replacement of the site. Off by default.
* Optional WP-CLI command: `wp aouwal-static-export run`.
* Optional, invisible author credit. It is off by default and can be enabled in Settings.

= Deploy to Cloudflare Pages =

Cloudflare Pages is the fastest path to a live static site. The export already includes the `_redirects` and `_headers` files Cloudflare Pages expects, and the Deploy tab walks you through creating a GitHub repository and connecting it to Cloudflare Pages. Once the repository is connected, every push redeploys the site automatically.

= Export to GitHub Pages =

The same export folder works on GitHub Pages. Commit the exported files to a repository, enable Pages for the branch or folder you pushed, and GitHub serves the static HTML. Relative links work as they are, so no extra configuration is needed.

= Netlify, Jamstack and any static host =

The output is a plain folder of HTML files and assets, which makes it a good fit for any Jamstack workflow. Upload it to Netlify (which also reads `_redirects` and `_headers`), Amazon S3, Vercel, a CDN, or any static hosting account. No WordPress, PHP or database is needed to serve it.

= Automate exports with WP-CLI and cron =

Aouwal Static Site Export writes a ZIP and a local folder. It does not schedule exports, so use cron or your host's scheduler for hands-off runs. The WP-CLI command can also place the result exactly where a deploy script expects it.

1. Run the export with machine-readable output and a stable ZIP path:

    wp aouwal-static-site-export run --batch=10 --format=json --zip=/var/www/builds/site.zip > /tmp/export.json

The JSON object contains `job_id`, `mode`, `counts` (`done`, `error`, `skipped`, `total`), `output_dir`, `zip`, `bytes`, `duration`, `started`, `finished` and any deployment results. Only JSON goes to stdout; progress and log lines go to stderr.

2. Unzip that stable path into the repository your host watches:

    unzip -o /var/www/builds/site.zip -d /var/www/static-repo

Or skip the unzip step with `--output-dir=/var/www/static-repo --force`, which copies the finished site tree into the target folder.

3. Commit and push the updated files:

    cd /var/www/static-repo && git add -A && git commit -m "Static export" && git push

4. Cloudflare Pages, Netlify and Vercel redeploy on every push, so the new static site is live a few minutes after the cron job runs.

Exit codes are stable:

* `0` — export completed without failed items.
* `1` — completed with failed items, or cancelled.
* `2` — could not start: another export is running, an invalid flag or path, or the PHP zip extension is missing.

Two cron runs cannot overlap: the exporter takes a lock and the second process exits 2 with the running job ID. A lock is released when the export finishes, pauses or is cancelled, and a stale lock is ignored after 20 minutes without progress.

`--format=json` and `--porcelain` are aliases. `--output-dir` and `--zip` accept absolute paths and are checked before the crawl starts. The ZIP keeps the site structure (`path/index.html`), so unzip it as a folder, not as a flat file list.

= Pause, resume or cancel an export =

The Export screen has three controls next to the Start button, so a long crawl no longer has to be left running or killed by closing the tab.

* **Pause** stops the export at the end of the current batch. Every page and asset already exported stays on disk, and the queue keeps its place.
* **Resume** continues from the next pending URL and finishes the export with the pages written before the pause still in place.
* **Cancel** stops the export and deletes the partial output folder and any ZIP, so a failed or wrong export leaves nothing behind. The log keeps a line naming the last URL that was processed.

Pausing and cancelling take effect between batches, which is one request. A batch that is already running finishes first, so a cancel can take a few seconds to report back on a slow host. Nothing is lost in the meantime: the next request repeats the cleanup.

The state is stored on the server, so reloading the screen shows a paused export with its Resume and Cancel buttons. The loop never restarts on its own, because two open tabs would both drive the same queue.

WP-CLI has the same controls for a crawl started from the command line:

    wp aouwal-static-export status
    wp aouwal-static-export cancel
    wp aouwal-static-export resume --batch=10

`status` prints the phase, the processed and pending counts and the last URL, which is handy in a cron log. `cancel` exits with 1 so a script stops before pushing a partial build.

= Changed-only export for large sites =

By default every export re-crawls every page and re-downloads every asset. Settings > Export mode adds a second mode for sites where that takes too long.

* **Full export** is the default and the previous behavior: every URL is fetched and rewritten on every run.
* **Changed-only export** starts from the last export, copies its files into the new output folder, and then re-exports only what changed.

What a changed-only run re-exports:

* A post or page whose `post_modified` time changed, plus every taxonomy, author and date archive that contains it, plus the homepage, the posts page and their pagination.
* Assets whose `ETag` or `Last-Modified` response header no longer matches the stored value. The plugin sends a conditional request (`If-None-Match` / `If-Modified-Since`) and keeps the copied file when the server answers 304 Not Modified.
* Feeds, additional URLs, the sitemap, robots.txt, `_redirects`, `_headers` and 404.html are always rebuilt.

Unchanged pages are not even requested: their stored fingerprint matches, so the copied file is kept as is.

The mode falls back to a full export on its own when the previous files cannot be trusted: a different plugin or WordPress version, a different theme, a different permalink structure or site URL, or any setting that changes the output (post types, archive toggles, URL style, uploads path, stripping options, credit). It also falls back when the previous output folder is missing, or when copying it fails.

Files for content that was deleted are removed from the new export, and the log names how many were dropped. A URL that failed keeps its old file and is retried on the next run.

Run it once from the command line without changing the saved setting:

    wp aouwal-static-export run --changed-only --batch=10
    wp aouwal-static-export run --full

Data stored: one row per page and asset in a custom table (`{prefix}aouwal_export_state`) holding the URL hash, fingerprint, ETag, Last-Modified, size and the last export that confirmed it. The table is removed when the plugin is uninstalled.

= Deploy from WordPress to GitHub and Cloudflare Pages =

The Deploy tab pushes a finished export to GitHub, Cloudflare Pages or Netlify without downloading, unzipping and pushing by hand. Each target is off by default and hidden until enabled, and each has its own Test connection, Deploy automatically option and Deploy button. "Deploy all enabled" runs every enabled target. Tokens are stored encrypted, never shown again, and a failed deployment keeps the local ZIP so it can be retried.

= GitHub =

GitHub needs the repository owner, repository name, branch, optional folder and a personal access token with contents write access.

Only changed files are uploaded, one commit is created per run, and a missing branch is created from the default branch. Removing files that are no longer in the export is opt-in. For a first deployment of thousands of files, run `wp aouwal-static-site-export deploy` from the command line to avoid a web request timeout.

    wp aouwal-static-export deploy

= Cloudflare Pages =

The Cloudflare target uploads the export to a Pages project with the direct upload API. Use it when the Pages project is not connected to a Git repository. If the project already builds from a GitHub repository, use the GitHub target and let Pages rebuild from the new commit.

Cloudflare Pages needs the 32-character account ID, the project name (not the `.pages.dev` hostname) and an API token with Pages Edit access.

Only missing file hashes are uploaded, and `_headers` and `_redirects` are included. Leave the branch blank for production, or enter a branch for a preview deployment.

Limits: Cloudflare Pages accepts up to 20,000 files per deployment and 25 MB per file. The plugin stops with a clear message before uploading when the export exceeds either limit.

Optional cache purge: set the 32-character zone ID and tick "Purge the zone cache after every Cloudflare deployment". The API token then also needs Zone > Cache Purge permission. A purge runs only after a successful deployment, so a removed file stops being served from the edge cache.

Pages keeps every deployment as a preview, so removing content from the export does not delete the previous deployment; the new deployment simply no longer serves it.

Test connection reads the project before anything is uploaded.

= Netlify =

The Netlify target uses the file digest API. It needs the Project ID (also called Site ID) and a personal access token from Applications > Personal access tokens.

Every file is a SHA1 entry in the manifest. Netlify returns the hashes it lacks, only those are uploaded, and the deploy replaces the previous file set atomically. The token is encrypted and never logged. Test connection reads the site first; a failed deploy keeps the local ZIP.

= Why use a static WordPress website =

A static website is faster and safer: no PHP and no database, just small HTML files served from a CDN. There is much less to attack: no login page, no plugin endpoints and no database on the public server. Keep WordPress for writing, and export a fresh copy when content changes.

= What the export cleans up =

* Removes comment forms, comment sections, "Leave a comment" links and comment feeds (on by default). Leftover theme wrappers such as `comments-area`, `comment-respond` and `comment-list` are removed too, across classic and block themes.
* Removes search forms (on by default).
* Strips WordPress version tags.
* Strips staging "noindex" robots meta so the exported site stays indexable.

= Not suitable for =

A static site has no PHP and no database, so features that need a server stop working after the export:

* E-commerce stores. Cart, checkout, payment and order confirmation need WooCommerce and a database.
* Membership sites and sites that require user logins. A static host has no sessions, accounts or password reset.
* Real-time data, such as live stock levels, prices, dashboards or feeds that change by the minute.
* Heavy AJAX functionality, including live search, AJAX filtering, infinite scroll endpoints and form submissions.
* Built-in forms, site search, comments and "add to cart" buttons. They need a server to receive and store the result.

If you need those features, keep the dynamic WordPress site, or replace them with third-party services before you go static: a hosted form service, a static search service, and a hosted checkout. Static export works best for content sites such as blogs, documentation, portfolios, marketing pages and company websites.

== Installation ==

1. Upload the `aouwal-static-site-export` folder to `/wp-content/plugins/`.
2. Activate the plugin in the Plugins screen.
3. Open the Static Export menu and configure the settings.
4. Run an export, then download the ZIP file.
5. Follow the Deploy tab to publish the site.

== Frequently Asked Questions ==

= How do I export WordPress to HTML? =

Install and activate the plugin, open Static Export in the admin menu, choose which post types and archives to include, then run the export. The plugin renders every page, localizes the assets and builds a ZIP file. Download the ZIP, unzip it, and upload the folder to your static host. The Deploy tab has the full walkthrough for GitHub and Cloudflare Pages.

= What does Aouwal Static Site Export do? =

It is a static site generator for WordPress. It converts your WordPress site into a static HTML site. The plugin crawls each public page, saves the rendered HTML, downloads and localizes all assets, and packages the result into a ZIP file. You can then host the site anywhere without WordPress, PHP or a database.

= Does it support custom post types? =

Yes. Every public post type is listed in Settings and you choose which ones to export. Products, portfolio items, documentation pages and other custom post types are exported with the same permalink structure they use on your WordPress site.

= Does it work with Elementor? =

Usually yes. Elementor renders its layout to HTML and CSS on the server, so pages export with their design intact. Widgets that need a server, such as forms, popups with dynamic content and logged-in-only sections, cannot work on a static host and should be tested before you publish.

= Does it work with WooCommerce? =

Product and category pages can be exported, so a product catalog works as a static site. The cart, checkout, my-account and product search pages are dynamic, so they cannot work without WordPress. The plugin does not convert WooCommerce into a static shop.

= Does it export images? =

Yes. Images are downloaded and stored in the uploads structure the HTML expects, including `srcset` and `<picture>` sources. Images served from a CDN or another domain are localized too when they are reachable. Any single asset larger than the Maximum asset size setting (25 MB by default) is skipped and listed in the log.

= Does it export CSS and JavaScript? =

Yes. Stylesheets and scripts are downloaded and rewritten to local paths, including CSS `url()` and `@import` references, so icons, fonts and background images keep working. Inline styles and inline scripts stay in the page. Files are not minified or combined, so the export matches what your site already serves.

= What happens to search and comments? =

Search and comments are server-side features, so they cannot run on a static host. The plugin removes search forms, comment forms, comment sections and comment feeds from the export by default. Both cleanup steps can be turned off in Settings, for example when you only need a partial static copy. There is no built-in replacement for them.

= Does the export include any credit or branding? =

No, not unless you enable it. In Settings, under Attribution, you can turn on an invisible, source-code-only credit. It is off by default and never appears in the browser or in the exported pages your visitors see.

= Can I deploy to Cloudflare Pages? =

Yes. The export includes `_redirects` and `_headers` files, which Cloudflare Pages reads automatically, and the Deploy tab gives you the GitHub and Cloudflare Pages steps. Connect the repository once and every new export redeploys the site.

= Can I schedule exports or publish to Netlify automatically? =

Not from the plugin settings. Scheduled exports, deploy targets and cache purging are not built in yet. The supported pattern is a cron job that runs `wp aouwal-static-export run --batch=10`, unzips the ZIP into a Git repository, then commits and pushes. Cloudflare Pages, Netlify and Vercel deploy the new commit automatically. The "Automate exports with WP-CLI and cron" section has the exact commands.

= Can I host the exported site anywhere? =

Yes. The output is plain HTML, CSS, JavaScript and media, so it runs on any static hosting or CDN: Netlify, GitHub Pages, Cloudflare Pages, Amazon S3, Vercel, or a basic web host. No WordPress, PHP or database is required.

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

= Can I export only the pages that changed? =

Yes. Set Settings > Export mode to "Changed-only export", or run `wp aouwal-static-export run --changed-only`. The export starts from the last one, keeps every file whose page or asset did not change, and re-exports edited posts, the archives that contain them, the homepage, the posts page and any asset whose ETag or Last-Modified changed.

A changed-only run is much faster on a large site because unchanged pages are not requested at all and unchanged assets answer 304 Not Modified. The mode falls back to a full export automatically after a plugin, WordPress or theme update, a permalink or site URL change, or any setting that affects the output. Full export stays the default.

= Does a changed-only export upload the whole site again? =

Yes, the ZIP still contains the complete site, because the export is a static snapshot rather than a patch. The saving is in the crawl: only changed URLs are fetched and rewritten, and unchanged files are copied from the previous export. If you deploy with rsync or Git, your sync tool still transfers only the files that differ.

= Can I deploy to GitHub from WordPress? =

Yes. Open the Deploy tab, enable GitHub, and save the repository owner, repository name, branch and a personal access token. Then press "Deploy last export", or turn on automatic deployment so every successful export is pushed.

The token needs contents read and write access for that repository; a fine-grained token limited to the single repository is enough. It is stored encrypted, never shown again and never written to the ZIP or the log.

Only changed files are uploaded, using a git blob hash comparison, and one commit is created per deployment. Cloudflare Pages, Netlify and Vercel still deploy the new commit automatically when the repository is connected to them.

= What happens if a GitHub deployment fails? =

The Deploy tab shows the GitHub error text, such as `Bad credentials` or a permission message. The local ZIP and the export folder are kept, so you can fix the token or branch and press "Deploy last export" again without re-running the crawl.

A failed deployment never changes the export result: the pages that were exported stay exported, and a partial commit is not left behind because the branch ref is updated only after the commit object exists.

= Can I deploy to Cloudflare Pages from WordPress? =

Yes. Open the Deploy tab, enable Cloudflare Pages, and save the account ID, the project name and an API token with the Cloudflare Pages permission set to Edit. Then press "Deploy last export", or enable automatic deployment.

The target uploads the export with the Pages direct upload API. It hashes every file, uploads only the hashes the project does not have yet, and creates one deployment with the full manifest. `_headers` and `_redirects` are included as Pages configuration.

The project must be a direct upload project. If it is connected to a Git repository, use the GitHub target instead: pushing a commit makes Pages rebuild the site automatically.

= Why does the Cloudflare target reject my project? =

The direct upload API only works with a Pages project that is not connected to Git. A Git-connected project builds from the repository, so uploading files to it is not possible. Either create a second project with Direct Upload, or enable the GitHub target and let the connected project rebuild.

The other limits are 20,000 files per deployment and 25 MB per file. The plugin checks both before uploading and reports which file is too large.

= Can I deploy to Netlify from WordPress? =

Yes. Open the Deploy tab, enable Netlify, and save the Project ID plus a personal access token from Applications > Personal access tokens. Then press "Deploy last export" or enable automatic deployment.

The plugin sends a SHA1 manifest, Netlify replies with the files it does not have, and only those are uploaded. Netlify then publishes the deploy atomically, so the new file set replaces the old one and removed content disappears.

If the site answers 401 or shows a login page, the Netlify project is protected by team SSO or a site password. Set the project to public in Netlify, or disable SSO for that site, before deploying.

= Does the plugin minify HTML or pre-compress files? =

Yes, both are opt-in in Settings > Performance.

HTML minification removes whitespace between tags and normal comments. Text spacing, conditional comments, the credit comment and the contents of `pre`, `textarea`, `script` and `style` stay byte-identical, so the page renders the same.

Pre-compressed files adds `file.ext.gz` next to HTML, CSS, JS, JSON, SVG and XML. It is meant for nginx `gzip_static`; Cloudflare Pages and Netlify compress on the fly and may ignore the sidecars, and GitHub Pages does too. The ZIP includes them. If the server has no PHP zlib extension the option is hidden.

= Does the plugin work on large sites? =

Yes. The crawl runs in batches, and each batch is one request, so a PHP timeout applies to a batch, not to the whole site or to a single page.

Batch size is set in Settings and accepts 1 to 50. The default is 5, so a 2,000-page site already exports in 5-page requests. Do not set 50 or 100: 50 is the maximum and 100 is not accepted. Lower it to 2 to 5 on shared hosting, raise it to 10 to 20 on a fast server. WP-CLI uses 10 URLs per batch by default:

    wp aouwal-static-export run --batch=10

Memory: 256 MB is the practical minimum and 512 MB is comfortable for large media libraries. Any single asset larger than the "Maximum asset size" setting (25 MB by default) is skipped and listed as an error in the log, so one huge video or archive cannot run the export out of memory.

Discovery and ZIP packaging each run in a single request on current versions. Sites with tens of thousands of posts can still hit a limit there. If that happens, exclude heavy post types, raise max_execution_time temporarily, or run the export with WP-CLI.

= Can I export a staging site protected by HTTP Basic Auth? =

Yes. Enter the username and password in Settings > Network > HTTP Basic Auth, then run the export as usual. The plugin sends the `Authorization` header only to your site's own hosts, so credentials never reach Google Fonts, a CDN or a Gravatar, and the header is dropped when the origin redirects to another domain.

The password is stored encrypted and is never rendered back into the settings page or written to the export log. Leave the password field blank to keep the saved value; tick "Remove stored credentials" to delete it. Basic Auth only: cookie-based protection and WordPress "site password" plugins are not supported, and without credentials a protected site still logs `HTTP 401` for every URL.

= Why does the export time out or get rate-limited? =

Two settings in Settings > Network control it. Raise "Request timeout" (60 seconds by default, up to 120) when a single slow page or a large file is abandoned. Add a "Delay between requests" of 200 to 1000 milliseconds when your host or a security plugin rate-limits the crawl.

If PHP itself runs out of time, lower "Batch size (concurrency)" instead, because each batch is one PHP request. The same values are available for a single run with WP-CLI: `wp aouwal-static-site-export run --timeout=90 --delay=250 --batch=5`.

= Can I crawl through a Docker container or a reverse proxy? =

Yes. Set "Custom crawl origin" to the URL the server can actually reach, for example `http://wordpress:8080` for a container name or `http://127.0.0.1:8080` for a local proxy. The crawler requests pages and assets from that origin, while every link, canonical URL and sitemap entry still uses the normal site URL, so the exported site is identical to a direct crawl.

The origin host is trusted like the site host, so it does not have to be publicly routable. Link-local and cloud metadata addresses such as 169.254.169.254 are always refused, and the origin URL cannot contain a username or password. On a proxy that uses a private certificate authority, choose "Verify against a custom CA bundle" and enter the absolute path to the PEM file on the server.

= Is the exported site good for SEO? =

Yes. The export keeps your page URLs, titles and content, generates a `sitemap.xml` and `robots.txt`, and strips version tags and staging "noindex" meta. Because the pages are static HTML, they load quickly and are easy for search engines and AI systems to crawl.

= Can I move or remove /wp-content/uploads/ from the exported URLs? =

Yes, optionally. In Settings > Paths, turn on "Rewrite uploads path" and choose a target folder such as "uploads" or "media". When enabled, every reference and file uses the new folder: /wp-content/uploads/2026/01/photo.jpg becomes /uploads/2026/01/photo.jpg, including HTML attributes, CSS url() and @import references, srcset variants and manifest icons.

The setting is off by default, so the export keeps the live /wp-content/uploads/ structure byte for byte when it is disabled. It changes URLs only and does not remove other WordPress fingerprints such as generator meta tags or already-localized script paths.

Trade-off: old /wp-content/uploads/ links stop working unless you add redirects. The _headers cache rule follows the configured folder. A path that collides with an existing page is reported as an error and is not overwritten.

= Can I pause or cancel an export that is already running? =

Yes. The Export screen shows Pause and Cancel buttons while an export runs. Pause stops at the end of the current batch and keeps every page and asset already written, so Resume can continue from the next pending URL. Cancel stops the export and deletes the partial output folder and any ZIP, so a wrong or failed export leaves nothing on disk.

Both actions are recorded in the log. A cancel adds a line naming the last URL that was processed and how many items had been exported, and the same line is still there after a page reload.

= Can I resume an export after closing the browser? =

Yes, if you paused it first. The state lives in the database, so reopening the Export screen shows the paused export with its Resume and Cancel buttons and the counts it reached. The crawl does not restart on its own, because two open tabs would otherwise drive the same queue at the same time.

An export that was never paused and whose tab was simply closed stays in the "crawling" phase. The screen detects that no batch has touched the output folder for five minutes, marks it as interrupted, and offers Resume or Cancel. From the command line, `wp aouwal-static-export status` shows the same state.

= What happens to the files when an export is cancelled? =

They are deleted. Cancel removes the job's output folder and its ZIP, plus the remaining queue rows, so a cancelled export cannot be downloaded or resumed by mistake. Other completed exports are untouched and stay downloadable.

The last processed URL is kept in the job log, which is the quick way to see where the crawl stopped. If you cancel because the settings were wrong, fix the settings and start a new export; it begins from a clean folder.

= What happens when a page fails to export? =

The export retries transient failures automatically. A timeout, an HTTP 429 or a 5xx response is tried again up to two times by default, with a short backoff and respect for the Retry-After header. Permanent errors such as 401, 403 or 404 are not retried, because a second attempt would fail the same way.

After the run, the Crawl health section on the Export screen lists every failed URL with its HTTP status or error text, the number of attempts, and the page that linked to a failed asset. The final log line lists the failure reasons too.

= Can I retry only the pages that failed? =

Yes. When the last export finished with failures, the Export screen shows a "Retry failed" button. It requeues only the errored items, keeps every file that was already written, and rebuilds the ZIP in place, so a single timeout does not force a full re-crawl.

From the command line, run `wp aouwal-static-export retry`. Add `--batch=5` to lower the concurrency. The command exits with 1 if failures remain, so a cron job can stop before deploying a partial build.

== Screenshots ==

1. Configure what to export: post types, archives, asset handling, URL style, cleanup options and generated files.
2. Run the export and follow the live progress log, then download the finished ZIP.
3. Deploy with the built-in GitHub and Cloudflare Pages steps, including the generated _redirects and _headers notes.

== Changelog ==

= 1.13.0 =
* New: optional HTML minification. Removes whitespace between tags and normal comments; text spacing, conditional comments, the credit comment and `pre`/`textarea`/`script`/`style` bodies stay byte-identical.
* New: optional pre-compressed `.gz` sidecars for HTML, CSS, JS, JSON, SVG and XML, for nginx `gzip_static`. Off by default; hidden when PHP has no zlib extension.
* Both settings are part of the changed-only signature, so toggling either forces a full export and never leaves a mixed tree.
* The export log reports original and minified HTML sizes and the number/size of `.gz` files; the WP-CLI JSON summary gains a `stats` object.
* Removing a page or asset also removes its `.gz` sidecar.
* Readme/FAQ, settings table and `.pot` updated.

= 1.12.0 =
* New: `wp aouwal-static-export run --format=json` prints one JSON object on stdout and sends log lines to stderr. It includes job ID, counts, output directory, ZIP path, bytes, duration, timestamps and deployment results. `--porcelain` is an alias.
* New: `--output-dir=<path>` and `--zip=<path>` copy the finished export to a stable path for cron and CI. Both are validated before the crawl; a non-empty folder or existing ZIP needs `--force`.
* New: an export lock stops two cron or web runs from overlapping. The second process exits 2 with the running job ID and start time, and the lock is released on finish, pause or cancel. A stale lock is ignored after 20 minutes without a batch.
* Documented exit codes: 0 success, 1 completed with failed items or cancelled, 2 could not start.
* Readme: cron and CI examples for JSON output, stable ZIP/output paths and the exit-code contract.

= 1.11.0 =
* New: direct deployment to Cloudflare Pages. Add the account ID, Pages project name and an API token with Pages Edit access, then push the finished export from the Deploy tab. Off by default and hidden until enabled.
* Files are hashed and checked against the project: only missing assets are uploaded, and one deployment is created with the full manifest. Unchanged files are reused on later runs.
* `_headers` and `_redirects` are sent as Pages configuration, so caching and redirect rules work after a direct upload.
* Optional branch field for a preview deployment; blank uses the production branch.
* Test connection reads the project before uploading. Failures report the Cloudflare error text and keep the local ZIP and export folder.
* Per-target automatic deployment: GitHub and Cloudflare Pages can each deploy after a successful export, independently.
* "Test connection" now tests every enabled target and lists each result. "Deploy last export" deploys to every enabled target.
* New: direct deployment to Netlify. Add the Project ID and a personal access token, then push the export. Only missing SHA1 hashes are uploaded, Netlify publishes the deploy atomically, and removed content disappears.
* Per-target deploy buttons: deploy to GitHub, Cloudflare Pages or Netlify on its own, or use "Deploy all enabled". Results are merged per target instead of replacing the whole last-run table.
* Optional Cloudflare cache purge after a successful deployment: set the zone ID and tick the option; the token then also needs Zone > Cache Purge permission.
* Refactor: a single target registry drives labels, configuration checks, test and deploy methods for all targets.
* Enforced Cloudflare limits (20,000 files, 25 MB per file) with a clear message before the upload starts.
* Fix: when an archive type is disabled, links to it are removed instead of staying broken. Date archive links (`/2026/` and `/2026/11/`) disappear when date archives are off, and `feed/index.xml`, feed directories and every feed link disappear when RSS feeds are off.
* Fix: a fallback 404 page is now self-contained and styled, with a clear message, a home link and a `noindex` robots tag. Themed 404 pages also get `noindex` when they do not already have it.
* New 404 page setting: use the theme template or a built-in page that looks the same on every theme and has no search box or archive widgets. Both modes add a base URL so relative CSS and menu links keep working when the 404 is served at `/2026/11/` or any other depth.
* Cloudflare token encrypted at rest, never rendered, never logged, and removed on uninstall with the rest of the deployment option.
* Fix: when comment forms are removed, "Leave a comment" links and leftover comment wrappers (`comments-area`, `comment-respond`, `comment-list`, `comments-wrapper` and similar) are removed too. Tested with Twenty Twenty-Five, Twenty Twenty-Four, Twenty Twenty-Three, Astra, Kadence, OceanWP and GeneratePress.

= 1.10.0 =
* New: direct GitHub deployment from the Deploy tab. Add a repository owner, repository name, branch, optional folder and a personal access token, then push the finished export with one commit. Off by default and hidden until enabled.
* Only changed files are uploaded: each exported file is hashed as a git blob and compared with the branch tree, so unchanged files are skipped.
* A missing branch is created from the default branch on the first deployment. An optional setting removes repository files that are no longer in the export; it is off by default and limited to the selected folder.
* The token is encrypted at rest with sodium or OpenSSL, never rendered back, never written to the export or the log, and cleared when the option is removed on uninstall.
* "Test connection" verifies the token, repository and branch before a push. Failures report the GitHub error text and leave the local ZIP and export folder untouched.
* New "Deploy automatically after every successful export" option, a "Deploy last export" button, and `wp aouwal-static-export deploy`.
* Deployment results are stored on the export job and shown in the Deploy tab.

= 1.9.0 =
* New: changed-only export. Settings > Export mode adds a second mode that starts from the last export and re-exports only what changed, while full export stays the default.
* Posts and pages are tracked by `post_modified`, so an unchanged page is not requested at all and its copied file is kept.
* Taxonomy, author and date archives use a count plus modification-time sum, so editing, adding or removing a post re-exports every archive that contains it. The homepage, the posts page, feeds and additional URLs are always rebuilt.
* Assets are revalidated with a conditional request (`If-None-Match` / `If-Modified-Since`). A 304 Not Modified keeps the copied file, so a replaced image with an unchanged URL is still picked up.
* Files for deleted posts are removed from the new export and the log reports how many were dropped.
* A run falls back to a full export automatically when the previous files cannot be trusted: a plugin, WordPress or theme update, a permalink or site URL change, or any output setting change. A missing or unreadable previous folder also forces a full export.
* New WP-CLI flags `--changed-only` and `--full` for a single run without changing the saved setting. `status` now prints the export mode.
* New database table `{prefix}aouwal_export_state` storing one fingerprint row per page and asset, with an automatic schema upgrade on update. The table is dropped on uninstall.
* Fix: response headers are now read correctly, so content-type detection and asset validators see the real header values.

= 1.8.0 =
* New: transient crawl failures are retried automatically. Timeouts, HTTP 408, 425, 429 and 5xx responses are retried with a short backoff (0.25 s, 0.5 s, 1 s), honouring Retry-After when the server sends it.
* The retry count is configurable in Settings > Network (0 to 5, default 2). Zero restores the old single-attempt behaviour.
* Permanent errors such as 401, 403 or 404 are never retried, and a URL refused by the safety checks is not retried either.
* New Crawl health report on the Export screen: exported, skipped and failed counts, the reason for each failure, the number of attempts, and the page or stylesheet that referenced a failed asset.
* New "Retry failed" button reprocesses only the failed items of the last export, reuses the files already written and rebuilds the ZIP in place.
* New WP-CLI command `wp aouwal-static-export retry [--batch=<n>]`. `status` now prints failure reasons and the failed URLs.
* Retry attempts appear in the log, and the final notice lists the failure reasons.
* Database: job items now store the origin URL and the attempt count, with an automatic schema upgrade on update.

= 1.7.0 =
* New: optional path rewrite for uploaded media. Settings > Paths can move /wp-content/uploads/ to a shorter folder such as /uploads/ or /media/ in the export.
* When enabled, every reference and file uses the new folder: HTML attributes, CSS url() and @import, srcset variants, manifest icons, the file layout and the ZIP, plus the _headers cache rule.
* Off by default, so the export keeps the live /wp-content/uploads/ structure byte for byte when it is disabled.
* The change is URL-only: it does not remove other WordPress fingerprints such as generator meta tags or already-localized script paths.
* Trade-off: old /wp-content/uploads/ links stop working unless you add redirects.
* Sanitizes the target folder to a safe relative path and ignores an empty or invalid value.

= 1.6.0 =
* New: Pause, Resume and Cancel controls on the Export screen. A long export can be stopped at a batch boundary without losing progress, and a wrong export can be aborted.
* Pause keeps the output folder, the ZIP is not built, and Resume continues from the next pending URL. Pages exported before the pause stay in the final site.
* Cancel deletes the partial output folder, the ZIP and the remaining queue rows, records the last processed URL in the log, and leaves the Start button ready for a new export.
* The export phase is stored on the server (`crawl`, `paused`, `done`, `cancelled`), so reloading the screen restores the paused export with its Resume and Cancel buttons. The loop never resumes on its own.
* A job that stopped making progress is marked as interrupted after five minutes without a written file, and the screen offers Resume or Cancel instead of showing a stalled crawl.
* A batch or a finalize request that reaches the server after a cancel repeats the cleanup, so no file written by the in-flight batch survives.
* New AJAX actions for pause, resume, cancel and status, so the controls act without reloading the screen.
* New WP-CLI commands: `wp aouwal-static-export status`, `cancel` and `resume --batch=<n>`. `cancel` exits with 1 so a cron script stops before pushing a partial build.
* The `_redirects`, `_headers`, `robots.txt` and `sitemap.xml` files are only written by a completed export, never by a paused or cancelled one.

= 1.5.0 =
* New Network settings section: request timeout, delay between requests, crawl concurrency, custom crawl origin, TLS verification and HTTP Basic Auth.
* HTTP Basic Auth support for protected and staging sites. Credentials are sent only to the site's own hosts, never to external asset hosts, and never after a redirect to another domain.
* The Basic Auth password is encrypted at rest with sodium or OpenSSL, keyed from your WordPress salts. It is never rendered back into the settings page and never written to the export log.
* Custom crawl origin: crawl through a container name or reverse proxy while links, canonical URLs and sitemap entries keep the site URL. Link-local and cloud metadata addresses stay blocked.
* TLS verification modes: verify everywhere, verify except on local hosts (previous behavior, still the default), or verify against a custom CA bundle.
* Request timeout is now a setting (60 seconds by default, up to 120) instead of a hard-coded value, and the delay between requests can be set from 0 to 3000 milliseconds.
* WP-CLI: `wp aouwal-static-export run` accepts `--timeout`, `--delay` and `--origin` for a single run without saving them.
* Defaults reproduce the previous behavior exactly, so an untouched install crawls the same way as before.

= 1.4.1 =
* Documentation: new "Automate exports with WP-CLI and cron" section with a cron, unzip, commit and push recipe.
* Documentation: the large-site FAQ now lists the real batch size range (1 to 50, default 5), explains that each batch is one request, and adds memory guidance.
* Documentation: new FAQ about scheduling exports and publishing to Netlify, Cloudflare Pages or S3 automatically.
* No code changes in this release.

= 1.4.0 =
* New display name: Aouwal Static Site Export – Static Site Generator, with a clearer short description and tags. The plugin directory slug is unchanged, so updates keep working as usual.
* Archive pagination is now exported. The blog index, category, tag, custom taxonomy, author and date archives include page 2 and later, so older posts stay reachable in the static site.
* Pagination follows your site settings: posts per page, the pagination base and the permalink style. Sites with plain permalinks skip pagination, because a static file server cannot serve ?paged=2 as its own page.
* Paginated archive URLs are left out of sitemap.xml, matching WordPress core sitemap behaviour.
* HTML5 markup is kept intact. `<picture>` and `<source>`, `<video>` and `<track>`, `<template>` blocks and custom elements survive the export without being re-nested.
* Inline scripts that build HTML are no longer truncated by the parser.
* SVG sprite references keep their fragment, so `sprite.svg#icon` icons keep working after asset localization.
* New: automated checks on GitHub Actions for PHP syntax (7.4, 8.2, 8.4), PHP_CodeSniffer with the WordPress and WordPress-Extra rulesets, and Plugin Check.
* New: the plugin ships a translation template at `languages/aouwal-static-site-export.pot`.
* The codebase passes PHP_CodeSniffer WordPress and WordPress-Extra with no errors and no warnings.

= 1.3.0 =
* Files that cannot be written (permissions, full disk, WP_Filesystem unavailable) are now reported. The page or asset is marked as an error instead of "done", the log names the path, and the export finishes with a warning that counts the failures.
* The generated files (404.html, sitemap.xml, robots.txt, _redirects, _headers) are checked the same way, and the log lists any that could not be written.
* WP-CLI exits with a non-zero status when items fail, so automated exports stop reporting success for an incomplete site.
* 404.html is only taken from the theme when the site actually answers 404 for unknown URLs. Sites that serve the homepage (or a 200 status) get the built-in 404 page and a note in the log. Settings > Cleanup &amp; output > 404 page lets you choose the built-in page on purpose: it has no search box or archive widgets, a home link and works at any URL depth.
* New "Maximum asset size" setting (25 MB by default). Larger files are skipped with a clear error instead of being loaded into memory. HTML pages are never truncated.
* Security: every redirect hop is validated by the SSRF guard, so a public URL cannot redirect the request to localhost, a private range or a link-local address.
* Security: the guard resolves both A and AAAA records and validates every address. IPv6-only hosts now work, and IPv4-mapped IPv6 addresses cannot bypass the private-range checks. DNS results are cached per request.
* The previous ZIP file is removed through WP_Filesystem instead of raw unlink(), with a clear error when it cannot be removed.
* Documented the one deliberate WP_Filesystem exception (ZipArchive and the directory iterator used to build the archive).

= 1.2.0 =
* Settings now control what the export actually does:
* "Remove comment forms and comment sections" now removes comment markup, the comment reply script, comment feed links and the unused block stylesheet.
* "Cloudflare Pages _redirects file" now controls whether that file is written.
* New "Cloudflare Pages _headers file" setting. The file is no longer written unconditionally.
* The RSS feeds setting now also removes the feed rules from _redirects.
* Fixed the nested feed rewrite rule, which used invalid Cloudflare Pages syntax. It is now `/*/feed/ /:splat/feed/index.xml 200`.
* Page URLs with dots in the slug, such as /v1.2/, are exported as v1.2/index.html instead of a file named "v1.2".
* /index.php is exported as index.html, and other .php pages as .html, instead of nested folders.
* Sites installed in a subdirectory no longer treat sibling paths such as /w2/ as internal, and no longer strip their prefix.
* The base element is removed from exported pages, because every URL is rewritten.
* Images that use data-lazy-src with a URL the export does not download no longer lose their src attribute.
* The "Strip cache-busting query strings" setting was removed. Query strings are always stripped, because a static file server cannot serve style.css?ver=1.2 differently from style.css.
* Documentation, the settings table and the Deploy tab now describe the optional cleanup and generated files accurately.
* Removed an unused deactivation cron hook and an undocumented WP-CLI --keep-zip option.

= 1.1.2 =
* The PHP zip extension is now checked when the export starts, before any page is crawled, instead of after a full export.
* The Export tab shows a clear message and disables the start button when the zip extension is missing.

= 1.1.1 =
* Removed the unused localized AOUWAL.batch value. The batch size is read server-side on every request, so exports always use the current setting.
* Corrected the project structure tree in the README so it matches the actual file layout.

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

= 1.13.0 =
Adds optional HTML minification and pre-compressed .gz sidecars for nginx gzip_static. Both are off by default, so nothing changes until you enable them.

= 1.12.0 =
Adds machine-readable WP-CLI output, --output-dir/--zip overrides for cron and CI, documented exit codes and an export lock that prevents overlapping runs. Default output is unchanged.

= 1.11.0 =
Adds optional direct deployment to Cloudflare Pages and Netlify, with hash-based upload of changed files and per-target automatic deploy. Off by default, so nothing changes until you enable it.

= 1.10.0 =
Adds optional direct deployment to GitHub from the Deploy tab, with token-only setup, changed-file upload and automatic deploy after each export. Off by default, so nothing changes until you enable it.

= 1.9.0 =
Adds a changed-only export mode that reuses the previous export and re-exports only changed pages and assets, plus WP-CLI --changed-only and --full flags. Full export stays the default, so nothing changes until you switch modes. The database upgrade runs automatically.

= 1.8.0 =
Adds automatic retries for transient crawl failures, a crawl health report naming every failed URL and its reason, and a "Retry failed" action plus a WP-CLI retry command. No action needed.

= 1.7.0 =
Adds an optional path rewrite that moves /wp-content/uploads/ to a shorter folder such as /uploads/ or /media/ in the export. Off by default; existing exports are unchanged. No action needed.

= 1.6.0 =
Adds Pause, Resume and Cancel to the Export screen, so a long crawl can be stopped and continued without losing progress and a wrong export can be cleaned up. New WP-CLI status, cancel and resume commands. No action needed.

= 1.5.0 =
Adds a Network settings section: request timeout, delay, concurrency, custom crawl origin, TLS verification and HTTP Basic Auth for staging sites. Defaults keep the previous crawl behavior, so nothing changes until you edit the settings.

= 1.4.1 =
Documentation only: real batch size limits, a WP-CLI, cron and Git deployment recipe, and an answer on scheduling and automatic publishing. No action needed.

= 1.4.0 =
New name: Aouwal Static Site Export – Static Site Generator. Archives now export their pagination (page 2 and later), HTML5 markup such as picture, source, track and template survives, and SVG sprite fragments are kept. Adds automated checks and a translation template. No action needed.

= 1.3.0 =
Adds a Maximum asset size setting (25 MB default), reports failed file writes instead of marking them done, validates every redirect against the SSRF guard, resolves IPv6, and only uses your theme 404 template for real 404s. No action needed.

= 1.2.0 =
Several settings now work as described: comment removal, the _redirects file and the RSS feed rules are honoured, and a new setting controls the _headers file. Dotted slugs, .php URLs and subdirectory installs now export to the correct paths. Check the Generated files settings after updating.

= 1.1.2 =
Fixes a late failure on hosts without the PHP zip extension. The export is now refused up front with a clear message instead of after a full crawl. Update recommended.

= 1.1.1 =
Housekeeping release: removes unused localized admin data and corrects the README project structure. No behaviour change; update at your convenience.

= 1.1.0 =
Security release. Export ZIPs now download through signed, expiring links instead of a permanent public URL, and the export folder is protected from direct access. Recommended for every site.

= 1.0.3 =
Fixes link hint handling and localizes web app manifest icons in exports. Update recommended.

= 1.0.2 =
All remote requests now use the WordPress HTTP API. Update to stay secure and compliant with the WordPress.org plugin guidelines.
