# Feature Parity — Honest Status (Release 3)

"Implemented & tested" means the feature exists with server-side validation
and authorization, and was exercised end-to-end over HTTP against a real
Apache + MariaDB installation made from the release ZIP (see TESTING.md and
the QA report shipped next to the ZIP). "Not ported" means it does not exist
in this codebase at all. There are no stubs or fake screens.

## Implemented & tested

| Area | Notes |
|---|---|
| Browser installer | 6 steps, step gating, CSRF, requirement re-check, plain-language DB errors, existing-database detection, migration tracking, lock |
| Database updates | `schema_migrations` tracking; **Admin → Database Updates** applies new releases' updates; upgrade from the first PHP release tested |
| Sign-in / sign-out / change password | bcrypt (compatible with the original's `bcryptjs` hashes), DB-backed throttling, session fixation protection, forced password change for temporary passwords |
| Roles | ADMIN (everything), STAFF (sales, payments, production, inventory, exports), PRODUCTION (job orders, QC, inventory view; no prices or payments). CUSTOMER accounts are refused at sign-in (portal not ported) |
| User management | Admin creates users with one-time temporary passwords, changes role, deactivates (takes effect on the next request), resets passwords; can't demote/deactivate self or the last admin |
| Customers | Create, edit, search, paginate, detail with orders/quotations/inquiries; admin-only "qualified for payment terms" flag |
| Inquiries | Create, list/filter/search, cancel with reason, convert to quotation (prefilled) |
| Quotations | Line items, percentage/fixed discount, tax, server-side totals (`PricingService::computeTotals`, line-by-line port of `lib/pricing-totals.ts`); edit while DRAFT/SENT/REVISION_REQUESTED; mark sent; staff approval on the customer's behalf with a required reason; record customer rejection / requested changes; cancel and restore; one live quotation per inquiry |
| Orders | Created automatically on approval (port of `lib/quotation-conversion.ts`), one order per quotation (code lock + unique key), payment terms from the customer flag, admin can change terms / grant a release exception, cancel with reason, complete after release, CSV export |
| Payments | Record with optional proof (JPG/PNG/WEBP/PDF ≤ 8 MB), non-future date, reference required for non-cash, never above the outstanding balance, duplicate submission and duplicate reference refused, confirm/reject with re-check under lock, CSV export |
| Production | One job order per line item; first job order created automatically when the 50 % partial is confirmed or terms are approved (as in the original); button-based stages with Undo and stage history; start gated on partial payment, release gated on full payment or release exception (ports of `lib/workflow.ts` rules); stale or double-clicked actions refused |
| QC | Results recorded at the QC stage; the stage can't be passed without a result or while an item's latest result is FAIL |
| Inventory | Create, edit stock and reorder level, low-stock filter |
| Dashboard | Counts, confirmed this month, outstanding balance, recent orders |
| Security controls | See SECURITY.md (CSRF, prepared statements, escaping + CSP, upload checks, private files, headers, audit log) |

## Simplified compared with the original

| Area | Difference |
|---|---|
| Staff permissions | The original grants permissions per staff member; here every STAFF account has the same fixed set (`app/Services/Policy.php`) |
| Numbering | `QUO-/ORD-/JO-YYYY-0001` sequences; the original derives order/JO numbers from the quotation number |
| Services catalogue | No management screen; quotation lines are free text. All job orders use the default 5-stage workflow (Design → Printing → Finishing → Quality Control → Ready) |
| Line-item specs | The `specs` JSON column exists but there is no structured specs form |
| Dashboard | No charts (sales overview, top services, P&L, payment methods) |
| QC | Free-text checklist items; no per-service checklist templates; no REWORK loop status |
| Quotation documents | No printable/PDF quotation or invoice |

## Not ported

These exist only in the original Next.js application:

- Customer portal (customer sign-in, own orders/quotations, online approval)
- Customer forms (secure links, submissions, locking, versions)
- Statements of account
- Expenses, suppliers, supply lots, material consumption, BOM/costing
- Rewards/loyalty points, vouchers
- Google/Facebook sign-in, Messenger integration
- Email sending and in-app/real-time notifications
- Drag-and-drop production board
- Order fulfilment/delivery records, design/proof workflow, installation stage handling
- Global search, scheduled reminders, PDF exports
- Business settings screen (name is set by the installer), theme switching
- Tooling to migrate data from the original PostgreSQL database

## Migrating data from the original PostgreSQL database (procedure only)

No migration script ships with this release, and none was run (no production
data was used). The schema mirrors the Prisma models (same enum values,
`DECIMAL(12,2)` money, `VARCHAR(30)` ids that accept the original cuid
values). Procedure: export each table from a read replica or a restored
dump, import in dependency order (users → customers → services → inquiries →
quotations → quotation_line_items → orders → order_line_items → payments →
workflow tables → job_orders → the rest) into a fresh MySQL database, then
compare row counts, `SUM(total)` of quotations and `SUM(amount)` of
confirmed payments, and spot-check several orders' balances against the
original app before any cut-over.
