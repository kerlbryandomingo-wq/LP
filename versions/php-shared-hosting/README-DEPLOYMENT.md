# LP System — PHP / MySQL Shared-Hosting Edition

This is an independent PHP/MySQL port of LP System, built to run on standard
cPanel-style shared hosting with **no Node.js, no PostgreSQL, no Docker, no
SSH/root access, and no Composer required**.

It was ported from the original Next.js/Prisma/PostgreSQL application living
at the repository root. **That original application is untouched** — this
is a separate, independently deployable codebase under
`versions/php-shared-hosting/`.

Read **FEATURE-PARITY.md** before relying on this for production — it lists
exactly what has been ported and tested, and what has not, honestly.

## Requirements

- PHP 8.0 or newer (tested on 8.3) with these extensions: `pdo_mysql`,
  `mbstring`, `json`, `fileinfo`, `session`. `gd` is optional (only used if
  you later add image resizing).
- MySQL 5.7+ or MariaDB 10.3+ (InnoDB).
- Apache with `mod_rewrite` and `mod_headers` (or an equivalent on
  LiteSpeed/nginx — see "Non-Apache hosts" below).
- No Composer, no `vendor/` directory, and no shell access are required to
  run the app — everything needed ships in this folder. Composer is
  supported but optional (see `composer.json` — there are zero required
  third-party packages).

## Directory layout

```
php-shared-hosting/
├── public/            <- Point your domain's document root HERE
│   ├── index.php      <- Front controller
│   ├── install/       <- Browser installer (self-locking after use)
│   ├── assets/        <- CSS/JS/images
│   └── uploads/       <- Public, non-sensitive files only
├── app/               <- PHP source (Controllers/Models/Services/Views) — keep OUTSIDE the public document root
├── config/            <- config.php is generated here by the installer — keep OUTSIDE the public document root
├── database/          <- SQL migrations + seed data
├── storage/            <- logs/, cache/, private-uploads/ — keep OUTSIDE the public document root
└── tests/
```

**If your host only gives you one folder (e.g. `public_html/`) and will not
let you point it at a subdirectory:** upload the entire `php-shared-hosting/`
contents into `public_html/`, then rename `public_html/public/` by MOVING its
contents up one level AND keeping `app/`, `config/`, `database/`, `storage/`
as siblings of `public_html/`'s new contents, one level ABOVE the web root if
at all possible. The `.htaccess` files in `config/`, `storage/`, `database/`,
and `app/` deny-all as a second layer of defense either way, but the real
protection is these folders not being under the web root at all. See
INSTALL.md's "Single-folder hosts" section for the exact steps.

## Quick start (cPanel)

1. In cPanel → "MySQL Databases," create a database and a user with full
   privileges on it. Note the host (usually `localhost`), database name,
   username, and password.
2. Upload this entire `php-shared-hosting/` folder's contents via File
   Manager or FTP. If your host supports subdomains/addon domains with a
   custom document root, point it at this folder's `public/` subdirectory.
   Otherwise see the single-folder note above.
3. Visit your domain in a browser. You'll land on `/install/` automatically
   until installation is complete (the app itself returns a friendly
   "not installed yet" message with a link there).
4. Follow the 6-step wizard: requirements check → database credentials →
   create tables → create your admin account → site settings → finish.
5. The installer locks itself (`storage/install.lock`) once done. As a
   further precaution, delete or rename `public/install/` entirely — see
   INSTALL.md.

## Non-Apache hosts (LiteSpeed / nginx)

LiteSpeed reads `.htaccess` natively — no changes needed. For nginx, there
is no `.htaccess` equivalent; ask your host to apply the same three rules
`public/.htaccess` encodes: (1) route all non-file requests to
`index.php`, (2) deny execution of `.php` files under `uploads/`, (3) deny
all access to `config/`, `storage/`, `database/`, `app/`. A sample nginx
block is in `docs/nginx-example.conf`-equivalent guidance inside
SECURITY.md.

## Support documents

- **INSTALL.md** — detailed installer walkthrough + troubleshooting.
- **UPGRADE.md** — how to update to a newer release without data loss.
- **BACKUP.md** — what to back up and how to restore it.
- **SECURITY.md** — the security model, what's protected and how, and
  hosting-specific hardening notes.
- **FEATURE-PARITY.md** — the honest checklist: what's implemented and
  tested, what's a documented gap, and why.
- **TESTING.md** — exactly what was tested in this release, how, and the
  results.
