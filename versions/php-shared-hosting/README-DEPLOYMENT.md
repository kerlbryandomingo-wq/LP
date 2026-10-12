# LP System — PHP / MySQL Shared-Hosting Edition

An independent PHP/MySQL version of LP System for ordinary cPanel-style
shared hosting: **no Node.js, no PostgreSQL, no Docker, no SSH/root access,
no Composer**. It is installed from the browser, like WordPress. You never
edit PHP files or create database tables by hand.

It was ported from the original Next.js/Prisma/PostgreSQL application at the
repository root, which is untouched. This edition covers the core staff
workflow only. **Read FEATURE-PARITY.md before relying on it**: it lists
exactly what is included, and what isn't (for example the customer portal,
customer forms, statements of account, expenses/costing, email and
Messenger).

## Requirements

- PHP 8.0 or newer (tested on 8.3) with `pdo_mysql`, `mbstring`, `json`,
  `fileinfo`, `session`, `ctype`, `filter`. `gd` is optional.
- MySQL 5.7.8+ or MariaDB 10.3+ (tested on MariaDB 10.11), InnoDB.
- Apache with `mod_rewrite` and `.htaccess` overrides (standard on cPanel),
  or LiteSpeed, which reads the same `.htaccess` files. For nginx, see
  SECURITY.md.
- HTTPS strongly recommended (free AutoSSL / Let's Encrypt on most hosts).

## Where it can live

All three layouts were installed and tested end-to-end:

| You upload the ZIP's contents into… | Site address | Notes |
|---|---|---|
| `public_html/` | `https://yourdomain.com/` | Most common |
| `public_html/lpsystem/` (any folder name) | `https://yourdomain.com/lpsystem/` | Links, redirects and cookies follow the folder automatically |
| any folder, with the domain's document root set to its `public/` | `https://lp.yourdomain.com/` | Optional; for hosts that let you choose the document root |

## What's in the ZIP

The files sit directly at the ZIP's root:

```
index.php        entry point (forwards to public/)
.htaccess        routes every request into public/, blocks everything else
public/          the only web-facing part: front controller, installer, CSS/JS
app/             PHP source           — never served
config/          config.php is written here by the installer — never served
database/        migrations + seed data — never served
storage/         logs, cache, private uploads — never served
*.md             documentation        — never served
```

## Quick start (cPanel)

1. cPanel → **MySQL Databases**: create a database and a user, then add the
   user to the database with **ALL PRIVILEGES**. Note the names and password.
2. cPanel → **File Manager**: open `public_html/` (or a new folder inside
   it), upload `LP-System-PHP-Shared-Hosting-Verified.zip`, right-click →
   **Extract** into that same folder, then delete the ZIP.
3. Visit the site. You are sent to the installer automatically.
4. Follow the six steps: requirements → database → create tables →
   administrator → site settings → finish.
5. Sign in, then add staff accounts under **Admin → Users**.

Details and troubleshooting: **INSTALL.md**.

## Documents

- **INSTALL.md**: installation walkthrough and troubleshooting
- **UPGRADE.md**: installing a newer release without losing data
- **BACKUP.md**: what to back up and how to restore
- **SECURITY.md**: security model, roles, hosting notes
- **FEATURE-PARITY.md**: what is and isn't ported from the original app
- **TESTING.md**: how this release was tested, and how to repeat it
