# Aouwal Static Site Export

**By [Abdul Aouwal](https://abdulaouwal.com) — Technical SEO Consultant**

[![WordPress.org](https://img.shields.io/badge/WordPress.org-Aouwal%20Static%20Site%20Export-21759b?logo=wordpress&logoColor=white)](https://wordpress.org/plugins/aouwal-static-site-export/)

Also available on the WordPress plugin directory: **<https://wordpress.org/plugins/aouwal-static-site-export/>**

Export a WordPress site to a fully static HTML bundle with localized assets — ready to push to GitHub and deploy on Cloudflare Pages (or any static host).

The plugin crawls your site over loopback HTTP, captures the fully rendered HTML of every page (theme, plugins and all), downloads and localizes assets, rewrites links to be portable, strips server-side features, and packages everything into a downloadable ZIP.

## How it works

1. **Discover** — enumerates posts, pages, custom post types, taxonomy archives, author archives, date archives and feeds.
2. **Crawl** — requests each URL so the exact rendered HTML WordPress serves is captured.
3. **Process HTML** — rewrites internal links to relative paths, localizes `src`/`srcset`/inline `url()`, and removes admin bar, `wp-json`, nonces, comment/search forms and staging `noindex` tags.
4. **Download assets** — CSS, JS, images and fonts are copied locally, with recursive CSS `url()` and `@import` handling.
5. **Write output** — mirror of your permalink structure (`/about/index.html`, `/blog/post/index.html`), plus `404.html`, `sitemap.xml`, `robots.txt`, `_redirects` and `_headers`.
6. **Package** — everything is zipped for download.

Export runs in **chunked AJAX batches**, so large sites don't hit PHP timeouts, and includes a live progress log.

## Features

- Posts, pages, custom post types, categories, tags, custom taxonomies, author archives and date archives.
- Downloads and localizes all assets, recursing into CSS `url()` and `@import` references.
- Rewrites internal links to portable relative (or root-absolute) URLs.
- Removes server-side features that cannot work statically (search forms, comment forms, comment feeds).
- Strips WordPress version tags and staging `noindex` robots meta so the exported site is indexable.
- Optional, invisible source-code credit (off by default) that can be enabled in Settings. It never shows in the browser.
- Generates `404.html`, `sitemap.xml`, `robots.txt` and Cloudflare Pages `_redirects` / `_headers` files.
- Stores exports in a protected uploads subfolder and serves the ZIP only through a signed, expiring admin link. Use the `aouwal_export_dir` filter to relocate exports outside the web root.
- Chunked, timeout-safe export with a live progress log.
- Optional WP-CLI command: `wp aouwal-static-export run`.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer with the `zip`, `dom` and `mbstring` extensions
- For the recommendation feature (none) — no external services required

## Installation

**From the WordPress plugin directory:**
1. Search for **Aouwal Static Site Export** under *Plugins → Add New*, or install it directly from <https://wordpress.org/plugins/aouwal-static-site-export/>.
2. Click *Install Now*, then *Activate*.

**From a ZIP:**
1. Download/clone this repository.
2. Copy the `aouwal-static-site-export` folder into `wp-content/plugins/`.
3. Activate **Aouwal Static Site Export** under *Plugins*.

**With git:**
```bash
cd wp-content/plugins
git clone https://github.com/aouwalitshikkha/site-to-static.git
```
Then activate the plugin in the WordPress admin.

## Usage

### Admin UI

Open **Static Export** in the WordPress admin menu. Three tabs:

- **Settings** — choose post types, archives, asset handling, URL style, cleanup and generated files.
- **Export** — click *Start static export*, watch the live progress bar and log, then download the ZIP.
- **Deploy** — download the latest ZIP and follow the GitHub + Cloudflare Pages guide.

### WP-CLI

```bash
wp aouwal-static-export run --batch=10
```
Runs a full export and prints the resulting ZIP path.

## Settings reference

| Setting | Description |
|---|---|
| Post types | Which post types to export (posts, pages, CPTs). |
| Archives | Include taxonomy, author and/or date archives and RSS feeds. |
| Asset handling | Download and localize assets; strip cache-busting query strings. |
| URL style | `Relative` (portable, works in a subdirectory) or `Root-absolute` (best for a Cloudflare Pages root domain). |
| Public site URL | Used for `sitemap.xml` / `robots.txt`. Leave blank to use the current site URL. |
| Additional URLs | Extra URLs to export, one per line. |
| Exclude URLs | Patterns to skip; plain text matches a substring, `/regex/` for a regular expression. |
| Remove dynamic features | Strip comment sections/forms and search forms. |
| Generated files | Toggle `sitemap.xml`, `robots.txt` and the Cloudflare `_redirects` file. |
| Batch size | URLs processed per request. Lower it if your server times out. |

## Deploy to Cloudflare Pages via GitHub

1. Download the export and unzip it into a new folder.
2. Push the files to a GitHub repository:
   ```bash
   # from inside the folder where you unzipped the export
   git init
   git add .
   git commit -m "Initial static export"
   git branch -M main
   git remote add origin https://github.com/USERNAME/REPO.git
   git push -u origin main
   ```
3. In the Cloudflare dashboard go to **Workers & Pages → Create → Pages → Connect to Git**.
4. Select the repository. Leave the build command blank and set the build output directory to `/` (repository root).
5. Click **Save and Deploy**. Every future push redeploys automatically.

The export includes a `_redirects` file (feed rewrites, `/index.php` cleanup) and a `_headers` file for caching.

## Known limitations

- Sites that rely heavily on client-side JavaScript (WooCommerce cart, some page builders such as Elementor) may not work fully once static.
- Forms need an external endpoint (e.g. Formspree, Web3Forms); server-side WordPress forms will not function.
- Server-side search and comments are removed.
- Cloudflare Pages cannot redirect by query string (`/?p=123`); use pretty permalinks.

## Project structure

```
aouwal-static-site-export/
├── aouwal-static-site-export.php          # bootstrap + activation
├── uninstall.php                 # cleanup on uninstall
├── readme.txt                    # WordPress.org-style readme
├── README.md                     # this file
├── LICENSE                       # GPL-2.0-or-later
├── includes/
│   ├── class-brand.php
│   ├── class-settings.php
│   ├── class-writer.php          # permalink → path mapping, relative links
│   ├── class-url-discovery.php   # URL enumeration
│   ├── class-crawler.php         # loopback HTTP fetch
│   ├── class-html-processor.php  # link rewriting + markup cleanup
│   ├── class-asset-processor.php # asset download + CSS recursion
│   ├── class-exporter.php        # chunked job state machine + extras
│   └── class-zip.php
├── admin/
│   ├── class-admin.php
│   ├── views/                    # settings, export, deploy
│   └── assets/                   # admin.css, export.js
└── cli/class-cli.php             # WP-CLI command
```

## License

[GPL-2.0-or-later](LICENSE).

## Author

**Abdul Aouwal** — Technical SEO Consultant & Specialist for indexing, crawl & AI visibility.

- Website: [abdulaouwal.com](https://abdulaouwal.com)
- WordPress.org: [Aouwal Static Site Export](https://wordpress.org/plugins/aouwal-static-site-export/)
- GitHub: [@aouwalitshikkha](https://github.com/aouwalitshikkha)
- LinkedIn: [aouwalitshikkha](https://www.linkedin.com/in/aouwalitshikkha/)
- Instagram: [@aouwalcmc](https://www.instagram.com/aouwalcmc)
- Facebook: [itshikkha](https://www.facebook.com/itshikkha/)
- Pinterest: [aouwalitshikkha](https://www.pinterest.com/aouwalitshikkha/)
