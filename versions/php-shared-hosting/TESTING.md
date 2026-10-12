# Testing

Release 3 was tested by installing the **release ZIP itself** on a real
Apache 2.4 + mod_php + MariaDB server, through the browser installer, in
every supported layout, and then driving the application over HTTP and in a
headless Chromium browser. The full per-check results for the shipped ZIP are
in the QA report published next to it (`dist/LP-System-PHP-Shared-Hosting-QA-Report.md`).

## What the suite covers

| Script | Scenario |
|---|---|
| `tests/qa/run.php` | One fresh copy per layout (web root, subfolder `/lpsystem/`, document root = `public/`): installer tests A–F incl. a deliberate requirement failure, bad DB credentials, step-skipping and lock bypass attempts; schema and migration records; sign-in, cookies, session fixation, throttling; user management and the three roles; customers → inquiry → quotation (10 × ₱500, ₱100 fixed discount, 12 % tax = ₱5,488.00) → approval → order → payments (`QA-PAYMENT-001`, duplicate submission, duplicate reference, over-balance, future date, bad and oversize uploads, proof access by role) → automatic job order → stages, QC gate, release gate, Undo, completion; cancellation, approved terms, release exception; inventory; CSV exports incl. formula injection; SQL injection, XSS, CSRF, IDOR, protected paths, traversal, planted PHP, error pages, log hygiene; pagination; sign-out |
| `tests/qa/reinstall.php` | Fresh copy pointed at a database with another app's `users` table (refused) and at a populated LP database (confirmation required, data kept) |
| `tests/qa/upgrade.php` | Previous PHP release installed with data → new files uploaded over it → admin applies database updates → data intact, new features work |
| `tests/qa/failure-modes.php` | `config.php` missing on an installed site; database server stopped |
| `tests/qa/browser.mjs` | Installer and main workflow in headless Chromium: CSS applied, modals, live quotation totals, payment balance preview, double-click guard, no JavaScript/CSP errors, no horizontal scrolling at 390 px |

All data is fictitious and prefixed `QA`. Every run creates its own empty
databases with a random database password and a random administrator
password, which are never printed or saved.

## Running it

On a disposable Linux machine (it reconfigures Apache and creates databases):

```bash
sudo tests/qa/run-all.sh LP-System-PHP-Shared-Hosting-Verified.zip /tmp/lpqa-results [PREVIOUS_RELEASE.zip]
# optional browser checks: npm i playwright-core in some folder, then
sudo QA_PLAYWRIGHT_DIR=/that/folder CHROME=/path/to/chrome-headless-shell tests/qa/run-all.sh …
sudo tests/qa/run-all.sh --cleanup
```

Results are written as `results-*.json` and `log-*.txt` (plus screenshots
for the browser run) in the output folder.

## Not covered

- Real shared-hosting providers (cPanel, LiteSpeed, PHP-FPM/suPHP): the tests
  emulate them with Apache + mod_php; behaviour on a specific host is not
  verified.
- nginx, MySQL (only MariaDB 10.11 was used), PHP versions other than 8.3.
- HTTPS (all tests ran over plain HTTP on localhost; the `Secure` cookie
  flag on HTTPS was not exercised).
- Load, concurrency beyond sequential double submissions, and long-running
  operation; email delivery (not implemented).
