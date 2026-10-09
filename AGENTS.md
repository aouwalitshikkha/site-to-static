# AGENTS.md — Site to Static (WordPress plugin)

## What this is
Dependency-free WordPress plugin (PHP 7.4+, WP 6.0+) that crawls a site over
loopback HTTP and exports portable static HTML + localized assets as a ZIP.
No Composer, no build step, no test framework, no CI.

## Naming (must follow for any new code)
- Slug + text domain: `site-to-static`
- Classes/constants: `STS_*` (`STS_Exporter`, `STS_VERSION`, `STS_FORCE_CREDIT`)
- Functions/options/hooks/DB: lowercase `sts_*` (`sts_activate`, `sts_settings`,
  `sts_jobs`, `sts_job_items` table, `sts_start` AJAX actions, `sts_ajax` nonce)
- JS global `STS`, CSS classes `sts-*`, settings fields `sts_settings[...]`
- PHP 7.4 compatible only — no PHP 8-only syntax.

## Two branches — do not mix up
- `main` = GitHub build. Export credit is **forced** via
  `define('STS_FORCE_CREDIT', true)` in `site-to-static.php`; multi-part credit
  incl. base64 marker.
- `wporg` = WordPress.org submission build. **No force-define, no base64.**
  Credit is opt-in only (`show_credit` setting, default off).
- Shared fixes go on `main` first, then `git cherry-pick` to `wporg`.
  Never merge `main` into `wporg` (would drag the forced credit across).
- Pre-release legacy names were `WSE_*`/`wse_*`/`wp-static-export`; activation
  removes leftover `wse_*` options + `wpstatic_job_items` table
  (`sts_remove_legacy_data()`).

## Architecture (entry points)
- `site-to-static.php` — bootstrap, activation (dbDelta table), deactivation.
- `includes/class-exporter.php` — chunked job state machine (`start`,
  `process_batch`, `finalize`); job rows in `{prefix}sts_job_items`.
- `includes/class-url-discovery.php` → `class-crawler.php` (loopback
  `wp_remote_get`; SSL verify ON except local hosts) → `class-html-processor.php`
  (DOM rewrite + strip) → `class-asset-processor.php` (download + CSS recursion)
  → `class-writer.php` (permalink → `path/index.html`, relative links) →
  `class-zip.php`. Credits via `includes/class-brand.php`
  (`STS_Brand::should_include()` gates every insertion — keep it that way).
- `admin/` — Settings/Export/Deploy tabs + AJAX (`admin-ajax.php`);
  `cli/class-cli.php` — `wp static-export run`.

## WordPress.org compliance (hard-won, do not regress)
- Nonces + `current_user_can('manage_options')` must stay **inline** in each
  AJAX method (checker can't see helper methods).
- All writes via `WP_Filesystem` (`STS_Writer::write()`); `uninstall.php` too.
- No `load_plugin_textdomain` (discouraged since WP 4.6; core auto-loads).
- `sslverify` true except `localhost/127.0.0.1/::1/*.local/*.test`.
- Custom-table `$wpdb` calls carry justified `phpcs:ignore` comments — do not
  remove them; do not "fix" with caching (queue mutates every batch).
- `readme.txt`: `Contributors: aouwal`, hyphenated tags (max 5),
  `Tested up to` = current WP. JS strings via `wp_localize_script` i18n.
- Submission ZIP: plugin folder at root, <10MB, **no dotfiles**
  (`.gitignore` alone triggers `hidden_files` ERROR). Also exclude dev docs
  (`AGENTS.md`, `agent.md` — `unexpected_markdown_file` warning).

## Local verification (XAMPP test site)
- PHP lint: `C:\xampp\php\php.exe -l <file>` (PHP 8.0.30; has zip/dom/mbstring/curl).
- Test WP: `C:\xampp\htdocs\w` (`http://localhost/w/`, DB `new`/root/nopass).
  Start Apache/MySQL first if ports 80/3306 are closed; install plugin copy to
  `wp-content/plugins/site-to-static/`.
- Export harness: temp `*_run.php` in `htdocs\w` that boots `wp-load.php`,
  runs `STS_Exporter` start→batch→finalize. **Delete harness scripts after**;
  never commit them. Serve output via Apache subdir or `php -S` and check
  pages/assets return 200; grep output for leftovers (`localhost`, `noindex`).
- WP-CLI: `C:\Users\aouwa\AppData\Local\Temp\opencode\wp-cli.phar` (run from
  `htdocs\w`). Plugin Check is installed there; run **static checks only**
  (Performance runtime checks fail under CLI). 0 errors required; the only
  acceptable warnings are custom-table `$wpdb`/`DROP TABLE` (annotated) and
  the `trademarked_term` "wp" flag (false positive — Foundation permits "WP").

## PowerShell pitfalls (learned the hard way)
- `-ne`/`-eq` are **case-insensitive**: use `-cne` when detecting case-only
  changes, or file writes get skipped.
- Avoid `$1` regex backreferences in `-replace` (unreliable here); use
  `[regex]::Match` inspection or explicit literal `.Replace()` pairs, and
  `-creplace` (case-sensitive) when case matters.
- `Get-ChildItem -Recurse -Include` returns directories too — filter to files
  before `Get-Content`, or quote `git grep` patterns carefully (PowerShell
  eats quotes; prefer `git grep -n "pattern" -- "*.php"`).
- `Compress-Archive -LiteralPath` does not expand wildcards; use `-Path`.

## Misc
- GitHub: `aouwalitshikkha/site-to-static` (`main`); wp.org user: `aouwal`.
- Author/brand: Abdul Aouwal, https://abdulaouwal.com (slate `#0f172a` +
  indigo `#4f46e5`, Inter). Admin UI already themed; keep it.
- Keep `.gitignore` in repo but **exclude it from submission ZIPs**.
- Workspace rule: never delete/move user files without asking; scratch files
  you create (harness, zips in temp) are yours to clean up.
