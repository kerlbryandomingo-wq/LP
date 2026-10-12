# LP System PHP Edition — Final QA Report

| | |
|---|---|
| Artifact | `LP-System-PHP-Shared-Hosting-Verified.zip` |
| SHA-256 | `c834ef0defa5fc8d208cfe799f2c7e1e2391dfcd41a37f4ffb0a1bc041a71b22` |
| Size | 143,090 bytes, 135 files |
| Report date | 2026-10-12 |
| Source | `versions/php-shared-hosting/` on branch `shared-hosting-php-migration` |

## Test environment

Isolated Linux container (Ubuntu 24.04.5), nothing exposed to the internet. Apache 2.4.58 with mod_php (prefork, PHP runs as `www-data`), mod_rewrite, mod_headers; PHP 8.3.6; MariaDB 10.11.14; headless Chromium 141 (Playwright `playwright-core` 1.47.2). Each run extracted **this exact ZIP** into a new folder, served it through Apache, and installed it through the browser installer into a new, empty, disposable database with a random database password and a random administrator password (neither recorded anywhere). All test data is fictitious and prefixed `QA` (customer "QA Demo Printing Customer", contact "QA Test Contact", qa-customer@example.test, 09990000000; payment reference QA-PAYMENT-001). No production data, real credentials, real documents or payment gateway were used; no real money moved. The SHA-256 above was re-checked after testing and is unchanged.

## Results

| Run | What it covers | Pass | Fail | Blocked | Not run |
|---|---|---|---|---|---|
| root | Package extracted into the web root (like `public_html/`): full install + workflows + roles + security | 230 | 0 | 0 | 0 |
| subfolder | Package in a subfolder (like `public_html/lpsystem/`): same checks | 230 | 0 | 0 | 0 |
| docroot-public | Document root pointed at `public/`: same checks | 230 | 0 | 0 | 0 |
| reinstall | Fresh copy against another app's DB and against a populated LP DB | 12 | 0 | 0 | 0 |
| upgrade | Previous release with data → upload new files → apply DB updates | 16 | 0 | 0 | 0 |
| failure-config | `config/config.php` missing on an installed site | 4 | 0 | 0 | 0 |
| failure-dbdown | Database server stopped | 8 | 0 | 0 | 0 |
| browser | Installer + workflow in headless Chromium, desktop and 390 px phone width | 25 | 0 | 0 | 0 |
| **Total** | | **755** | **0** | **0** | **0** |

Every check in the table ran against the final ZIP and passed. Checks that could not be run in this environment, and features that do not exist, are listed under "Not run / not verified" — they are not counted above.

## Installation tests A–F (all three layouts)

| Test | Result |
|---|---|
| A First visit | Site redirects to the installer; installer page, CSS and JS load under the correct path in the web root, a subfolder and a `public/` document root |
| B Requirements | All checks pass on a correct server, including a live URL-rewriting probe. **Deliberate failure**: `storage/cache/` made unwritable → shown as FAIL and the server refuses to continue (HTTP 422); after fixing permissions it continues |
| C Database | Wrong password, missing database, out-of-range port, malformed host/name, unreachable server: each refused with a plain-language message; the password is never echoed; no tables created before step 4 |
| D Schema | All 22 tables created (InnoDB, money as `DECIMAL(12,2)`), seed workflow with 5 stages, every migration and seed recorded once, in order, in `schema_migrations` |
| E Administrator | "LP System QA Administrator" / qa-admin@example.test; weak, mismatched and invalid inputs rejected; nothing written until Finish |
| F Completion & lock | `config.php` written atomically (no temp files left, no admin password inside), bcrypt hash stored, lock file written. Afterwards every installer GET/POST returns 403 "already installed"; deleting only the lock file does not reopen it; still exactly one administrator |

Step-skipping (posting step 6 or step 2 directly), forged/missing installer tokens, and a repeat install over an existing LP database (confirmation required, all data kept, existing admin email refused) were also tested.

## Workflows exercised (each layout)

1. Admin signs in → creates a STAFF and a PRODUCTION user (temporary passwords, forced change at first sign-in).
2. Staff creates customer **QA Demo Printing Customer** (contact QA Test Contact, qa-customer@example.test, 09990000000); double submission creates one record; customer edited.
3. Inquiry **QA Test Tarpaulin Printing**, quantity 10 → Convert to Quotation (prefilled) → quotation 10 × ₱500.00, fixed discount ₱100.00, tax 12 % → **subtotal ₱5,000.00, discount ₱100.00, tax ₱588.00, total ₱5,488.00** (stored and displayed). A second quotation with 10 % discount on ₱400.01 confirmed the rounding rules (₱403.21).
4. Quotation edited, marked sent, approved on the customer's behalf with a reason → order ORD-YYYY-0001 created automatically with the same breakdown; double approval / repeat convert never creates a second order; an approved quotation can't be rejected.
5. Payment **QA-PAYMENT-001** ₱1,000.00 by bank transfer with a PNG proof → PENDING; duplicate submission ignored; same reference refused; over-balance, future date, missing reference, negative amount, PHP-disguised-as-PNG and 9 MB uploads refused; Production can't confirm (403) or open the proof; forged CSRF refused; staff confirms once.
6. A rejected payment (with reason) is excluded from the balance; a second payment reaching the 50 % partial (₱2,744.00) creates the job order automatically.
7. Production starts the job order, advances through Design → Printing → Finishing → Quality Control; a stale/double-clicked advance is refused; QC can't be passed with no result or a FAIL; after a passing re-check → READY; release blocked until fully paid; final payment → release → order FULFILLING; Undo and redo; order completed; completed order can't be cancelled or changed. Dashboard outstanding balance returns to ₱0.00.
8. Second order: admin-flagged "qualified for terms" customer → approved terms → job order immediately, production starts unpaid; staff can't grant a release exception (403), admin can (audited); cancellation needs a reason and freezes job orders.
9. Inventory item created, duplicate SKU refused, stock edited (audited), negative stock refused, Production view-only.
10. CSV exports of orders and payments (with filters); a customer named `=HYPERLINK(...)` is exported as text (`'=HYPERLINK`); Production can't export.

## Security checks (each layout)

SQL injection in search, IDs, filters and paging (no errors, no data leaked); stored and reflected XSS escaped, plus a CSP that forbids inline scripts; CSRF on every POST; role checks / IDOR (Production denied 8 staff pages, Staff denied admin pages, proof files by role); session cookie HttpOnly + SameSite=Lax + path-scoped; session ID changes at sign-in; sign-in throttled after 5 failures even with fresh cookies (stored in the database); CUSTOMER-role accounts refused; deactivated users signed out on their next request; uploads checked by content and size and stored outside the web root; a `.php` file planted in `public/` returns 403 and doesn't run; 13 sensitive paths (config, source, SQL, logs, lock file, docs, `.htaccess`, traversal attempts) not downloadable; no directory listings; friendly 404/500 pages with no internals; no PHP warnings and no passwords in the logs; security headers present, `X-Powered-By` removed; DB-down and missing-config pages leak nothing.

## Defects found and fixed in this QA cycle

All fixes were made in the source (`versions/php-shared-hosting/`), the ZIP was rebuilt, re-extracted and fully re-tested.

| # | Severity | Defect in the audited release (`LP-System-PHP-Shared-Hosting.zip`, SHA-256 `e232bed6…8819`) | Fix |
|---|---|---|---|
| 1 | High | Subfolder installs broken (87 root-relative links/redirects; redirected to `/install/` outside the folder) | Base path detected at runtime; every link, redirect, asset and cookie uses it; `.htaccess` rules made folder-relative |
| 2 | Medium | Requirements step could be bypassed by posting the next step | Server-side step gating + requirement re-check on continue |
| 3 | Medium | Posting the final step directly ran it without a database (PHP warnings, raw socket error) | Step gating, installer CSRF token, validated input, plain-language errors |
| 4 | High | Login throttle stored in the session (unlimited guesses by dropping cookies) | Throttle stored in the database per email and per IP |
| 5 | High | Double "Convert to Order" created two orders | Row lock + existing-order check + unique key; conversion idempotent |
| 6 | High | Double-submitted payment recorded twice | One-time form tokens in the same transaction; duplicate reference check; balance re-checked under lock |
| 7 | Medium | Invalid IDs/enums caused HTTP 500 | Validation and 404 pages everywhere |
| 8 | Medium | Approved quotation could be rejected after conversion | All status changes guarded by allowed-from states |
| 9 | High | Production role could see finance and approve quotations; CUSTOMER accounts got the staff UI | Central role policy checked in every action; CUSTOMER sign-in refused |
| 10 | Medium | No user management | Admin → Users (create, role, deactivate, reset password, forced change) |
| 11 | Medium | Lists capped at 100 rows | Search, filters and pagination on every list |
| 12 | Medium | No inventory create, customer edit, quotation edit, order completion or exports | All added |
| 13 | Low | QC never gated the QC stage; proof not viewable | QC gate; proof link with role check |
| 14 | High | *(found while testing the fixes)* QC re-check and Undo could pick the wrong record when two events happened in the same second | Insertion sequence column; ordering by it |
| 15 | High | *(found by the upgrade test)* After uploading the new files over the previous release, nobody could sign in to apply the database update | Sign-in works before updates; admins are sent to Database Updates; others see "Update in progress" |
| 16 | Medium | *(found by the browser test)* Quotation form overflowed by 134 px at phone width | Table scroll container made a positioning context |
| 17 | Low | *(found by the installer test)* "Database does not exist" reported as a privileges problem on MariaDB | Message covers both causes |

## Not run / not verified

| Item | Status | Reason |
|---|---|---|
| Real shared-hosting providers (cPanel File Manager upload, LiteSpeed, PHP-FPM/suPHP, per-user file ownership) | Not run | No hosting account available; emulated with Apache + mod_php |
| HTTPS (`Secure` cookie flag, HTTPS detection) | Not run | Tests used plain HTTP on localhost |
| MySQL 5.7 / 8.x, PHP 8.0–8.2, nginx | Not run | Only MariaDB 10.11 and PHP 8.3 available; nginx rules in SECURITY.md are untested |
| Concurrency beyond sequential double submissions; load | Not run | Out of scope for this environment |
| Customer portal, customer forms, statements of account, expenses/suppliers/costing, reports beyond CSV exports, email/Messenger/notifications, OAuth, drag-and-drop board | Not run | **Not implemented in this edition** (FEATURE-PARITY.md) |
| Payment gateway | Not applicable | The system records manual payments only; no gateway exists |
| Data migration from the original PostgreSQL database | Not run | No migration tooling exists; procedure only (FEATURE-PARITY.md) |

## Known limitations

- Staff permissions are one fixed set per role (the original grants them per user).
- Order and job order numbers use their own sequences (`ORD-/JO-YYYY-0001`).
- No services catalogue screen; all job orders use the default 5-stage workflow.
- Dashboard shows figures only, no charts; no printable quotations/invoices.
- Sign-in is the only rate-limited action; no two-factor authentication.
- During a file upload (upgrade), requests that hit a mix of old and new files can briefly show an error page (UPGRADE.md).

## Installing on cPanel

1. cPanel → MySQL Databases: create a database and a user; Add User To Database with ALL PRIVILEGES.
2. File Manager → `public_html/` (or a subfolder such as `public_html/lpsystem/`) → Upload `LP-System-PHP-Shared-Hosting-Verified.zip` → right-click → Extract into the same folder → delete the ZIP. `index.php`, `public/`, `app/` etc. must sit directly in that folder.
3. Open the site address; the installer opens automatically.
4. Requirements → database details → Create Tables → administrator → site settings → Finish.
5. Sign in, add staff under Admin → Users, enable HTTPS and backups (SECURITY.md, BACKUP.md).

Verify the download before uploading: `sha256sum LP-System-PHP-Shared-Hosting-Verified.zip` (Linux/macOS) or `certutil -hashfile LP-System-PHP-Shared-Hosting-Verified.zip SHA256` (Windows) must print the SHA-256 at the top of this report.

## Reproducing

`versions/php-shared-hosting/tests/qa/run-all.sh` (see TESTING.md) runs every check in this report against any ZIP on a disposable Linux machine.

## Appendix — every check

### Main suite (same checks on each layout)

| Section | ID | Check | Web root | Subfolder | Docroot = public/ |
|---|---|---|---|---|---|
| Test A — first visit | A1 | Home redirects to the installer when not installed | PASS | PASS | PASS |
| Test A — first visit | A2 | Installer welcome page loads (200) | PASS | PASS | PASS |
| Test A — first visit | A3 | Installer stylesheet URL is correct for this layout | PASS | PASS | PASS |
| Test A — first visit | A4 | Stylesheet is served (200, text/css) | PASS | PASS | PASS |
| Test A — first visit | A5 | Script is served (200) | PASS | PASS | PASS |
| Test A — first visit | A6 | Installer form carries a CSRF token | PASS | PASS | PASS |
| Test A — first visit | A7 | Posting the final step without a token is refused (403), no PHP warnings | PASS | PASS | PASS |
| Test A — first visit | A8 | Jumping to step 6 with a valid token is redirected back to step 1 | PASS | PASS | PASS |
| Test A — first visit | A9 | GET of an unreached step redirects to the reached step | PASS | PASS | PASS |
| Test A — first visit | A10 | Skipping the requirements step (POST step 2 before step 1) is refused | PASS | PASS | PASS |
| Test B — requirements | B1 | Requirements page lists checks | PASS | PASS | PASS |
| Test B — requirements | B2 | All required checks pass on a correct server | PASS | PASS | PASS |
| Test B — requirements | B3 | URL-rewriting probe reports "working" | PASS | PASS | PASS |
| Test B — requirements | B4 | Deliberate failure: unwritable storage/cache/ is reported as FAIL | PASS | PASS | PASS |
| Test B — requirements | B5 | Continuing while a requirement fails is refused on the server (422) | PASS | PASS | PASS |
| Test B — requirements | B6 | After fixing permissions the wizard continues to step 3 | PASS | PASS | PASS |
| Test C — database configuration | C1 | Wrong password: clear message, request refused | PASS | PASS | PASS |
| Test C — database configuration | C2 | Wrong password is never echoed back, no raw SQL error | PASS | PASS | PASS |
| Test C — database configuration | C3 | Wrong database name: "does not exist" guidance | PASS | PASS | PASS |
| Test C — database configuration | C4 | Out-of-range port rejected by validation | PASS | PASS | PASS |
| Test C — database configuration | C5 | Malformed host rejected by validation | PASS | PASS | PASS |
| Test C — database configuration | C6 | Malformed database name rejected | PASS | PASS | PASS |
| Test C — database configuration | C7 | Unreachable server: plain-language message | PASS | PASS | PASS |
| Test C — database configuration | C8 | Valid credentials advance to step 4 | PASS | PASS | PASS |
| Test C — database configuration | C9 | Step 4 shows the connected database and server version | PASS | PASS | PASS |
| Test C — database configuration | C10 | No tables created before step 4 is confirmed | PASS | PASS | PASS |
| Test D — schema creation | D1 | Create Tables succeeds and advances to step 5 | PASS | PASS | PASS |
| Test D — schema creation | D2 | Every migration and seed recorded once in schema_migrations (4) | PASS | PASS | PASS |
| Test D — schema creation | D3 | All 22 expected tables exist, no extras | PASS | PASS | PASS |
| Test D — schema creation | D4 | All tables are InnoDB | PASS | PASS | PASS |
| Test D — schema creation | D5 | Money columns are DECIMAL(12,2) | PASS | PASS | PASS |
| Test D — schema creation | D6 | Seed: default workflow has 5 stages incl. one QC stage | PASS | PASS | PASS |
| Test D — schema creation | D7 | Release-3 columns present (sort_order, approved_by_id, reviewed_by_id, order_line_item_id, seq) | PASS | PASS | PASS |
| Test D — schema creation | D8 | No users exist before the administrator step | PASS | PASS | PASS |
| Test E — administrator account | E1 | Too-short password rejected | PASS | PASS | PASS |
| Test E — administrator account | E2 | Password without a number rejected | PASS | PASS | PASS |
| Test E — administrator account | E3 | Mismatched confirmation rejected | PASS | PASS | PASS |
| Test E — administrator account | E4 | Invalid email rejected | PASS | PASS | PASS |
| Test E — administrator account | E5 | Typed password not echoed into the form after an error | PASS | PASS | PASS |
| Test E — administrator account | E6 | Valid administrator details advance to step 6 | PASS | PASS | PASS |
| Test E — administrator account | E7 | Administrator not written to the database until Finish | PASS | PASS | PASS |
| Test F — completion and lock | F1 | Invalid timezone rejected | PASS | PASS | PASS |
| Test F — completion and lock | F2 | Non-http site URL rejected | PASS | PASS | PASS |
| Test F — completion and lock | F3 | Finish shows the completion page | PASS | PASS | PASS |
| Test F — completion and lock | F4 | Completion page links to sign-in under this layout's path | PASS | PASS | PASS |
| Test F — completion and lock | F5 | config/config.php and storage/install.lock created | PASS | PASS | PASS |
| Test F — completion and lock | F6 | config.php holds no administrator password | PASS | PASS | PASS |
| Test F — completion and lock | F7 | No leftover temporary config files | PASS | PASS | PASS |
| Test F — completion and lock | F8 | Administrator saved: name, ADMIN role, active | PASS | PASS | PASS |
| Test F — completion and lock | F9 | Password stored as a bcrypt hash, never plain text | PASS | PASS | PASS |
| Test F — completion and lock | F10 | Admin password not present anywhere in users/audit tables | PASS | PASS | PASS |
| Test F — completion and lock | F11 | Business name saved | PASS | PASS | PASS |
| Test F — completion and lock | F12 | Installer locked after install: GET /install/ → 403 | PASS | PASS | PASS |
| Test F — completion and lock | F12 | Installer locked after install: GET /install/?step=3 → 403 | PASS | PASS | PASS |
| Test F — completion and lock | F12 | Installer locked after install: POST /install/ step 3 → 403 | PASS | PASS | PASS |
| Test F — completion and lock | F12 | Installer locked after install: POST step 6 (new browser) → 403 | PASS | PASS | PASS |
| Test F — completion and lock | F13 | Still exactly one administrator after lock bypass attempts | PASS | PASS | PASS |
| Test F — completion and lock | F14 | Deleting only install.lock does not reopen the installer | PASS | PASS | PASS |
| Authentication and sessions | L1 | Protected page redirects to /login when signed out | PASS | PASS | PASS |
| Authentication and sessions | L2 | Login page renders with assets under the right path | PASS | PASS | PASS |
| Authentication and sessions | L3 | Session cookie: HttpOnly, SameSite=Lax, Path=/ | PASS | — | PASS |
| Authentication and sessions | L4 | Wrong password: 401 with a generic message | PASS | PASS | PASS |
| Authentication and sessions | L5 | Login without CSRF token refused (403) | PASS | PASS | PASS |
| Authentication and sessions | L6 | Correct login (email case-insensitive) redirects to dashboard | PASS | PASS | PASS |
| Authentication and sessions | L7 | Session ID changes at login (session fixation protection) | PASS | PASS | PASS |
| Authentication and sessions | L8 | Dashboard loads for the administrator | PASS | PASS | PASS |
| Authentication and sessions | L9 | Navigation links use this layout's base path | PASS | PASS | PASS |
| Authentication and sessions | L10 | Security headers: CSP, X-Frame-Options, nosniff; no X-Powered-By | PASS | PASS | PASS |
| Authentication and sessions | L11 | Admin-only menu items visible to admin (Users, Database Updates) | PASS | PASS | PASS |
| Authentication and sessions | L12 | Database Updates page: up to date after install | PASS | PASS | PASS |
| Authentication and sessions | L13 | GET /logout does not log out (404; logout is POST+CSRF only) | PASS | PASS | PASS |
| Authentication and sessions | L14 | Login throttled after 5 failures even with new cookies each time | PASS | PASS | PASS |
| Authentication and sessions | L15 | Throttle is stored server-side (login_attempts rows) | PASS | PASS | PASS |
| User management and roles | U1 | Admin creates STAFF and PRODUCTION accounts; one-time temporary passwords shown | PASS | PASS | PASS |
| User management and roles | U2 | New accounts must change password at first sign-in | PASS | PASS | PASS |
| User management and roles | U3 | Duplicate email rejected | PASS | PASS | PASS |
| User management and roles | U4 | Temporary-password user is forced to the change-password page | PASS | PASS | PASS |
| User management and roles | U5 | Change password requires the correct current password | PASS | PASS | PASS |
| User management and roles | U6 | Staff sets own password and continues | PASS | PASS | PASS |
| User management and roles | U7 | Production user sets own password | PASS | PASS | PASS |
| User management and roles | U8 | Production dashboard → production list; no finance links in menu | PASS | PASS | PASS |
| User management and roles | U9 | PRODUCTION denied /orders (403) | PASS | PASS | PASS |
| User management and roles | U9 | PRODUCTION denied /payments (403) | PASS | PASS | PASS |
| User management and roles | U9 | PRODUCTION denied /customers (403) | PASS | PASS | PASS |
| User management and roles | U9 | PRODUCTION denied /quotations (403) | PASS | PASS | PASS |
| User management and roles | U9 | PRODUCTION denied /inquiries (403) | PASS | PASS | PASS |
| User management and roles | U9 | PRODUCTION denied /admin/users (403) | PASS | PASS | PASS |
| User management and roles | U9 | PRODUCTION denied /orders/export (403) | PASS | PASS | PASS |
| User management and roles | U9 | PRODUCTION denied /inventory/new (403) | PASS | PASS | PASS |
| User management and roles | U10 | STAFF denied /admin/users (403) | PASS | PASS | PASS |
| User management and roles | U10 | STAFF denied /admin/updates (403) | PASS | PASS | PASS |
| User management and roles | U11 | Admin cannot change own role or deactivate self (no controls rendered for own row) | PASS | PASS | PASS |
| User management and roles | U12 | Server refuses self-demotion even if posted directly | PASS | PASS | PASS |
| User management and roles | U13 | CUSTOMER-role account cannot sign in (portal not in this edition) | PASS | PASS | PASS |
| User management and roles | U14 | Deactivated user is signed out on their next request | PASS | PASS | PASS |
| Customers | C-1 | Customer validation: bad email rejected, form kept | PASS | PASS | PASS |
| Customers | C-2 | Customer created | PASS | PASS | PASS |
| Customers | C-3 | Double-submitting the same form does not create a second customer | PASS | PASS | PASS |
| Customers | C-4 | Customer number assigned from the sequence (CUS-YYYY-0001) | PASS | PASS | PASS |
| Customers | C-5 | Customer edited; STAFF cannot grant payment terms (admin-only field ignored) | PASS | PASS | PASS |
| Customers | C-6 | Customer search finds the record | PASS | PASS | PASS |
| Customers | C-7 | Search wildcard characters are treated literally ("%" matches nothing) | PASS | PASS | PASS |
| Inquiry → Quotation | Q1 | Inquiry form preselects the customer | PASS | PASS | PASS |
| Inquiry → Quotation | Q2 | Negative quantity rejected | PASS | PASS | PASS |
| Inquiry → Quotation | Q3 | Inquiry created (status NEW) | PASS | PASS | PASS |
| Inquiry → Quotation | Q4 | Convert to Quotation opens a prefilled quotation form | PASS | PASS | PASS |
| Inquiry → Quotation | Q5 | Quotation form prefilled from inquiry (product, qty 10) | PASS | PASS | PASS |
| Inquiry → Quotation | Q6 | Inquiry stays NEW until the quotation is actually saved | PASS | PASS | PASS |
| Inquiry → Quotation | Q7 | Quantity 0 rejected | PASS | PASS | PASS |
| Inquiry → Quotation | Q8 | Non-numeric price rejected | PASS | PASS | PASS |
| Inquiry → Quotation | Q9 | Fixed discount larger than subtotal rejected | PASS | PASS | PASS |
| Inquiry → Quotation | Q10 | Tax above 100% rejected | PASS | PASS | PASS |
| Inquiry → Quotation | Q11 | No line items rejected | PASS | PASS | PASS |
| Inquiry → Quotation | Q12 | Rejected submissions created nothing | PASS | PASS | PASS |
| Inquiry → Quotation | Q13 | Quotation created as DRAFT with number QUO-YYYY-0001 | PASS | PASS | PASS |
| Inquiry → Quotation | Q14 | Totals: subtotal 5000.00, fixed discount 100.00, tax 12% = 588.00, total 5488.00 | PASS | PASS | PASS |
| Inquiry → Quotation | Q15 | Inquiry marked QUOTED | PASS | PASS | PASS |
| Inquiry → Quotation | Q16 | Re-submitting the quotation form does not create a duplicate | PASS | PASS | PASS |
| Inquiry → Quotation | Q17 | Quotation page shows ₱5,488.00 grand total | PASS | PASS | PASS |
| Inquiry → Quotation | Q18 | Draft quotation edited; totals unchanged | PASS | PASS | PASS |
| Inquiry → Quotation | Q19 | Quotation marked SENT | PASS | PASS | PASS |
| Inquiry → Quotation | Q20 | PRODUCTION cannot approve quotations (403) | PASS | PASS | PASS |
| Inquiry → Quotation | Q21 | Approval without a reason refused | PASS | PASS | PASS |
| Inquiry → Quotation | Q22 | Approval creates the order and opens it | PASS | PASS | PASS |
| Inquiry → Quotation | Q23 | Double approval / repeat convert never creates a second order | PASS | PASS | PASS |
| Inquiry → Quotation | Q24 | An approved quotation can no longer be rejected | PASS | PASS | PASS |
| Inquiry → Quotation | Q25 | Approval recorded with staff member and reason | PASS | PASS | PASS |
| Inquiry → Quotation | Q26 | A QUOTED inquiry cannot seed a second quotation form | PASS | PASS | PASS |
| Order and payments | O1 | Order copies the quotation breakdown exactly | PASS | PASS | PASS |
| Order and payments | O2 | Order number ORD-YYYY-0001, status OPEN, standard 50% partial terms | PASS | PASS | PASS |
| Order and payments | O3 | Line items copied to the order | PASS | PASS | PASS |
| Order and payments | O4 | No job order yet — production waits for the partial payment | PASS | PASS | PASS |
| Order and payments | O5 | Order page: balance ₱5,488.00 and Record Payment available | PASS | PASS | PASS |
| Order and payments | P1 | Payment above the outstanding balance refused | PASS | PASS | PASS |
| Order and payments | P2 | Future payment date refused | PASS | PASS | PASS |
| Order and payments | P3 | Non-cash payment without a reference refused | PASS | PASS | PASS |
| Order and payments | P4 | Negative amount refused | PASS | PASS | PASS |
| Order and payments | P5 | PHP disguised as .png rejected by content check | PASS | PASS | PASS |
| Order and payments | P6 | Rejected upload left no file behind | PASS | PASS | PASS |
| Order and payments | P7 | Over-size (9 MB) proof rejected with a clear message | PASS | PASS | PASS |
| Order and payments | P8 | Payment QA-PAYMENT-001 (₱1,000.00, PNG proof) recorded as PENDING | PASS | PASS | PASS |
| Order and payments | P9 | Duplicate submission of the same payment form is ignored | PASS | PASS | PASS |
| Order and payments | P10 | Same reference on a new form refused as a duplicate | PASS | PASS | PASS |
| Order and payments | P11 | Only one stored proof file for the payment | PASS | PASS | PASS |
| Order and payments | P12 | Proof link shown on the order page | PASS | PASS | PASS |
| Order and payments | P13 | Staff can open the proof (image/png, nosniff, sandbox CSP) | PASS | PASS | PASS |
| Order and payments | P14 | PRODUCTION cannot open payment proofs (404) | PASS | PASS | PASS |
| Order and payments | P15 | Signed-out visitor cannot open payment proofs | PASS | PASS | PASS |
| Order and payments | P16 | Uploaded file not reachable by any public URL | PASS | PASS | PASS |
| Order and payments | P17 | PRODUCTION cannot confirm payments (403) | PASS | PASS | PASS |
| Order and payments | P18 | Confirm with a forged CSRF token refused (403) | PASS | PASS | PASS |
| Order and payments | P19 | Payment confirmed once; second confirm reports "already confirmed" | PASS | PASS | PASS |
| Order and payments | P20 | Reviewer recorded on the payment; audit log written | PASS | PASS | PASS |
| Order and payments | P21 | Below the 50% partial (₱2,744.00): still no job order | PASS | PASS | PASS |
| Order and payments | P22 | Payment rejected with a reason; not counted in the balance | PASS | PASS | PASS |
| Order and payments | P23 | Partial reached (₱2,744.00 confirmed): job order created automatically, ON_HOLD | PASS | PASS | PASS |
| Order and payments | P24 | Order page balance now ₱2,744.00 | PASS | PASS | PASS |
| Order and payments | P25 | A second job order for the same line item is refused | PASS | PASS | PASS |
| Production, QC and release | J1 | Production user sees the job order; no peso amounts shown | PASS | PASS | PASS |
| Production, QC and release | J2 | Start Production: ON_HOLD → IN_PROGRESS; order → IN_PRODUCTION | PASS | PASS | PASS |
| Production, QC and release | J3 | Double-click / stale form does not advance twice | PASS | PASS | PASS |
| Production, QC and release | J4 | Stages advance in order to Quality Control (stage 4, status QC) | PASS | PASS | PASS |
| Production, QC and release | J5 | Cannot pass QC without any QC result | PASS | PASS | PASS |
| Production, QC and release | J6 | A failed QC item blocks the next stage | PASS | PASS | PASS |
| Production, QC and release | J7 | After a passing re-check the job order moves to READY | PASS | PASS | PASS |
| Production, QC and release | J8 | Release blocked while the balance is unpaid | PASS | PASS | PASS |
| Production, QC and release | J9 | Fully paid: balance cleared (confirmed ₱5,488.00) | PASS | PASS | PASS |
| Production, QC and release | J10 | Overpayment on a fully paid order refused | PASS | PASS | PASS |
| Production, QC and release | J11 | Release succeeds once paid; order → FULFILLING | PASS | PASS | PASS |
| Production, QC and release | J12 | Undo restores READY and the order back to IN_PRODUCTION | PASS | PASS | PASS |
| Production, QC and release | J13 | Stage history lists every change with the user | PASS | PASS | PASS |
| Production, QC and release | J14 | Order completed; job order COMPLETED | PASS | PASS | PASS |
| Production, QC and release | J15 | Completed order's job order can no longer be changed | PASS | PASS | PASS |
| Production, QC and release | J16 | Completed order cannot be cancelled | PASS | PASS | PASS |
| Production, QC and release | J17 | Dashboard outstanding balance ₱0.00 after full payment | PASS | PASS | PASS |
| Second order: cancellation, release exception, approved terms | S1 | Admin can flag a customer as qualified for payment terms | PASS | PASS | PASS |
| Second order: cancellation, release exception, approved terms | S2 | Percentage discount + tax rounding matches the original formula (400.01 → 403.21) | PASS | PASS | PASS |
| Second order: cancellation, release exception, approved terms | S3 | Qualified customer: order gets APPROVED_TERMS and its first job order immediately | PASS | PASS | PASS |
| Second order: cancellation, release exception, approved terms | S4 | Second job order added for the second line item | PASS | PASS | PASS |
| Second order: cancellation, release exception, approved terms | S5 | Approved terms: production starts with ₱0 paid | PASS | PASS | PASS |
| Second order: cancellation, release exception, approved terms | S6 | STAFF cannot grant a release exception (admin only, 403) | PASS | PASS | PASS |
| Second order: cancellation, release exception, approved terms | S7 | Admin release exception recorded and audited | PASS | PASS | PASS |
| Second order: cancellation, release exception, approved terms | S8 | Cancel without a reason refused | PASS | PASS | PASS |
| Second order: cancellation, release exception, approved terms | S9 | Order cancelled with reason | PASS | PASS | PASS |
| Second order: cancellation, release exception, approved terms | S10 | Cancelled order's job orders are frozen | PASS | PASS | PASS |
| Second order: cancellation, release exception, approved terms | S11 | No Record Payment on a cancelled order | PASS | PASS | PASS |
| Second order: cancellation, release exception, approved terms | S12 | Cancelled order excluded from outstanding balance (still ₱0.00) | PASS | PASS | PASS |
| Inventory | I1 | Inventory item created (SKU normalised to upper case) | PASS | PASS | PASS |
| Inventory | I2 | Duplicate SKU rejected | PASS | PASS | PASS |
| Inventory | I3 | Low-stock filter shows the item flagged Low | PASS | PASS | PASS |
| Inventory | I4 | Stock quantity updated and audited | PASS | PASS | PASS |
| Inventory | I5 | PRODUCTION can view inventory but not edit it | PASS | PASS | PASS |
| Inventory | I6 | Negative stock rejected | PASS | PASS | PASS |
| Exports | X1 | Orders CSV export downloads (text/csv attachment) | PASS | PASS | PASS |
| Exports | X2 | Export contains the QA order with its total and zero balance | PASS | PASS | PASS |
| Exports | X3 | CSV formula injection neutralised (cell starts with ') | PASS | PASS | PASS |
| Exports | X4 | Payments CSV export honours the status filter | PASS | PASS | PASS |
| Exports | X5 | PRODUCTION cannot export (403) | PASS | PASS | PASS |
| Security probes | X-1 | SQL injection in search: no error, no rows leaked | PASS | PASS | PASS |
| Security probes | X-2 | SQL injection in an ID: plain 404 | PASS | PASS | PASS |
| Security probes | X-3 | Invalid filter/page values ignored safely | PASS | PASS | PASS |
| Security probes | X-4 | Stored XSS payload rendered escaped (list) | PASS | PASS | PASS |
| Security probes | X-5 | Stored XSS payload rendered escaped (detail) | PASS | PASS | PASS |
| Security probes | X-6 | Reflected XSS in search box escaped | PASS | PASS | PASS |
| Security probes | X-7 | State change without CSRF token refused (403) | PASS | PASS | PASS |
| Security probes | X-8 | Not downloadable: /config/config.php | PASS | PASS | PASS |
| Security probes | X-8 | Not downloadable: /app/bootstrap.php | PASS | PASS | PASS |
| Security probes | X-8 | Not downloadable: /storage/install.lock | PASS | PASS | PASS |
| Security probes | X-8 | Not downloadable: /storage/logs/php-error.log | PASS | PASS | PASS |
| Security probes | X-8 | Not downloadable: /database/migrations/001_initial_schema.sql | PASS | PASS | PASS |
| Security probes | X-8 | Not downloadable: /.htaccess | PASS | PASS | PASS |
| Security probes | X-8 | Not downloadable: /README-DEPLOYMENT.md | PASS | PASS | PASS |
| Security probes | X-8 | Not downloadable: /composer.json | PASS | PASS | PASS |
| Security probes | X-8 | Not downloadable: /public/index.php | PASS | PASS | PASS |
| Security probes | X-8 | Not downloadable: /app/Views/layouts/app.php | PASS | PASS | PASS |
| Security probes | X-8 | Not downloadable: /assets/../../config/config.php | PASS | PASS | PASS |
| Security probes | X-8 | Not downloadable: /files/..%2F..%2Fconfig%2Fconfig.php | PASS | PASS | PASS |
| Security probes | X-8 | Not downloadable: /index.php/../config/config.php | PASS | PASS | PASS |
| Security probes | X-9 | Unknown page: friendly 404, no internals | PASS | PASS | PASS |
| Security probes | X-10 | Unknown record: 404 inside the app layout | PASS | PASS | PASS |
| Security probes | X-11 | Garbage payment input handled (no 500, nothing stored) | PASS | PASS | PASS |
| Security probes | X-12 | Payment against a non-existent order: 404 | PASS | PASS | PASS |
| Security probes | X-13 | Production list (all) renders | PASS | PASS | PASS |
| Security probes | X-14 | No PHP warnings/notices/fatals logged during the run | PASS | PASS | PASS |
| Security probes | X-15 | No passwords written to the logs | PASS | PASS | PASS |
| Security probes | X-16 | No directory listings | PASS | PASS | PASS |
| Security probes | X-17 | A .php file planted under public/ is not executed | PASS | PASS | PASS |
| Pagination and logout | N1 | Customer list paginates (25 per page, page 2 reachable) | PASS | PASS | PASS |
| Pagination and logout | N2 | Out-of-range page shows an empty list, not an error | PASS | PASS | PASS |
| Pagination and logout | N3 | Logout (POST + CSRF) ends the session | PASS | PASS | PASS |
| Authentication and sessions | L3 | Session cookie: HttpOnly, SameSite=Lax, Path=/lpsystem/ | — | PASS | — |

### reinstall

| ID | Check | Result |
|---|---|---|
| R1 | Database with another application's "users" table is refused | PASS |
| R2 | Existing LP System data detected; confirmation required (409) | PASS |
| R3 | Nothing changed without confirmation | PASS |
| R4 | With confirmation the wizard continues | PASS |
| R5 | Step 4 warns that existing data is kept | PASS |
| R6 | Table step on an up-to-date database succeeds without changes | PASS |
| R7 | Existing administrator email cannot be reused | PASS |
| R8 | Install completes and reports that existing data was kept | PASS |
| R9 | All existing customers, orders and payments preserved | PASS |
| R10 | Exactly one new administrator added | PASS |
| R11 | New administrator sees the existing orders | PASS |
| R12 | Installer locked again afterwards | PASS |

### upgrade

| ID | Check | Result |
|---|---|---|
| G1 | Previous release installed with its own installer | PASS |
| G2 | Previous release has no schema_migrations table | PASS |
| G3 | Data created in the previous release | PASS |
| G4 | New files uploaded; config.php untouched | PASS |
| G5 | Installer stays locked after the upload | PASS |
| G6 | Signed-out visitor is sent to sign-in (no error page) | PASS |
| G7 | Sign-in page works before the database update | PASS |
| G8 | Administrator can sign in before the database update | PASS |
| G9 | Administrator is directed to Database Updates while updates are pending | PASS |
| G10 | Database Updates lists the pending updates | PASS |
| G11 | Applying updates succeeds | PASS |
| G12 | All updates recorded; new tables/columns exist | PASS |
| G13 | Existing data preserved | PASS |
| G14 | Application usable after the update | PASS |
| G15 | New-release features work on the upgraded database (quotation total 5488.00) | PASS |
| G16 | Applying again is harmless ("already up to date") | PASS |

### failure-config

| ID | Check | Result |
|---|---|---|
| M1 | Missing config.php: clear 503 "Configuration missing" page | PASS |
| M2 | No internals leaked | PASS |
| M3 | Installer does NOT reopen over the existing system | PASS |
| M4 | Site works again once config.php is restored | PASS |

### failure-dbdown

| ID | Check | Result |
|---|---|---|
| M4b | Sign-in page still renders while the database is down | PASS |
| M5 | DB down, POST /login: generic error page (500) with a reference code | PASS |
| M6 | DB down, POST /login: no SQL error, path or password shown | PASS |
| M5 | DB down, GET /dashboard: generic error page (500) with a reference code | PASS |
| M6 | DB down, GET /dashboard: no SQL error, path or password shown | PASS |
| M5 | DB down, GET /orders: generic error page (500) with a reference code | PASS |
| M6 | DB down, GET /orders: no SQL error, path or password shown | PASS |
| M7 | Error details written to storage/logs/php-error.log instead | PASS |

### browser

| ID | Check | Result |
|---|---|---|
| W1 | Visiting the site opens the installer | PASS |
| W2 | Stylesheet applied on the installer (brand button colour) | PASS |
| W3 | Requirements all pass | PASS |
| W4 | Wrong DB password shows a readable error | PASS |
| W5 | Timezone defaults to Asia/Manila | PASS |
| W6 | Installation completes in the browser | PASS |
| W7 | Dashboard renders with the sidebar | PASS |
| W8 | Double-clicking "Create Customer" creates one customer | PASS |
| W9 | Live preview: 10 × ₱500 − ₱100 + 12% = 5,488.00 | PASS |
| W10 | "+ Add Line Item" adds a row | PASS |
| W11 | Remove (✕) deletes the extra row | PASS |
| W12 | Saved quotation shows ₱5,488.00 (server total) | PASS |
| W13 | Approve opens a modal dialog | PASS |
| W14 | Escape closes the modal | PASS |
| W15 | Payment modal previews the balance after payment (₱4,488.00) | PASS |
| W16 | Payment with proof recorded as PENDING | PASS |
| W17 | Payment confirmed from the order page | PASS |
| W18 | No horizontal page scroll at 390px: /dashboard | PASS |
| W18 | No horizontal page scroll at 390px: /orders | PASS |
| W18 | No horizontal page scroll at 390px: /quotations/new | PASS |
| W18 | No horizontal page scroll at 390px: /orders/ord_2ed2db3172582d503908e512 | PASS |
| W18 | No horizontal page scroll at 390px: /customers | PASS |
| W18 | No horizontal page scroll at 390px: /admin/users | PASS |
| W19 | Mobile menu opens | PASS |
| W20 | No JavaScript errors, CSP violations or 5xx responses | PASS |
