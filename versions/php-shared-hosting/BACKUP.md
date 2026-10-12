# Backup & Recovery

## What to back up

1. **The database** — everything that matters (customers, orders,
   payments, production history) lives here.
2. **`config/config.php`** — your database credentials and app key. Losing
   this without a backup means reconstructing it by hand (possible —
   `config/config.sample.php` shows the exact shape — but your real
   values won't be recoverable from anywhere else).
3. **`storage/private-uploads/`** — payment proofs. Not in the database;
   these are files on disk.

## How to back up on typical shared hosting

- **Database**: cPanel → phpMyAdmin → select your database → Export →
  "Quick" method, SQL format → download. Most hosts also offer a cPanel
  → Backup Wizard that includes databases automatically on a schedule —
  turn this on if available.
- **Files**: cPanel → File Manager → select `config/config.php` and
  `storage/private-uploads/` → Compress → download the resulting `.zip`.
  Or via FTP, download these two paths directly.
- **Frequency**: at minimum, before every upgrade (see UPGRADE.md) and on
  whatever recurring schedule your host's backup tool supports — daily is
  reasonable for an active business system.

## Restoring

1. **Database**: cPanel → phpMyAdmin → select the (re-created, empty)
   database → Import → choose your `.sql` backup file → Go.
2. **Files**: upload `config/config.php` back to `config/` and
   `storage/private-uploads/` back to `storage/`. Overwrite what's there.
3. The installer stays locked as long as `config/config.php` (restored in
   step 2) is present.

**Lost `config/config.php` but the database is fine?** Upload a fresh copy
of the release, run the installer, enter the same database, and tick the
"keep existing data" confirmation (INSTALL.md → "Reinstalling"). Nothing is
deleted; you create a new administrator and carry on.

## Disaster recovery (starting over)

If you've lost everything (no file backup, no DB backup) you will need to
reinstall from scratch into a new, empty database. **All prior business data is unrecoverable in this
scenario** — this is exactly why routine backups matter.
