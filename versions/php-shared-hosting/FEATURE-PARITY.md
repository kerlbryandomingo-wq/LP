# Feature Parity Checklist — Honest Status

This is not a marketing document. Every row below reflects what was
actually implemented and tested in this release, not what was planned.
"Implemented & tested" means real database operations, validation, and
authorization exist and were exercised end-to-end against a live
MySQL/MariaDB database (see TESTING.md for exact test transcripts).
"Not ported" means the feature does not exist in this PHP codebase at
all — it is not a stub, not a mock, and the original Next.js app is the
only place it currently works.

## Implemented & tested

| Module | Status | Notes |
|---|---|---|
| Authentication (login/logout) | ✅ Implemented & tested | Session-based, bcrypt-compatible with the original's `bcryptjs` hashes, login throttling, session fixation protection |
| Role model (ADMIN/STAFF/PRODUCTION/CUSTOMER) | ✅ Implemented & tested | Server-side `requireRole()` gates on every controller action that needs one |
| Customers (create, list, search, view) | ✅ Implemented & tested | |
| Inquiries (create, list, view, convert to quotation) | ✅ Implemented & tested | Desired-product/service/qty carry-over to the quotation form preserved |
| Quotations (create with line items, discount, tax, send/approve/reject, convert to order) | ✅ Implemented & tested | `PricingService::computeTotals()` is a line-by-line port of the original `lib/pricing-totals.ts`'s `computeTotals()` — same rounding, same clamping. Verified live: a 10%-discount + 12%-tax quotation on ₱1,000 subtotal computed exactly ₱1,008.00 total, matching the original formula by hand-calculation |
| Orders (creation from an approved quotation, cancel) | ✅ Implemented & tested | Pricing breakdown (subtotal/discount/tax) copied from the source quotation exactly, as the original does |
| Payments (manual record with proof upload, staff confirm/reject, balance display) | ✅ Implemented & tested | `PaymentService` is a line-by-line port of the original `lib/workflow.ts`'s `confirmedPaymentTotal()`/`paymentSummary()`. Verified live: recording and confirming a partial payment recalculated the order's balance from ₱1,008.00 to ₱504.00 exactly |
| Concurrency-safe numbering (quotation/order/job-order numbers) | ✅ Implemented & tested | Atomic `INSERT ... ON DUPLICATE KEY UPDATE ... LAST_INSERT_ID()` pattern — verified two sequential calls return 1 then 2 with no gap or collision |
| Production — Job Orders, button-based stage progression, Undo | ✅ Implemented & tested | Payment gates preserved: starting production requires partial payment or an approved-terms exception (Rule #1), releasing requires full payment or an authorized exception (Rule #2) — both verified live, including the exact rejection message format matching the original |
| QC checklist (item-level PASS/FAIL recording) | ✅ Implemented & tested | |
| Inventory (stock level list, low-stock indicator) | ✅ Implemented & tested | Read-only in this release — see "Not ported" for consumption/costing |
| Dashboard (KPI counts) | ✅ Implemented & tested | Basic counts only — see "Not ported" for the full chart/analytics dashboard |
| CSRF protection | ✅ Implemented & tested | |
| SQL injection protection | ✅ Implemented & tested | PDO prepared statements throughout; live injection attempts against login confirmed harmless |
| Private file uploads (payment proofs) | ✅ Implemented & tested | MIME allowlist, size cap, randomized names, stored outside the public document root |
| WordPress-style installer | ✅ Implemented & tested | Full 6-step wizard, tested end-to-end against a live MariaDB database including the self-lock and reinstall-block behavior |
| Apache `.htaccess` security (config/storage/database/app protection, PHP-execution denial in uploads) | ✅ Implemented & tested | Tested over a real installed Apache 2.4 + mod_rewrite + mod_headers + mod_php, not just assumed — see TESTING.md |

## Partially ported (real, but reduced scope)

| Module | Status | What's missing |
|---|---|---|
| Discount/Tax pricing | ✅ Full parity | — |
| Order/Quotation line items | ✅ Full parity on the fields exercised in testing | The original's per-line `specs` JSON (custom per-product spec fields) is stored in the schema but this release's forms don't yet have a UI to populate structured specs — it's always null here. |
| Job Order stage progression semantics | ⚠️ Reasonable reproduction, not a byte-verified port | `PaymentService`/`PricingService` were ported by reading the original source line-by-line. `ProductionService`'s exact stage-transition rules (when QC is entered, when a rework loop occurs) were reconstructed from the original's `WorkflowStage` schema and the two payment-gate rules, but the original's full `completeCurrentStage()` function (~150 lines in `lib/workflow.ts`) was not individually verified line-by-line against this port. The payment gates themselves (the part that matters most for financial correctness) ARE byte-verified. |
| Dashboard | ⚠️ Reduced | The original's KPI sparklines, Sales Overview/Top Services/Service Cost Breakdown/P&L/Payment Methods charts (recent "Whiskey" dashboard work) are not ported — this release shows 4 plain count KPIs and a recent-orders table only. |

## Not ported (documented gap, not a silent omission)

These exist in the original Next.js application and do **not** exist in
this PHP codebase at all:

- **Customer self-service portal** (customers logging in to view their own
  orders/quotations/invoices). This release is staff/admin-facing only.
  Object-level "can this customer see this record" authorization, which
  the original spec correctly flags as critical, was never built because
  there is no customer-facing surface yet to protect.
- **Customer Forms** — secure form links, submission, locking,
  admin-authorized reopening, version history, per-item printed locks.
  None of this exists in this release.
- **Drag-and-drop production Kanban.** Button-based stage progression
  (Advance / Undo) is implemented and tested; the drag-and-drop
  interaction is not.
- **Google/Facebook OAuth login.**
- **Meta Messenger integration** (webhooks, signature verification,
  outbound messages).
- **Email sending** (quotation/order/payment notification emails).
  `config.sample.php` has SMTP settings fields reserved for this, but no
  PHPMailer integration or send logic has been written yet.
- **Server-Sent Events / real-time notifications.** Not attempted in this
  release in any form, including polling.
- **Statement of Account (SOA) / monthly statements.**
- **Expense tracking, Suppliers, Supply Lots, Material Consumption,
  Service BOM/costing.** The `services` table exists with a
  `production_cost` flat field only — the original's full BOM-based
  costing engine (`ServiceBOMMaterial`, `ServiceCostComponent`,
  `JobOrderMaterialConsumption`) was not ported.
- **Rewards/loyalty points redemption flow.** The `reward_points_balance`
  column exists on `customers` (for schema parity) but nothing writes or
  reads it yet.
- **Exports (CSV/PDF) and most reports** beyond the dashboard's basic
  counts.
- **Scheduled/cron reminder tasks.**
- **Data migration tooling from the live PostgreSQL database.** A
  migration *procedure* is documented conceptually below, but no
  Postgres→MySQL export/transform/import script was written or tested
  against real data, because no production data migration was attempted
  per the task's own explicit prohibition on touching production data
  during development.

## Data migration from the original PostgreSQL database — procedure (not yet executable tooling)

This release includes the target MySQL schema (`database/migrations/`)
designed to correspond field-for-field with the equivalent Prisma models,
but does **not** include a working, tested Postgres→MySQL export/import
script. Building and testing one safely requires either a sanitized copy
of real production data or representative synthetic data at realistic
volume, and the task's own rules correctly prohibit touching production
data during development. The procedure, when someone builds this script:

1. Export each Postgres table to CSV (`\copy ... TO ... CSV`) or JSON.
2. Transform: Decimal values preserved as-is (MySQL's `DECIMAL(12,2)`
   matches Postgres's `@db.Decimal(12,2)` precision exactly — no float
   conversion anywhere); enum string values map 1:1 (both schemas use
   the same enum value names); `cuid()` string IDs carry over unchanged
   (this schema's `VARCHAR(30)` primary keys accept them as-is, so
   existing foreign-key relationships are preserved by reusing the same
   ID values rather than regenerating them).
3. Import in dependency order: `users` → `customers` → `services` →
   `inquiries` → `quotations` → `quotation_line_items` → `orders` →
   `order_line_items` → `payments` → `workflow_templates` →
   `workflow_stages` → `job_orders` → everything else.
4. Validate: row counts per table match source exactly; `SUM(total)`
   across quotations and `SUM(amount)` across confirmed payments match
   the source database's equivalent aggregate query; spot-check 5-10
   orders' full payment history and balance against the original app's
   own display of the same orders.
5. Never run this against the live production database directly — export
   from a read replica or a fresh pg_dump restore, import into a fresh
   MySQL database, validate, and only then consider cutover.
