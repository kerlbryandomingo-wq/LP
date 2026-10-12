# Security Model

Each control below is exercised by the automated QA suite (`tests/qa/`)
against an installation made from the release ZIP; the QA report lists the
individual checks.

## Access control

- **Roles** (`app/Services/Policy.php`), checked on the server in every
  controller action via `AuthService::authorize()`. Hiding a menu link is
  never the protection.
  - **ADMIN**: everything, including users, database updates, payment terms
    and release exceptions.
  - **STAFF**: customers, inquiries, quotations (incl. approval on the
    customer's behalf), orders, payments (record/confirm/reject), production,
    QC, inventory, CSV exports.
  - **PRODUCTION**: production list, job orders, stage changes, QC results,
    inventory (view only). No prices, payments, customers or exports.
  - **CUSTOMER**: refused at sign-in. The customer portal is not part of
    this edition.
- Deactivating a user ends their session on their next request.
- Payment proofs: only roles that may view payments can open them; others
  get the same "not found" response as for a missing file.

## Sign-in and sessions

- Passwords: bcrypt via `password_hash()`; minimum 8 characters with a letter
  and a number; temporary passwords must be changed at first sign-in.
- Throttling, stored in the database (so discarding cookies doesn't reset
  it): 5 failures per email or 30 per IP address within 15 minutes. Uses
  `REMOTE_ADDR` only; `X-Forwarded-For` is ignored because clients can forge
  it.
- Generic "Invalid email or password"; similar response time for unknown
  emails.
- Session cookie: `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, scoped to
  the installation's path (two installs on one domain don't share sessions).
  The session ID is regenerated at sign-in. Idle timeout 240 minutes.
- The installer uses its own cookie (`SameSite=Strict`, installer path only)
  and its own CSRF token.

## Requests and data

- **CSRF**: every POST needs the session token; otherwise 403 and nothing
  changes. Sign-out is POST-only.
- **Duplicate submissions**: create forms carry a one-time token stored in
  the same database transaction as the record, so double clicks and resubmits
  can't create a second customer, quotation, payment, job order or user.
  State changes (approve, confirm, advance) are guarded by status checks under
  row locks.
- **SQL injection**: PDO prepared statements everywhere; search terms escape
  `%` and `_`; list filters accept only known values.
- **XSS**: all output is HTML-escaped. A Content-Security-Policy forbids
  inline and third-party scripts (`script-src 'self'`).
- **Money**: amounts are validated as decimals with at most 2 places and
  stored as `DECIMAL(12,2)`; totals are recomputed on the server; payments
  can't exceed the outstanding balance (checked again at confirmation under
  a lock).
- **CSV exports**: cells starting with `= + - @`, tab or CR get a leading `'`
  so spreadsheets don't run them as formulas.
- **Audit log**: sign-in/out, user changes, quotation/order/payment status
  changes, job order stage changes, QC results, inventory edits, exports,
  database updates and installation.

## Files

- Uploads: content type detected from the file itself (`finfo`), only
  JPEG/PNG/WEBP/PDF, at most 8 MB, random stored name, saved with
  `move_uploaded_file()` into `storage/private-uploads/` (never web-served).
  If saving the payment fails, the stored file is deleted again.
- Downloads go through an authorization check and are sent with `nosniff`
  and a sandboxing CSP.
- `.htaccess`: the root file routes everything into `public/`; `app/`,
  `config/`, `database/` and `storage/` each deny all; hidden files and
  `.md/.sql/.sh/.json/.lock/.log` are denied; only `index.php` may run inside
  `public/`; no directory listings.
- `config/config.php` (database password) is written atomically by the
  installer with mode 0644. It is protected from the web by the rules above.
  On hosts where PHP runs as your own account you may tighten it to 0600.

## Errors

- `display_errors` is off. Visitors see a generic page with a short
  reference code; details go to `storage/logs/php-error.log`.
- Missing `config.php` on an installed site gives a 503 "Configuration
  missing" page and never reopens the installer.
- Headers on every page: `Content-Security-Policy`, `X-Frame-Options: DENY`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`; no
  `X-Powered-By`.

## HTTPS

Enable your host's free certificate (cPanel → SSL/TLS Status → Run AutoSSL)
before going live. If you add HTTPS after installing, set
`'secure_cookie' => true` under `session` in `config/config.php`.

## Known limits

- Rate limiting covers sign-in only.
- No two-factor authentication.
- No object-level customer data separation (there are no customer logins).
- nginx: not tested. If your host uses nginx without Apache, ask them to set
  the document root to `public/` and use:

```nginx
root /path/to/site/public;
index index.php;
location / { try_files $uri $uri/ /index.php?$query_string; }
location = /index.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php-fpm.sock; }
location = /install/index.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php-fpm.sock; }
location ~ \.php$ { deny all; }
location ~ /\. { deny all; }
```
