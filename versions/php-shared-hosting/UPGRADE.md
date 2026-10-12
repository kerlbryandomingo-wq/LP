# Upgrading LP System (PHP/MySQL Edition)

Upgrades are a file upload followed by one click under **Admin → Database
Updates**. No phpMyAdmin or SQL is needed.

## Before you start

1. **Back up** the database and the files listed in BACKUP.md.
2. Pick a quiet time. While files are being uploaded, a page request can
   meet a mix of old and new files and show an error page. This stops by
   itself once the upload finishes; nothing is saved half-way.

## Steps

1. Extract the new release ZIP on your computer (or in an empty folder on the
   server).
2. Upload its contents **over** the existing installation, including hidden
   `.htaccess` files, overwriting existing files. The new ZIP contains no
   `config/config.php` and no `storage/install.lock`, and its `storage/`
   holds only empty placeholder files, so uploading it does not touch your
   settings, uploads or lock file. (Do not delete `config/` or `storage/`.)
3. Sign in as an administrator. If the release contains database updates,
   you are taken to **Admin → Database Updates** automatically, and other
   users see a short "Update in progress" page until you finish.
4. Click **Apply Updates**. Each update is recorded in `schema_migrations`
   and is applied only once. Clicking again later just reports "already up
   to date".
5. Check that your data is there and the site works.

### Upgrading from the first PHP release (before Release 3)

That release had no `schema_migrations` table. The first **Apply Updates**
re-checks every table (existing tables and rows are kept: all statements are
`CREATE TABLE IF NOT EXISTS` / insert-if-missing) and then adds the Release 3
tables and columns. This exact path was tested: previous release installed
with data → new files uploaded → admin signs in → updates applied → data
intact, new features working (TESTING.md).

One Release 3 update adds a database rule of **one order per quotation**. If
a database from the first release contains duplicate orders for the same
quotation (possible there through a double click), that update stops with an
error and nothing further is applied. Cancel or merge the duplicate orders,
then click **Apply Updates** again.

## If something goes wrong

The update page reports failures without changing anything further; details
go to `storage/logs/php-error.log`. Restore the backup from step "Before you
start" (database and files) to return exactly to where you were.
