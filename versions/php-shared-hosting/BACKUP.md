# Backup & Recovery

## What to back up

1. **The database** — everything that matters (customers, orders,
   payments, production history) lives here.
2. **`config/config.php`** — your database credentials and app key. Losing
   this without a backup means reconstructing it by hand (possible —
   `config/config.sample.php` shows the exact shape — but your real
   values won't be recoverable from anywhere else).
3. **`storage/private-uploads/`** — payment proofs and other private
   documents. Not in the database; these are files on disk.
4. **`public/uploads/`** — any public assets uploaded through the app.

## How to back up on typical shared hosting

- **Database**: cPanel → phpMyAdmin → select your database → Export →
  "Quick" method, SQL format → download. Most hosts also offer a cPanel
  → Backup Wizard that includes databases automatically on a schedule —
  turn this on if available.
- **Files**: cPanel → File Manager → select `config/config.php`,
  `storage/private-uploads/`, `public/uploads/` → Compress → download the
  resulting `.zip`. Or via FTP, download these three paths directly.
- **Frequency**: at minimum, before every upgrade (see UPGRADE.md) and on
  whatever recurring schedule your host's backup tool supports — daily is
  reasonable for an active business system.

## Restoring

1. **Database**: cPanel → phpMyAdmin → select the (re-created, empty)
   database → Import → choose your `.sql` backup file → Go.
2. **Files**: upload `config/config.php` back to `config/`,
   `storage/private-uploads/` back to `storage/`, `public/uploads/` back
   to `public/`. Overwrite what's there.
3. Confirm `storage/install.lock` still exists (it should, since you're
   restoring an already-installed system) — if the installer reappears,
   something's wrong; do not re-run it against a database that already
   has data: instead, re-create the lock file with any content
   (`echo "restored" > storage/install.lock` via File Manager, or just
   re-upload it from your backup).

## Disaster recovery (starting over)

If you've lost everything (no file backup, no DB backup) you will need to
reinstall from scratch: delete `storage/install.lock`, delete all tables
in the database (or point the installer at a fresh database), and run the
installer again. **All prior business data is unrecoverable in this
scenario** — this is exactly why routine backups matter.
