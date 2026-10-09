# agent.md — Site to Static quick reference

> Companion to `AGENTS.md` (full guide). This is the 60-second version.

## What
WordPress plugin → static HTML + assets ZIP. PHP 7.4+, no Composer/build/CI.

## Naming
Slug/domain `site-to-static` · classes/consts `STS_*` · everything else `sts_*`.

## Branches (critical)
- `main` — GitHub. Forced credit (`STS_FORCE_CREDIT` defined). Keep it.
- `wporg` — wp.org submit. No force-define, no base64, opt-in `show_credit` off.
- Shared fixes → `main` first, `git cherry-pick` to `wporg`. Never merge across.

## Commands
```powershell
# lint
& C:\xampp\php\php.exe -l <file>
# test export (temp harness in C:\xampp\htdocs\w, DELETE after)
# serve + check: copy output to C:\xampp\htdocs\static-test, browse http://localhost/static-test/
# WP-CLI (from htdocs\w)
& C:\...\wp-cli.phar plugin check site-to-static --ignore-warnings
```

## Gotchas
- `-ne` is case-insensitive → use `-cne`; avoid `$1` in `-replace`.
- Nonces+caps must be inline in each AJAX method (checker limitation).
- Writes via `WP_Filesystem`; custom-table `$wpdb` needs justified ignores.
- Submission ZIP: folder at root, <10MB, exclude `.git`, `.gitignore`,
  `AGENTS.md`, `agent.md` (dev docs trigger check warnings).
- Test WP: `C:\xampp\htdocs\w`, DB `new`/root/nopass; start Apache/MySQL if needed.
