# Tests

These are the exact scripts used to validate this release (see
`../TESTING.md` for the recorded results). They are plain bash + curl —
no PHPUnit/Composer dependency, consistent with this project's
no-required-dependencies approach.

## Running them

1. Install the app against a **disposable, non-production** database
   (either run the installer manually first, or point these scripts at
   an environment where you've already done so).
2. Start the app with PHP's built-in server for local testing:
   ```
   cd public && php -S 127.0.0.1:8900
   ```
3. From the `php-shared-hosting/` directory:
   ```
   bash tests/01_workflow_part1.sh
   ```
   This prints the order ID it created — pass it to part 2:
   ```
   bash tests/02_workflow_part2.sh <order_id_from_part1>
   bash tests/03_security.sh
   ```

`tests/tmp/` is scratch space these scripts write intermediate HTML/
header dumps to — safe to clear between runs, already excluded from
version control.

## What they cover

- `01_workflow_part1.sh` — login, customer creation, inquiry creation,
  inquiry→quotation conversion, quotation line items + discount/tax
  totals (verifies the server-computed total matches the expected
  formula), quotation approval, quotation→order conversion.
- `02_workflow_part2.sh` — job order creation, the payment-gate rule
  (advancing a stage before partial payment is paid must be rejected),
  payment recording + confirmation, balance recalculation, the
  now-permitted stage advance, QC result recording.
- `03_security.sh` — unauthenticated access rejection, CSRF rejection,
  SQL injection attempts against login, login throttling after repeated
  failures, path-traversal safety.

These were also re-run once against a real installed Apache + mod_php
(not just PHP's built-in server) during development — see TESTING.md
section 4 for that transcript, which isn't scripted here since it
required a one-off VirtualHost configuration outside this project's own
files.
