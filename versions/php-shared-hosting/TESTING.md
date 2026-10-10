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
- Create Database Tables: **all 19 tables created** (18 from the initial
  schema + the `number_sequences` table), plus seed rows in
  `business_settings`, `workflow_templates` and `workflow_stages`, verified
  via `SHOW TABLES`. (An earlier version of this document said "20" — a
  counting mistake, corrected.)
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
| CSRF — POST without a token | `POST /customers` with no `_csrf` field | Rejected before any DB write ✅. Originally returned 419; under Apache that became a 500 and was changed to 403 — see section 6 |
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

## 6. Release 2 — "extract straight into public_html" layout (real Apache)

**Why this round happened:** the first release ZIP wrapped everything in an
`LP-System-PHP-Shared-Hosting/` folder and only had an `index.php` inside
`public/`. That required pointing the domain's document root at `public/`,
which most cPanel / starter shared-hosting accounts can't do — extracted
into `public_html/`, there was no `index.php` at the web root. This was
reported by the user and is a packaging design flaw, now fixed:

- The ZIP's files now sit at the ZIP root (no wrapper folder).
- A root `index.php` and root `.htaccess` were added; the `.htaccess`
  rewrites every request into `public/`.
- A fresh, not-yet-installed site now redirects to `/install/` (previously
  it only printed a message).
- The installer's requirements step now checks Apache `mod_rewrite` when
  PHP can detect it.

**Setup:** the release ZIP was extracted with `unzip` into an empty
directory owned by the web-server user, and real Apache 2.4 + mod_rewrite +
mod_headers + mod_php 8.3 was configured with that directory as the
document root, mirroring `public_html/` on a cPanel account. MariaDB 10.11,
fresh empty database.

**Results:**

| Check | Result |
|---|---|
| `GET /` on a fresh install | 302 → `/install/` ✅ |
| Installer, all 6 steps | Requirements 13/13 green (incl. mod_rewrite); every step advanced; "Installation Complete"; `config/config.php` and `storage/install.lock` written ✅ |
| `/install/` after install | 403 "already installed" ✅ |
| Workflow script part 1 (login → customer → inquiry → quotation → approve → order) | All steps passed; quotation total ₱1,008.00 as expected ✅ |
| Workflow script part 2 (job order → payment gate → partial payment → confirm → advance → QC) | All steps passed; gate message and ₱504.00 balance as expected ✅ |
| CSS / JS assets | 200, correct content types ✅ |
| `/no-such-page` | 404 ✅ |
| SQL injection payloads on login (logged-out session) | Rejected; `users` table intact ✅ |
| Login throttling | Attempts 1–5 "Invalid", attempt 6 "Too many failed attempts" ✅ |
| `.php` file planted in `public/uploads/` | 403, not executed ✅ |
| Security headers | Present ✅ |
| 20 sensitive paths: `/config/config.php`, `/config/config.sample.php`, `/config/`, `/storage/install.lock`, `/storage/logs/app.log`, `/storage/logs/php-error.log`, `/app/bootstrap.php`, `/app/Services/AuthService.php`, `/database/migrations/001_initial_schema.sql`, `/tests/03_security.sh`, `/README-DEPLOYMENT.md`, `/composer.json`, `/.htaccess`, `/.gitignore`, `/public/../config/config.php`, `/public/install/steps/2.php`, `/install/steps/5.php`, `/install/layout_top.php`, `/index.php/../config/config.php`, `/public/index.php/../../config/config.php` | All 403/404, zero file content in any response ✅ |
| Same install served with document root = `public/` (the other supported layout) | Login, assets, auth redirect, installer lock all correct; `/config/config.php` 404 ✅ |

**Final re-run on the exact released ZIP:** after the fixes below, the final
ZIP was extracted into a wiped directory with a wiped database and every
check above was repeated: installer (including a deliberate wrong DB
password, which showed the real "Access denied" error and let the user
retry), both workflow scripts, logged-in CSRF forgery (403, row count
unchanged), and all 20 sensitive paths. All passed, with zero PHP warnings
logged.

**Bugs found in this round and fixed:**

1. **CSRF rejection returned 500 under Apache.** The guard worked (nothing
   was written, the mismatch was logged), but it answered with status 419,
   which Apache doesn't recognise and turns into a 500. PHP's built-in
   server passes 419 through, which is why sections 3/4 didn't catch it.
   Changed to 403; re-tested as a logged-in user: 403, customer row count
   unchanged.
2. **PHP "Undefined array key" warnings** in the error log when optional
   form fields (service, notes, valid-until, etc.) weren't submitted. Nine
   reads in four controllers, plus two line-item array reads, fixed. After
   the fix, both workflow scripts ran with **zero** new PHP warnings.

**Still not tested:** a real cPanel / Z.com account (only an equivalent
Apache configuration), LiteSpeed, nginx, and the File Manager's own
"Extract" feature (the ZIP was extracted with `unzip`; it's a standard
deflate ZIP with no special features, but File Manager itself wasn't run).
