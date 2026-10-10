# Testing — What Was Actually Run, and the Results

All tests below were run in this development session against a real,
freshly installed MariaDB 10.11 database — not mocked, not assumed. PHP
8.3.6 was used throughout.

## 1. Installer, end-to-end (PHP built-in server)

Drove the full 6-step wizard via scripted HTTP requests (cookies
preserved across steps, matching real browser behavior):

- Welcome → Requirements Check: **12/12 checks passed** (PHP version,
  5 required extensions, writable-directory checks for
  config/storage/storage-logs/storage-private-uploads/public-uploads).
- Database Connection: tested against a real MySQL user/database with
  correct and (separately, not shown here) deliberately wrong credentials
  — wrong credentials correctly surfaced the real PDO error message
  rather than a generic failure.
- Create Database Tables: **all 20 tables created** (18 core tables + 1
  sequence table + the seed data's effect on 2 of those tables), verified
  via `SHOW TABLES`.
  - **Bug found and fixed during this testing**: the first attempt failed
    with a SQL syntax error, because the naive `explode(';', $sql)`
    statement-splitter in the installer broke on a semicolon that
    appeared inside a SQL `--` comment, not as a real statement
    terminator. Fixed by stripping comment lines before splitting. This
    is exactly the kind of bug this testing phase exists to catch —
    documented here rather than silently fixed with no record.
- Create Administrator Account: created, verified via `SELECT * FROM
  users` — correct name/email/role=ADMIN/active=1, password hash present
  and matching bcrypt format.
- Site Settings → Finish: `config/config.php` generated with a random
  64-character app key; `storage/install.lock` created;
  `business_settings.business_name` updated correctly.
- **Reinstall-block verified**: a second request to `/install/` after
  completion returned HTTP 403 with "LP System is already installed."

## 2. Core business workflow, end-to-end (PHP built-in server)

Single continuous session, scripted:

1. Login as the admin account created above → HTTP 302 to `/dashboard`.
2. `/dashboard` loads (HTTP 200).
3. Created a customer ("Juan Dela Cruz").
4. Created an inquiry for that customer ("Tarpaulin Banner", qty 2).
5. Converted the inquiry to a quotation (status NEW → QUOTED).
6. Created a quotation: 2 × ₱500.00 line item, 10% discount, 12% tax.
   **Computed by the server as**: subtotal ₱1,000.00 → discount ₱100.00
   → taxable ₱900.00 → tax ₱108.00 → **total ₱1,008.00**. This matches
   the original TypeScript `computeTotals()` formula by hand
   calculation: `1000 - (1000×0.10) = 900`, `900 × 0.12 = 108`,
   `900 + 108 = 1008`. ✅
7. Approved the quotation, converted it to an order. Order's total
   (₱1,008.00) and pricing breakdown matched the source quotation
   exactly.
8. Order detail page correctly showed Balance = ₱1,008.00, highlighted,
   since Confirmed = ₱0.00.
9. Created a Job Order from the order. Status: ON_HOLD.
10. **Attempted to advance the job order's stage before any payment** →
    correctly **rejected** with: *"On hold: requires 504.00 confirmed
    partial payment (has 0.00), or an approved payment-terms exception."*
    (504.00 = 50% of 1008.00, the default `required_partial_pct`). Status
    remained ON_HOLD. ✅ This is the exact business rule
    (`assertCanStartProduction`) from the original's `lib/workflow.ts`,
    ported and verified.
11. Recorded a ₱504.00 payment (exactly the required 50% partial),
    method CASH.
12. Confirmed the payment as staff. Order balance recalculated to
    ₱504.00 (1008.00 − 504.00). ✅
13. **Re-attempted the stage advance** → succeeded this time (partial
    payment now met). Status moved ON_HOLD → IN_PROGRESS, stage advanced
    to "1. Design." ✅
14. Recorded a QC result ("Color accuracy check", PASS) — appeared
    correctly in the job order's QC checklist table.

## 3. Security tests

| Test | Method | Result |
|---|---|---|
| Unauthenticated access to a protected page | `GET /dashboard` with no session cookie | HTTP 302 → `/login` ✅ |
| Unauthenticated access to a protected file download | `GET /files/{id}` with no session cookie | HTTP 302 → `/login` ✅ |
| CSRF — POST without a token | `POST /customers` with no `_csrf` field | HTTP 419, request rejected before any DB write ✅ |
| SQL injection — classic `' OR '1'='1` in the login email field | Logged-out session, real POST to `/login` | Rejected with "Invalid email or password." `users` table row count unchanged (still 1) ✅ |
| SQL injection — `'; DROP TABLE users; --` in the login email field | Same | Rejected the same way; `users` table still exists and intact afterward ✅ |
| Login throttling | 6 consecutive wrong passwords, same email, same session | Attempts 1-5: "Invalid email or password." Attempt 6: "Too many failed attempts. Please wait a few minutes and try again." ✅ |
| Path traversal in a route parameter | `GET /customers/../../../etc/passwd` | Resolved/normalized to a 404, no filesystem disclosure. Confirmed structurally safe regardless: route parameters are only ever used as SQL bind parameters, never as filesystem paths, except in `UploadService`, which sanitizes with `preg_replace`/`basename` ✅ |

## 4. Real Apache testing (not just PHP's built-in server)

Installed Apache 2.4 + `mod_rewrite` + `mod_headers` + `mod_php` 8.3 in
the development sandbox and configured a VirtualHost pointing at
`public/`, matching a real shared-hosting document-root setup, to verify
`.htaccess` actually works rather than assuming it from reading the file:

- `/login` and `/dashboard` served correctly through `mod_rewrite`'s
  front-controller rule (not PHP's own fallback behavior — confirmed via
  response headers).
- **Bug found and fixed during this testing**: `config/config.php` was
  originally written with permissions `0640`, which caused a 500 error
  (`Permission denied`) under this Apache+mod_php setup because the web
  server process runs as a different system user (`www-data`) than the
  file's owner. Changed to `0644` — HTTP access is still fully blocked by
  `config/.htaccess` regardless of filesystem permissions, verified
  below. This matters because many real hosts (traditional Apache+
  mod_php, as opposed to per-account PHP-FPM/suPHP) have exactly this
  user-mismatch characteristic.
- `GET /config/config.php` directly → HTTP 404 (outside the document
  root; Apache correctly never looks for it there) ✅
- `GET /storage/logs/app.log` directly → HTTP 404 ✅
- Placed a literal `<?php echo 'SHOULD_NOT_EXECUTE'; ?>` file at
  `public/uploads/shell.php` and requested it directly → **HTTP 403
  Forbidden**, not execution output. Confirms the `.htaccess`
  `<FilesMatch>` PHP-execution-denial rule works for real, not just in
  theory. File removed immediately after the test. ✅
- Static assets (`/assets/css/app.css`) served correctly (HTTP 200) ✅
- Security headers (`X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`) present on responses ✅
- Full login → dashboard flow repeated over Apache (not just the PHP
  built-in server) to confirm session handling is identical — HTTP 302
  on login, HTTP 200 + correct dashboard content after ✅

## 5. What was NOT tested (honest gaps)

- **Responsive/visual testing at tablet/mobile widths** was not performed
  with a real browser screenshot tool in this session — the CSS includes
  a mobile breakpoint (sidebar collapses, hamburger menu) matching the
  original's responsive behavior conceptually, but no Playwright/browser
  screenshot comparison was taken for this PHP edition the way earlier
  work on the Next.js app itself did. Treat the mobile layout as
  implemented-but-visually-unverified.
- **Load/performance testing** under realistic concurrent load was not
  performed. The number-sequence and payment-confirmation paths use
  InnoDB row locking correctly by construction (verified functionally,
  not under concurrent load), but no multi-connection stress test was run.
- **A real cPanel account** was not available to test against — the
  Apache test above is the closest practical equivalent achievable in
  this environment, not a substitute for a real hosting account.
- **Email/Messenger/OAuth/exports/reports beyond the dashboard** were not
  tested because they were not ported in this release (see
  FEATURE-PARITY.md).
- **Data migration from a real PostgreSQL database** was not tested —
  no migration script exists yet (see FEATURE-PARITY.md's migration
  procedure section).
