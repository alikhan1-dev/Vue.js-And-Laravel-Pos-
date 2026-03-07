# Step 5: Payment & Accounting Engine

A fully tenant-aware, POS-integrated payment and accounting module. Handles multiple payment methods per sale, partial payments, and generates double-entry journal entries linked to the general ledger.

## Database

### payments

| Field         | Type          | Notes                                                                 |
|---------------|---------------|-----------------------------------------------------------------------|
| id            | bigint        | PK                                                                    |
| sale_id       | bigint        | FK → sales.id (nullable; for invoice)                                 |
| customer_id   | bigint        | Optional; copied from sale when present. Enables fast customer payment history and statements without joining sales. |
| sale_number   | varchar(32)   | Optional; copied from sale.number when present. Improves reporting and audit clarity (human-readable sale reference). |
| company_id    | bigint        | FK → companies.id                                                     |
| branch_id     | bigint        | FK → branches.id; must equal sale.branch_id when sale_id is set      |
| warehouse_id  | bigint        | FK → warehouses.id (optional)                                         |
| pos_session_id| bigint        | FK → pos_sessions.id (optional). Links payment to POS session for cash management and shift reporting. |
| amount        | decimal(15,2) | Total (sum of lines); negative for refund                             |
| currency_id   | bigint        | FK → currencies.id (nullable; defaults from company)                  |
| exchange_rate | decimal(18,8) | Rate vs base; default 1.00000000                                      |
| rate_source   | varchar(50)   | Optional. Source of exchange rate for multi-currency: e.g. manual, ECB, openexchangerates, fixer. |
| primary_payment_method_id | bigint | FK → payment_methods.id (nullable). Primary method for faster reporting; methods still stored per line in payment_lines. |
| payment_date  | date          | Accounting date of the payment (defaults to created_at date). Use for backdated posting (e.g. payment created tomorrow, recorded for yesterday). |
| payment_number| varchar(50)   | Human-readable code; **unique per company** (DB: unique(company_id, payment_number)), auto-generated like PAY-2026-00001 |
| notes         | text          | Optional notes for cashier/accountant                                  |
| status        | string/enum   | pending, completed, failed, refunded, cancelled                        |
| created_by    | bigint        | FK → users.id                                                         |
| timestamps    |               | created_at, updated_at                                                |

**Indexes:** `sale_id`, `company_id`, `branch_id`, `status`, `created_at`, `customer_id`, `payment_date` (for date-range and customer payment history queries).  
**Unique:** `(company_id, payment_number)` so payment numbers are unique per company.

### payment_lines

| Field       | Type          | Notes                                      |
|------------|---------------|--------------------------------------------|
| id         | bigint        | PK                                         |
| payment_id | bigint        | FK → payments.id                           |
| payment_method_id | bigint | FK → payment_methods.id                    |
| account_id | bigint        | FK → accounts.id (debit account)           |
| amount     | decimal(15,2) | Amount for this method                     |
| reference  | varchar(255)  | Optional (card ref, cheque no, etc.)       |
| description| text          | Optional                                   |
| timestamps |               | created_at, updated_at                     |

### accounts

| Field     | Type          | Notes                          |
|----------|---------------|--------------------------------|
| id       | bigint        | PK                             |
| company_id | bigint      | FK → companies.id              |
| code     | varchar(50)   | Unique per company (e.g. 1000) |
| name     | varchar(255)  | Account name                   |
| type     | string/enum   | asset, liability, equity, income, expense, contra_income |
| parent_id| bigint        | Nullable FK → accounts.id (hierarchy) |
| is_active| boolean       | Default true                   |
| timestamps |               | created_at, updated_at       |

### journal_entries

| Field              | Type          | Notes                    |
|--------------------|---------------|--------------------------|
| id                 | bigint        | PK                       |
| company_id         | bigint        | FK → companies.id        |
| journal_entry_number | varchar(50) | Human-readable JE code (e.g. JE-2026-00001); **unique per company** (DB: unique(company_id, journal_entry_number)). Preferred by accountants for referencing entries. |
| branch_id          | bigint        | FK → branches.id (nullable). Enables branch-level reporting (e.g. Branch Karachi vs Lahore revenue). |
| reference_type     | string        | Sale, Payment, Adjustment, Refund |
| reference_id       | bigint        | Source document id       |
| reference_number   | varchar(50)   | Human-readable ref (e.g. INV-2026-0001, PAY-2026-00005) for audit, ledger reports, and search. |
| entry_type         | string        | sale_posting, payment_receipt, refund, adjustment |
| status             | string        | draft, posted, reversed (default posted) |
| currency_id        | bigint        | FK → currencies.id (nullable) |
| posted_at          | timestamp     | When entry was posted (nullable) |
| is_locked          | boolean       | Once true, lines immutable |
| created_by         | bigint        | FK → users.id            |
| timestamps         |               | created_at, updated_at   |
| deleted_at         | timestamp     | Soft deletes; **never** allow delete when posted/locked (testing/dev only for draft entries). |

**Index:** `branch_id` for branch-level ledger and revenue reports.  
**Unique:** `(company_id, journal_entry_number)` so JE numbers are unique per company (e.g. JE-2026-00023).

### journal_entry_lines

| Field              | Type          | Notes                    |
|--------------------|---------------|--------------------------|
| id                 | bigint        | PK                       |
| journal_entry_id   | bigint        | FK → journal_entries.id  |
| account_id         | bigint        | FK → accounts.id         |
| customer_id        | bigint        | Optional party dimension (customer) |
| supplier_id        | bigint        | Optional party dimension (supplier) |
| type               | enum          | debit, credit            |
| amount             | decimal(15,2) | Line amount              |
| description        | varchar       | Optional line memo       |
| timestamps         |               | created_at, updated_at   |

### currencies

| Field    | Type    | Notes                          |
|----------|---------|--------------------------------|
| id       | bigint  | PK                             |
| code     | string  | 3-letter code (PKR, USD, AED) |
| name     | string  | Currency name                  |
| symbol   | string  | Optional symbol                |
| is_active| boolean | Default true                   |
| timestamps |        | created_at, updated_at        |

### payment_allocations

| Field    | Type          | Notes                                |
|----------|---------------|--------------------------------------|
| id       | bigint        | PK                                   |
| payment_id | bigint      | FK → payments.id                     |
| sale_id  | bigint        | FK → sales.id                        |
| amount   | decimal(15,2) | Part of payment allocated to invoice |
| timestamps |             | created_at, updated_at               |

### customer_ledger

Customer-level ledger for **aging reports**, **credit control**, and **statements**. Tracks every debit (invoice) and credit (payment, refund, adjustment, credit) per customer. Used by ERP-style systems (e.g. SAP, Odoo) for receivables management.

| Field           | Type          | Notes                                                                 |
|-----------------|---------------|-----------------------------------------------------------------------|
| id              | bigint        | PK                                                                   |
| company_id      | bigint        | FK → companies.id                                                     |
| customer_id     | bigint        | FK → customers.id                                                     |
| type            | varchar(20)   | **invoice**, **payment**, **refund**, **adjustment**, **credit**      |
| reference_type  | varchar(50)   | Sale, Payment, SaleReturn, etc.                                       |
| reference_id    | bigint        | ID of source document                                                |
| amount          | decimal(15,2) | **Signed:** + for debit (invoice), − for credit (payment/refund)      |
| balance_after   | decimal(15,2) | Running balance after this entry                                      |
| entry_date      | date          | Accounting date of the entry                                         |
| description     | varchar(255)  | Optional (e.g. "Sale SAL-2026-00001", "Payment PAY-2026-00005")      |
| created_by      | bigint        | FK → users.id (nullable)                                             |
| timestamps      |               | created_at, updated_at                                               |

**Indexes:** `(company_id, customer_id)`, `(customer_id, entry_date)`, `(reference_type, reference_id)`.

**When entries are created (CustomerLedgerService):**

- **invoice** — When a sale is completed and has `customer_id`. Amount = sale `grand_total` (positive). Increases customer balance.
- **payment** — When a payment is completed and is linked to a sale with `customer_id` (or payment has `customer_id`). Amount = −payment amount. Decreases customer balance.
- **refund** — When a payment is refunded (amount positive, decreases balance) or when a **SaleReturn** is completed (postReturnCredit; amount positive). Decreases receivable.

**Use cases:** Aging (outstanding by bucket), credit limit checks, customer statement PDF, dispute resolution.

### sales (relevant columns for payment)

| Field        | Type          | Notes                                                                 |
|--------------|---------------|-----------------------------------------------------------------------|
| paid_amount  | decimal(15,2) | Default 0. Cached total of completed payments; updated on payment/refund. |
| due_amount   | decimal(15,2) | Cached remaining due (total − paid_amount). Updated when paid_amount changes. |

See **Due amount & paid amount (POS performance)** below.

### audit_logs

| Field     | Type    | Notes                               |
|-----------|---------|-------------------------------------|
| id        | bigint  | PK                                  |
| user_id   | bigint  | FK → users.id (nullable)            |
| action    | string  | payment_created, payment_refunded, journal_entry_posted, ... |
| entity_type | string| FQCN (e.g. `App\Models\Payment`)    |
| entity_id | bigint  | ID of entity                        |
| old_values | json   | Previous values (optional)          |
| new_values | json   | New values (optional)               |
| timestamps |        | created_at, updated_at              |

## Due amount & paid amount (POS performance)

- **Sales table** has cached columns to avoid summing payments on every read:
  - **paid_amount** (decimal 15,2, default 0) – Total of completed payments for this sale. Updated when a payment is completed or when a refund is applied.
  - **due_amount** (decimal 15,2) – Remaining due: `total − paid_amount`. Updated whenever `paid_amount` changes (on payment completion or refund).
- **Why:** Computing `sale.total − sum(payments)` for thousands of sales is slow. Large POS systems use these cached columns so list and detail APIs return `due_amount` and `paid_amount` without extra queries.
- **When created:** New sales get `paid_amount = 0`, `due_amount = total`. When a completed payment is recorded against a sale, `paid_amount` is incremented and `due_amount` is set to `total − paid_amount`. On refund, `paid_amount` is decremented and `due_amount` recalculated.
- **API:** GET sale (e.g. `/api/sales/{id}`) includes `due_amount` and `paid_amount` from the database. Use for POS “Amount due” and “Amount paid” display.

## Models & relationships

- **Payment** – Belongs to Sale (nullable), Company, Branch, Warehouse, User, and optionally PrimaryPaymentMethod (`primary_payment_method_id`). Has many PaymentLine and JournalEntry (via reference_type/reference_id). Stores `customer_id`, `sale_number` (from the linked sale when present), and optional `rate_source` (e.g. manual, ECB, openexchangerates, fixer) for multi-currency accounting.
- **PaymentLine** – Belongs to Payment, Account, and PaymentMethod. One line per method (cash, card, etc.) with amount and optional reference.
- **PaymentMethod** – Belongs to Company. Has many PaymentLine. Lets you configure Visa, Mastercard, Bank Transfer, wallets, etc.
- **Account** – Belongs to Company. Can have parent/children for hierarchical chart of accounts. Balances are derived from journal_entry_lines.
- **JournalEntry** – Header only. Belongs to Company, Branch (nullable), and User (creator). Has `journal_entry_number` (e.g. JE-2026-00001, unique per company) and `reference_number` (e.g. INV-2026-0001, PAY-2026-00005). Links to Sale/Payment/Adjustment via reference_type and reference_id. Has many JournalEntryLine. Uses soft deletes; **deletion is blocked when posted/locked**. Append-only once `status = posted` and `is_locked = true`.
- **JournalEntryLine** – Belongs to JournalEntry and Account. Stores debit/credit lines plus optional `customer_id` / `supplier_id` for sub-ledger reporting. Immutable once parent entry is locked.
- **Currency** – Global list of currencies. `payments.currency_id` and `journal_entries.currency_id` point here.
- **PaymentAllocation** – Belongs to Payment and Sale. Represents allocation of a payment amount against one sale/invoice. Currently a payment created with `sale_id` gets a single allocation row, but structure supports splitting across many sales.
- **AuditLog** – Belongs to User. Tracks accounting-sensitive actions (creating payments, posting journal entries, refunds) with before/after snapshots.

## Workflow (accrual accounting)

**Core idea:** Revenue is recognized when the sale is completed (Step 4), not when cash is received. Payments only move balances between Accounts Receivable and Cash/Bank.

1. **Sale posting (Step 4 – Sale Engine)**  
   When a sale becomes completed (direct `type=sale` at creation, or quotation converted to sale), the system posts:  
   - **Dr 1100 – Accounts Receivable**  
   - **Cr 4000 – Sales Revenue**  
   This is a `journal_entries` row with `reference_type = Sale`, `reference_id = sale.id`, `reference_number = sale.number`, `branch_id = sale.branch_id`, `entry_type = sale_posting`. The corresponding `journal_entry_lines` carry `customer_id` from the sale so you can build customer statements and aging.

2. **Create payment (Step 5 – Payment Engine)**  
   Validate sale (if present): **payment.branch_id must equal sale.branch_id** (payments cannot cross branches). Total paid ≤ sale total − already paid (enforced inside a transaction with `lockForUpdate()` on the sale row). Validate each line: account belongs to company and is active, `payment_method_id` exists and is active, amount in range. Create payment with `customer_id` and `sale_number` copied from the sale (when present), and `payment_date` from request or default to today. Create payment_lines. If a `sale_id` is provided, create a `payment_allocations` row linking the payment to that sale (structure supports many-to-many in future). If status = completed: for each payment, create **one journal entry** with **multiple lines**:  
   - **Debit lines:** Cash/Bank/Wallet accounts (one per payment line)  
   - **Credit line:** 1100 – Accounts Receivable (total payment amount)  
   `journal_entries.entry_type = payment_receipt`, `reference_type = Payment`, `reference_id = payment.id`, `reference_number = payment.payment_number`, `branch_id = payment.branch_id`. The linked sale’s `paid_amount` is incremented and `due_amount` updated. Customer id is copied to AR and cash/bank lines to keep the customer sub-ledger consistent.

3. **Partial payments** – Multiple payments per sale; remaining due = `sale.due_amount` (from cached column: total − paid_amount). Overpayment is blocked in the PaymentService with `lockForUpdate()` on the sale row; the check uses `sale.paid_amount`. Future enhancement can split a single payment across multiple sales using `payment_allocations`.

4. **Returns (goods returned, before cash refund)**  
   When a return sale is created in Step 4, the system posts:  
   - **Dr 5000 – Sales Returns (contra income)**  
   - **Cr 1100 – Accounts Receivable**  
   This reduces revenue and the receivable. `entry_type = refund` with `reference_type = Sale`, `reference_id = return_sale.id`, `reference_number = return_sale.number`, `branch_id = return_sale.branch_id`.

5. **Refund (cash back to customer)**  
   POST `/api/payments/{id}/refund` with amount and account_id. Creates a new payment and a journal entry with:  
   - **Dr 5000 – Sales Returns**  
   - **Cr Cash/Bank**  
   `entry_type = refund`, `reference_type = Payment`, `reference_number` and `branch_id` from the refund payment. The original sale’s `paid_amount` is decremented and `due_amount` updated. This reverses part of the original cash receipt.

6. **Status** – Only completed payments post journal entries. Pending/failed/cancelled do not. `journal_entries.status` is set to `posted` and `is_locked = true` for normal postings; future adjustments can use `draft` or `reversed` if needed.

## Validation & business rules

- Amounts: 0.01 ≤ amount ≤ 999,999,999.99.
- Payment status: PHP enum `PaymentStatus` + DB ENUM on `payments.status` (MySQL): `pending`, `completed`, `failed`, `refunded`, `cancelled`.
- Account type: PHP enum `AccountType` + DB ENUM on `accounts.type` (MySQL).
- Accounts and payment methods must belong to the same company as the payment and be active.
- **Payment branch must match sale branch:** When `sale_id` is present, `payment.branch_id` must equal `sale.branch_id`. Payments cannot be recorded against a different branch (e.g. sale for Karachi, payment for Lahore is rejected with 422).
- Cannot overpay a sale (total of completed payments ≤ sale total). This also prevents negative receivables per sale per customer.
- Journal entries are immutable once `status = posted` and `is_locked = true`; corrections via adjustment or refund entries (new rows), never by editing existing ones. **Journal entries use soft deletes** for testing/dev; deletion is **never** allowed when the entry is posted or locked.
- `payments` and `payment_lines` use soft deletes for safety; **only non-completed payments** can be soft-deleted. Completed payments cannot be deleted; they should be reversed via refund or marked with status `cancelled` if never actually processed.
- **payment_date:** Optional on create; defaults to current date. Use for backdated posting (e.g. payment created tomorrow but recorded for yesterday). List filters `date_from` / `date_to` prefer `payment_date` when set, falling back to `created_at` for legacy rows.
- **POS session validation:** When `pos_session_id` is provided, PaymentService validates session exists and is `open`. When `POS_REQUIRE_SESSION` is enabled (see `config/pos.php`), all payments must include `pos_session_id`.
- **Deadlock retry:** PaymentService uses `RetryOnDeadlock` trait — all transactions are wrapped with exponential back-off (up to 3 retries) on MySQL deadlock (SQLSTATE 40001). Critical for high-concurrency POS environments where multiple terminals may pay the same sale simultaneously.
- **Exchange rate at transaction time:** `payments.exchange_rate` captures the rate when the payment is created. Historical rates are never updated. Use `CurrencyRounding` for consistent banker's rounding across monetary calculations.
- **Sale adjustments:** Corrections on completed sales use `sale_adjustments` (not direct edits). Adjustment journal entries reference `SaleAdjustment` and require four-eyes approval. See [Sales Engine](sales-customer-engine.md#sale-adjustments-admin-override).

## API (Vue.js ready)

All under `Authorization: Bearer <token>`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/accounts | List active accounts (id, code, name, type) for dropdowns |
| GET | /api/payment-methods | List active payment methods (id, name, type) for dropdowns |
| GET | /api/payments | List payments. Query: sale_id, payment_method_id, status, branch_id, date_from, date_to (filter by payment_date when set, else created_at), per_page. Indexed by customer_id for customer payment history. |
| POST | /api/payments | Create payment. Body: sale_id?, branch_id, warehouse_id?, payment_date? (date; defaults to today), status?, notes?, currency_id?, exchange_rate?, rate_source? (e.g. manual, ECB, openexchangerates, fixer), lines: [{payment_method_id, account_id, amount, reference?, description?}]. When sale_id is set, branch_id must match the sale’s branch. `primary_payment_method_id` is set from the first line for reporting. |
| GET | /api/payments/{id} | Payment detail with lines, accounts, methods, and journal entries (header + lines) |
| POST | /api/payments/{id}/refund | Refund. Body: amount, account_id |

### Create payment example (multiple methods)

```json
POST /api/payments
{
  "sale_id": 123,
  "branch_id": 1,
  "payment_date": "2026-03-21",
  "lines": [
    { "payment_method_id": 1, "amount": 40, "account_id": 1 },
    { "payment_method_id": 2, "amount": 60, "account_id": 2, "reference": "CARD-987654321" }
  ]
}
```

When `sale_id` is provided, `branch_id` must match the sale’s branch. The payment stores `customer_id` and `sale_number` from the sale for reporting and audit.

Backend creates one payment (amount = 100), one payment_line per method, and **one journal entry** with:  
- Debit 40 to account 1 (Cash)  
- Debit 60 to account 2 (Bank)  
- Credit 100 to 1100 (Accounts Receivable).

## Seeder

Default accounts for the default company:

- 1000 – Cash (asset)  
- 1010 – Bank (asset)  
- 1100 – Accounts Receivable (asset)  
- 4000 – Sales Revenue (income)  
- 5000 – Sales Returns (contra income)  

For the seeded completed sale, the seeder applies full accrual flow:

1. **Sale posting:** Dr 1100 (Accounts Receivable) 499.95, Cr 4000 (Sales Revenue) 499.95.  
2. **Payment:** full payment 499.95 via Cash – Dr 1000 (Cash) 499.95, Cr 1100 (Accounts Receivable) 499.95.

Run: `php artisan migrate --seed` or `php artisan db:seed`.

## Verification

- **Tenant isolation:** Payments and accounts for Company A are not visible to Company B users.
- **Branch rule:** Create a payment with `sale_id` and a `branch_id` different from the sale’s branch → 422 (payment branch must match sale branch).
- **customer_id & sale_number:** After creating a payment against a sale, the payment row has `customer_id` and `sale_number` populated from the sale; list/filter by `customer_id` for customer payment history without joining sales.
- **payment_date:** Create a payment with `payment_date` set to a past or future date; list with `date_from`/`date_to` uses `payment_date` when set.
- **due_amount / paid_amount:** GET a sale (e.g. `/api/sales/{id}`); response includes `due_amount` and `paid_amount` (cached on sales table, updated on payment/refund). Use for POS “amount due” and “amount paid” display.
- **Ledger:** After posting a completed sale, Accounts Receivable and Sales Revenue update. After creating a payment, Cash/Bank and Accounts Receivable update (receivable goes down). Refund decreases Cash/Bank and increases Sales Returns.
- **Partial payments:** Remaining due = `sale.due_amount` (cached column); overpay is rejected.
- **API:** Vue.js can list accounts, create payments with multiple methods (and optional `payment_date`), show payment detail with journal entries (including `entry_type`), and call refund.

## Performance

- **Payment number:** Unique constraint `(company_id, payment_number)` on `payments` ensures no duplicate numbers per company.
- **Due amount:** Cached `paid_amount` and `due_amount` on `sales` avoid `SUM(payments)` on every read; updated when payments are completed or refunded. Essential for fast POS list and detail views.
- **Deadlock recovery:** `RetryOnDeadlock` trait ensures transient deadlocks in high-concurrency payment flows are retried transparently (up to 3 attempts, exponential back-off starting at 50ms).
- Indexes on payments: sale_id, company_id, branch_id, status, created_at, customer_id, payment_date.
- Indexes on journal_entries: company_id, reference_type, reference_id, entry_type, posted_at, branch_id.
- Indexes on journal_entry_lines: journal_entry_id, account_id, customer_id, supplier_id.
- For high volume, consider cached balance totals or materialized views; batch journal entry inserts if processing bulk payments and reporting on account or customer balances.
- **Future:** For extreme-scale POS, consider async payment/refund queue to reduce lock contention (see Sales Engine future improvements).
