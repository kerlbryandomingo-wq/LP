# LP System — PHP / MySQL Shared-Hosting Edition

This is an independent PHP/MySQL port of LP System, built to run on standard
cPanel-style shared hosting with **no Node.js, no PostgreSQL, no Docker, no
SSH/root access, and no Composer required**.

It was ported from the original Next.js/Prisma/PostgreSQL application living
at the repository root. **That original application is untouched** — this
is a separate, independently deployable codebase under
`versions/php-shared-hosting/`.

Read **FEATURE-PARITY.md** before relying on this for production — it lists
exactly what has been ported and tested, and what has not.

## Requirements

- PHP 8.0 or newer (tested on 8.3) with these extensions: `pdo_mysql`,
  `mbstring`, `json`, `fileinfo`, `session`. `gd` is optional.
- MySQL 5.7+ or MariaDB 10.3+ (InnoDB).
- Apache with `mod_rewrite` (standard on cPanel hosts) or LiteSpeed, which
  reads the same `.htaccess` files. nginx needs the rules in SECURITY.md.
- The site must live at the **root of a domain or subdomain**
  (`https://yourdomain.com/` or `https://lp.yourdomain.com/`), not in a
  subfolder such as `https://yourdomain.com/lp/` — the app's links and
  redirects are root-relative.
- No Composer, no `vendor/` directory, no shell access. There are zero
  third-party packages.

## What's in the ZIP

The ZIP's files sit directly at its root — extract it straight into your web
root (`public_html/`) and `index.php` lands where your host expects it:

```
public_html/                 <- extract the ZIP here
├── index.php                <- entry point
├── .htaccess                <- routes every request into public/, blocks everything else
├── public/                  <- the actual web-facing part
│   ├── index.php            <- front controller
│   ├── install/             <- browser installer (locks itself after use)
│   ├── assets/              <- CSS / JS
│   └── uploads/             <- public, non-sensitive files only
├── app/                     <- PHP source — never served directly
├── config/                  <- config.php is written here by the installer — never served directly
├── database/                <- SQL migrations + seed data — never served directly
├── storage/                 <- logs, cache, private uploads — never served directly
├── tests/                   <- test scripts — never served directly
└── *.md                     <- documentation — never served directly
```

Every request is internally rewritten into `public/`, so `app/`, `config/`,
`database/`, `storage/`, `tests/` and the docs can't be downloaded even
though they sit inside `public_html/` (verified on a real Apache server —
see TESTING.md section 6). Each of those folders also has its own deny-all
`.htaccess` as a second layer.

**If your host lets you choose the document root**, you can instead point it
at the `public/` folder; the root `index.php` and `.htaccess` are then
simply unused. Both setups were tested.

## Quick start (cPanel)

1. In cPanel → **MySQL Databases**, create a database and a user, and add
   the user to the database with **ALL PRIVILEGES**. Note the database
   name, username and password (the host is almost always `localhost`).
2. In cPanel → **File Manager**, open `public_html/`, upload
   `LP-System-PHP-Shared-Hosting.zip`, right-click it → **Extract**. You
   should now see `index.php`, `public/`, `app/` etc. directly inside
   `public_html/`. (Turn on "Show Hidden Files" in File Manager settings to
   see the `.htaccess` files.) You can delete the ZIP afterwards.
3. Visit your domain. A fresh install redirects you to `/install/`
   automatically.
4. Follow the wizard: requirements check → database credentials → create
   tables → admin account → site settings → finish.
5. The installer locks itself (`storage/install.lock`). As an extra
   precaution you can then delete `public/install/` — see INSTALL.md.

## Support documents

- **INSTALL.md** — detailed installer walkthrough + troubleshooting.
- **UPGRADE.md** — how to update to a newer release without data loss.
- **BACKUP.md** — what to back up and how to restore it.
- **SECURITY.md** — the security model and hosting-specific notes
  (including nginx rules).
- **FEATURE-PARITY.md** — what's implemented and tested, what isn't, and why.
- **TESTING.md** — exactly what was tested in this release, how, and the
  results.
