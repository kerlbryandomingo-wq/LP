# Upgrading LP System (PHP/MySQL Edition)

There is no automatic updater in this release — upgrades are a manual
file-replace + migration step, which is the safest approach on shared
hosting where you may not have shell access.

## Before any upgrade

1. **Back up first.** Follow BACKUP.md completely — database dump AND a
   copy of `config/config.php`, `storage/private-uploads/`, and
   `public/uploads/` — before touching anything.
2. Note your current version (check `CHANGELOG.md` if the release you're
   upgrading from included one, or the release ZIP's filename/date).

## Upgrade steps

1. Download the new release ZIP.
2. **Do not overwrite `config/config.php`.** Extract the new release to a
   separate folder first, then copy files over, excluding:
   - `config/config.php` (keep your existing one — it has your real DB
     credentials and app key)
   - `storage/install.lock` (keep it — you do not want the installer to
     reappear)
   - `storage/private-uploads/`, `public/uploads/` (your real uploaded
     files — never overwrite these with the empty ones from the release)
3. Copy the new release's `app/`, `public/assets/`, `public/index.php`,
   `public/.htaccess`, and `database/migrations/` over the old ones.
4. **Run any new migration files.** Check `database/migrations/` for
   files numbered higher than the last one you applied (e.g. if you're on
   `003_...sql` and the new release includes `004_...sql`, run only
   `004_...sql` — never re-run old ones against a live database that
   already has that schema). Run it the same way the installer does: via
   phpMyAdmin's SQL tab, or `mysql -u youruser -p yourdb < 004_....sql` if
   you have shell/SSH access. There is no browser-based "run pending
   migrations" button in this release — review BACKUP.md's restore path
   first and apply migrations by hand with a fresh backup in place.
5. Reload the site and confirm you can log in and see your existing data.

## If something breaks mid-upgrade

Restore the backup you took in step 1 — both the database dump and the
file copies. Because you never overwrote `config/config.php` with a
different one, a clean restore puts you back exactly where you started.
