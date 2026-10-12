# Installing LP System (PHP/MySQL Edition)

You do not need to edit any PHP file or create any database table by hand.

## 1. Create the database

cPanel → **MySQL Databases**:

1. Create a database, e.g. `lpsystem`. cPanel adds your account prefix, so
   the full name becomes something like `myaccount_lpsystem`.
2. Create a database user with a strong password.
3. **Add User To Database** → tick **ALL PRIVILEGES**.

Use an **empty** database. The installer refuses a database that holds
another application's `users` table, and asks for explicit confirmation if
the database already holds LP System data (see "Reinstalling" below).

## 2. Upload the files

1. cPanel → **File Manager** → open the folder the site should live in:
   - `public_html/` for `https://yourdomain.com/`, or
   - a new folder such as `public_html/lpsystem/` for
     `https://yourdomain.com/lpsystem/`.
   Remove any placeholder `index.html` from that folder.
2. **Upload** `LP-System-PHP-Shared-Hosting-Verified.zip`, then right-click
   it → **Extract** into the same folder. Delete the ZIP afterwards.
3. Check: `index.php`, `public/`, `app/`, `config/`, `database/` and
   `storage/` must sit directly in that folder. If you see one extra folder
   containing them, move its contents up one level. To see the `.htaccess`
   files, enable File Manager → Settings → **Show Hidden Files**.

Via FTP: extract the ZIP on your computer and upload everything, including
the hidden `.htaccess` files.

Optional: if your host lets you set a domain's document root, you may point
it at the `public/` folder instead.

## 3. Run the installer

Open the site address. You are redirected to `…/install/`.

| Step | What happens |
|---|---|
| 1 Welcome | Nothing is changed yet. |
| 2 Requirements | Checks PHP version and extensions, that `config/` and `storage/` (and its subfolders) are writable, and that URL rewriting works. A **FAIL** blocks the next step, and the server re-checks when you continue. Folder failures: set the folder to `755` (or `775`) in File Manager → Permissions. |
| 3 Database | Host (usually `localhost`), port (`3306`), database name, user, password. The connection is tested for real; errors are explained in plain words (wrong password, database missing or not assigned to the user, server unreachable). Your password is never shown back. |
| 4 Create tables | Creates all tables and the starter production workflow, records each update in `schema_migrations`, then verifies every table exists. Safe to repeat if interrupted. |
| 5 Administrator | Name, email (your sign-in), password: at least 8 characters with a letter and a number. |
| 6 Site settings | Business name, site address (detected), timezone (default Asia/Manila). **Finish** saves the administrator, writes `config/config.php`, and locks the installer. |

Steps can't be skipped: each needs the previous one to have succeeded on the
server, and every installer form carries a security token.

## 4. After installing

- Sign in with the administrator email and password.
- **Admin → Users**: create accounts for staff (Staff or Production role).
  Each new user receives a one-time temporary password and must choose their
  own at first sign-in.
- The installer is locked: any visit shows "already installed" (HTTP 403)
  and can't change anything. Deleting only `storage/install.lock` does not
  reopen it either, because `config/config.php` also records the
  installation. For extra tidiness you may delete `public/install/`.
- Set up backups (BACKUP.md) and HTTPS (SECURITY.md).

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Folder listing, host default page, or an extra "LP-System…" folder | Extracted one level too deep, or `index.html` still present | Move the contents up so `index.php` is directly in the site folder; delete `index.html` |
| Requirements: "URL rewriting … not working" | `.htaccess` files missing, or the host ignores them | Upload hidden files; ask the host to enable `mod_rewrite` / `AllowOverride` |
| Requirements: "could not be verified" | The server blocks requests to itself | Usually harmless; if `/login` later says Not Found, see the row above |
| Requirements: folder not writable | Permissions | Set the folder to 755 (or 775) |
| "does not exist, or the user … has not been given access" | Database name typo (include the cPanel prefix), or user not added to the database | cPanel → MySQL Databases → Add User To Database → ALL PRIVILEGES |
| "username or password is incorrect" | Wrong database credentials | Re-type them; reset the DB user's password in cPanel if unsure |
| "contains a users table from a different application" | The database belongs to another app (e.g. WordPress) | Create a new, empty database for LP System |
| Generic "Something went wrong" page with a reference code | An error was logged, not shown (by design) | Look up the reference in `storage/logs/php-error.log` |
| "Update in progress" after uploading a new release | Database updates are pending | An administrator signs in and applies them (UPGRADE.md) |

## Interrupted installation

Steps 1–5 only keep their data in the installer session (the administrator
password is kept only as a bcrypt hash). If the browser closes, start again:
tables created in step 4 are detected and kept, and step 4 only adds what's
missing. Nothing is locked until **Finish** succeeds.

## Reinstalling over an existing LP System database

If you point a fresh copy of the files at a database that already holds LP
System data, step 3 tells you so and asks you to tick a confirmation box.
Nothing is deleted. Missing tables and pending updates are applied, your
existing records stay, and you create an additional administrator. An
administrator email that already exists can't be reused. This is the
recovery path when `config/config.php` is lost but the database is intact.
