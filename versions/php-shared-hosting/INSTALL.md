# Installing LP System (PHP/MySQL Edition)

## Before you start

- Create a MySQL/MariaDB database and a database user with full privileges
  on it. Most cPanel hosts: "MySQL Databases" → create database → create
  user → "Add User to Database" with ALL PRIVILEGES.
- Know your database host (almost always `localhost` on shared hosting).
- The site must be installed at the root of a domain or subdomain, not in a
  subfolder like `yourdomain.com/lp/`.

## Uploading the files

1. cPanel → **File Manager** → open `public_html/` (or the folder your
   subdomain points to). It should be empty, apart from anything your host
   put there by default, such as `cgi-bin/`. Remove any placeholder
   `index.html`, which would otherwise be shown instead of the app.
2. Click **Upload**, choose `LP-System-PHP-Shared-Hosting.zip`, wait for it
   to finish, then go back to `public_html/`.
3. Right-click the ZIP → **Extract** → extract into `public_html/` itself.
4. Check the result. Directly inside `public_html/` you should see
   `index.php`, `public/`, `app/`, `config/`, `database/`, `storage/`,
   `tests/` and the `.md` documents. The `.htaccess` files are hidden by
   default — turn on File Manager → Settings → **Show Hidden Files
   (dotfiles)** to see them. If instead you see a single extra folder that
   contains all of this, you extracted into a subfolder: move its contents
   up into `public_html/`.
5. Delete the ZIP from `public_html/`.

Do not move or rename any of the folders — the app finds them relative to
each other. The root `.htaccess` makes sure only `public/` is ever served;
everything else returns "Forbidden" even though it sits inside
`public_html/`.

FTP works the same way: extract the ZIP on your computer and upload its
contents (including the hidden `.htaccess` files) into `public_html/`.

**If your host lets you set a custom document root** (common for addon
domains/subdomains), you may point it at the `public/` folder instead. That
works too; nothing else changes.

## The wizard

1. **Welcome** — confirms you're ready; nothing is changed yet.
2. **Requirements Check** — verifies PHP version, required extensions,
   Apache `mod_rewrite` (where PHP can detect it), and that `config/`,
   `storage/`, and `public/uploads/` are writable. A "FAIL"
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
| Your domain shows a folder listing, your host's default page, or a "LP-System-…" folder instead of the installer | The ZIP was extracted into a subfolder, or a default `index.html` is still there | Make sure `index.php` sits directly in `public_html/` (see "Uploading the files") and delete any `index.html` |
| The home page loads but `/install/`, `/login` or the CSS give "Not Found" | The `.htaccess` files are missing (hidden files weren't uploaded) or the host has URL rewriting turned off | Turn on "Show Hidden Files" and confirm `public_html/.htaccess` and `public_html/public/.htaccess` exist; if they do, ask your host to enable `mod_rewrite` / `.htaccess` overrides |
| Pages work but have no styling and links point to the wrong place | The site was installed in a subfolder (`yourdomain.com/lp/`) | Install at the root of a domain or a subdomain instead |
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
