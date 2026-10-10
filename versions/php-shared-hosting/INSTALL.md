# Installing LP System (PHP/MySQL Edition)

## Before you start

- Create a MySQL/MariaDB database and a database user with full privileges
  on it. Most cPanel hosts: "MySQL Databases" → create database → create
  user → "Add User to Database" with ALL PRIVILEGES.
- Know your database host (almost always `localhost` on shared hosting).
- Have the folder uploaded (via File Manager's upload+extract, or FTP) so
  that your domain's document root points at this folder's `public/`
  subdirectory, or follow "Single-folder hosts" below if your host can't
  do that.

## Single-folder hosts

If your host forces the document root to be exactly `public_html/` (or
similar) with no way to point it at a subdirectory:

1. Upload this release's `public/` folder's **contents** (not the folder
   itself) directly into `public_html/`.
2. Upload `app/`, `config/`, `database/`, `storage/` as siblings **one
   level above** `public_html/`, if your hosting account's file structure
   allows a level above the web root (cPanel accounts usually have a
   home directory containing `public_html/` alongside other folders —
   put them there).
3. If your account truly has no directory above the web root at all,
   upload `app/`, `config/`, `database/`, `storage/` INSIDE `public_html/`
   as normal sibling folders of the files you just uploaded in step 1.
   This is less ideal (they're technically inside the web root) but the
   `.htaccess` deny-all files inside each of those folders still fully
   block HTTP access to them — verified in this release's testing (see
   TESTING.md). Either way, after extracting, edit nothing: the installer
   finds these folders relative to its own location automatically.

## The wizard

1. **Welcome** — confirms you're ready; nothing is changed yet.
2. **Requirements Check** — verifies PHP version, required extensions, and
   that `config/`, `storage/`, and `public/uploads/` are writable. A "FAIL"
   here almost always means a folder permission — set it to `755` (or
   `775` if your host requires group-writable) via File Manager's
   "Permissions" option, then reload the page.
3. **Database Connection** — enter your host/port/database/username/
   password. The wizard tests the connection for real before letting you
   continue; a wrong password or host shows the exact database error.
4. **Create Database Tables** — creates the schema and loads a starter
   production workflow template. Safe to click even if you're unsure
   whether tables already exist — it checks first and never drops or
   duplicates anything.
5. **Create Administrator Account** — this becomes your first login. Use
   a real email address you control and a password of at least 8
   characters.
6. **Site Settings** — business name, site URL (auto-detected — only
   change this if you're installing at a URL different from the one
   you're viewing the installer from), and timezone.
7. **Finish** — writes `config/config.php` (with a freshly generated
   random app key) and `storage/install.lock`, which permanently disables
   the installer. You're redirected to `/login`.

## After installing

- **Delete or rename `public/install/`.** The lock file alone is enough to
  stop reinstallation, but removing the folder is the most thorough option
  and is recommended before going live.
- Log in with the administrator account you just created.
- Review **SECURITY.md** for HTTPS and further hardening steps.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Requirements check fails on a folder | Wrong permissions | Set the folder to 755 via File Manager |
| "Could not connect" on the database step | Wrong host/user/password, or the DB user lacks privileges | Double-check cPanel → MySQL Databases; re-grant ALL PRIVILEGES |
| Blank white page anywhere | A PHP fatal error occurred and was logged, not shown (by design — see SECURITY.md) | Check `storage/logs/php-error.log` and `storage/logs/app.log` via File Manager |
| "LP System is already installed" but you need to reinstall | The installer locked itself | Delete `storage/install.lock` via FTP/File Manager, then revisit `/install/`. This does **not** delete any data — it just re-opens the wizard, which itself never drops existing tables. |
| Installer stopped partway (e.g. browser closed) | No partial state is saved until the final "Finish" step | Just start again from step 1 — nothing was written to disk yet, this is the designed recovery path. |

## Recovery: resuming an interrupted install

Steps 1–6 only hold their data in your PHP session, not on disk. If your
browser closes or the connection drops before you reach step 7 ("Finish"),
nothing has been written to `config/config.php` or locked — tables may
have already been created by step 4 (harmless; step 4 is idempotent), but
simply reload `/install/` and start again. The installer will skip
table creation if it detects the tables already exist and let you proceed
straight to creating your admin account.
