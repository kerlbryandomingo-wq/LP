# Security Model

## What's implemented and verified in this release

(See TESTING.md for exactly how each of these was tested.)

- **SQL injection**: every single database query in this codebase goes
  through PDO prepared statements with bound parameters — there is no
  string-concatenated SQL anywhere in `app/`. Verified by both code
  review and a live SQL-injection attempt against the login form (see
  TESTING.md) with the `users` table confirmed intact afterward.
- **CSRF**: every state-changing (POST) request requires a per-session
  token (`App\Helpers\Csrf`), verified server-side before any database
  write. A POST missing or mismatching the token is rejected with HTTP
  419 before any business logic runs. Verified live.
- **Session security**: `HttpOnly` always; `Secure` automatically enabled
  when the installer detects HTTPS; `SameSite=Lax`; session ID
  regenerated on every successful login (fixation protection); idle
  timeout (configurable, default 240 minutes).
- **Password security**: `password_hash()`/`password_verify()` with
  bcrypt — the same algorithm family the original Next.js app's
  `bcryptjs` library used, so migrated password hashes keep working with
  no forced reset.
- **Login throttling**: 5 failed attempts per email within a 5-minute
  window locks further attempts for that email until the window expires.
  Verified live (6th attempt in a row is rejected with a clear message).
- **Server-side authorization**: every controller action that should be
  restricted calls `AuthService::requireRole([...])` or
  `AuthService::requireLogin()` — never relies on hiding a button. A
  logged-out request to any protected page redirects to `/login`;
  verified live for the dashboard and the protected-file download route.
- **Private file protection**: payment proofs and other uploaded
  documents are stored in `storage/private-uploads/`, entirely outside
  the public document root, with no static URL that reaches them. The
  only path in is `App\Controllers\UploadController::download()`, which
  requires an authenticated session. **Known gap**: this release checks
  "is logged in," not yet "does this specific customer own the order
  this file belongs to" — see FEATURE-PARITY.md. Do not rely on this for
  multi-customer production use until that object-level check is added.
- **Upload validation**: MIME-type allowlist (JPEG/PNG/WEBP/PDF only,
  checked via `finfo`, not the client-supplied filename/content-type),
  8MB size cap, randomized stored filenames (never the original name or
  anything derived from user input), `move_uploaded_file()` (not
  `rename`/`copy`) to guarantee the source really came from this request.
- **PHP execution denial in uploads**: `public/.htaccess` denies execution
  of `.php`/`.phtml`/etc. anywhere under `public/`, as defense-in-depth on
  top of the MIME-type allowlist above — even if a malicious file somehow
  landed in `public/uploads/`, Apache refuses to execute it. **Verified
  live**: a `.php` file placed in `public/uploads/` returns HTTP 403, not
  execution output (see TESTING.md).
- **Config/secrets never web-reachable**: `config/`, `storage/`,
  `database/`, and `app/` each carry their own deny-all `.htaccess`, on
  top of simply not living under the document root (`public/`) in the
  recommended layout. **Verified live** over real Apache: both
  `config/config.php` and `storage/logs/*.log` return 404 when requested
  directly.
- **No stack traces / SQL errors shown to users**: `display_errors` is
  forced off in `app/bootstrap.php`; all errors go to
  `storage/logs/php-error.log` and `storage/logs/app.log` instead.
- **Security headers**: `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy:
  strict-origin-when-cross-origin` on every response, verified live.
- **Audit logging**: every sensitive action (login, logout, payment
  record/confirm/reject, order cancel, quotation approve/reject, job
  order stage changes) writes a row to `audit_log` via
  `App\Services\AuditService`.
- **File-permission portability**: `config/config.php` is written as
  0644 by the installer — deliberately not tighter, because on a
  traditional Apache+mod_php setup (as opposed to per-user PHP-FPM/suPHP,
  which most cPanel hosts use) the web server process runs as a
  different system user than the file owner, and a tighter mode breaks
  that setup. HTTP access is what actually matters, and that's fully
  blocked by `config/.htaccess` regardless of filesystem permissions —
  this was found and fixed during this release's own Apache testing (see
  TESTING.md).

## HTTPS

Shared-hosting HTTPS is usually provided via your host's free
Let's Encrypt/AutoSSL integration (cPanel: "SSL/TLS Status" → "Run
AutoSSL"). Once enabled, the installer's HTTPS auto-detection sets
`secure_cookie` to `true` for you. If you add HTTPS *after* installing,
edit `config/config.php`'s `session.secure_cookie` to `true` by hand.

## Known gaps (do not claim parity on these — see FEATURE-PARITY.md)

- Object-level authorization (a customer accessing only their own
  records) is not yet implemented — this release's "customer portal" is
  out of scope entirely (staff/admin use only).
- Rate limiting is implemented for login only, not for every endpoint.
- Webhook signature verification (Messenger) is not applicable — the
  Messenger integration itself was not ported in this release.
- No automated dependency-vulnerability scanning is configured (there
  are zero third-party dependencies to scan in this release, which is
  itself a deliberate shared-hosting-compatibility choice).

## Non-Apache hosts (nginx)

If your host uses nginx without an Apache-compatibility layer, ask them to
apply these three rules (nginx doesn't read `.htaccess`):

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location ~* /uploads/.*\.(php|phtml)$ {
    deny all;
}
location ~ ^/(config|storage|database|app)/ {
    deny all;
}
```
