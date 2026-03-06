# Step 5: Payment & Accounting Engine

A fully tenant-aware, POS-integrated payment and accounting module. Handles multiple payment methods per sale, partial payments, and generates double-entry journal entries linked to the general ledger.

## Database

### payments

| Field        | Type          | Notes                                    |
|-------------|---------------|------------------------------------------|
| id          | bigint        | PK                                       |
| sale_id     | bigint        | FK → sales.id (nullable; for invoice)    |
| company_id  | bigint        | FK → companies.id                        |
| branch_id   | bigint        | FK → branches.id                         |
| warehouse_id| bigint        | FK → warehouses.id (optional)            |
| amount      | decimal(15,2) | Total (sum of lines); negative for refund|
| currency_id | bigint        | FK → currencies.id (nullable; defaults from company) |
| exchange_rate | decimal(18,8) | Rate vs base; default 1.00000000        |
| payment_number | varchar(50)| Human-readable code (unique per company), auto-generated like PAY-2026-00001 |
| notes       | text          | Optional notes for cashier/accountant    |
| status      | string/enum   | pending, completed, failed, refunded, cancelled |
| created_by  | bigint        | FK → users.id                            |
| timestamps  |               | created_at, updated_at                   |

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
| reference_type     | string        | Sale, Payment, Adjustment, Refund |
| reference_id       | bigint        | Source document id       |
| entry_type         | string        | sale_posting, payment_receipt, refund, adjustment |
| status             | string        | draft, posted, reversed (default posted) |
| currency_id        | bigint        | FK → currencies.id (nullable) |
| posted_at          | timestamp     | When entry was posted (nullable) |
| is_locked          | boolean       | Once true, lines immutable |
| created_by         | bigint        | FK → users.id            |
| timestamps         |               | created_at, updated_at   |

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

## Models & relationships

- **Payment** – Belongs to Sale (nullable), Company, Branch, Warehouse, User. Has many PaymentLine and JournalEntry (via reference_type/reference_id).
- **PaymentLine** – Belongs to Payment, Account, and PaymentMethod. One line per method (cash, card, etc.) with amount and optional reference.
- **PaymentMethod** – Belongs to Company. Has many PaymentLine. Lets you configure Visa, Mastercard, Bank Transfer, wallets, etc.
- **Account** – Belongs to Company. Can have parent/children for hierarchical chart of accounts. Balances are derived from journal_entry_lines.
- **JournalEntry** – Header only. Belongs to Company and User (creator). Links to Sale/Payment/Adjustment via reference_type and reference_id. Has many JournalEntryLine. Append-only once `status = posted` and `is_locked = true`.
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
   This is a `journal_entries` row with `reference_type = Sale`, `reference_id = sale.id`, `entry_type = sale_posting`. The corresponding `journal_entry_lines` carry `customer_id` from the sale so you can build customer statements and aging.

2. **Create payment (Step 5 – Payment Engine)**  
   Validate sale (if present): total paid ≤ sale total − already paid (enforced inside a transaction with `lockForUpdate()` on the sale row). Validate each line: account belongs to company and is active, `payment_method_id` exists and is active, amount in range. Create payment and payment_lines. If a `sale_id` is provided, create a `payment_allocations` row linking the payment to that sale (structure supports many-to-many in future). If status = completed: for each payment, create **one journal entry** with **multiple lines**:  
   - **Debit lines:** Cash/Bank/Wallet accounts (one per payment line)  
   - **Credit line:** 1100 – Accounts Receivable (total payment amount)  
   `journal_entries.entry_type = payment_receipt`, `reference_type = Payment`, `reference_id = payment.id`. Customer id is copied to AR and cash/bank lines to keep the customer sub-ledger consistent.

3. **Partial payments** – Multiple payments per sale; remaining due = sale total − sum(completed payments). Overpayment is blocked in the PaymentService with `lockForUpdate()` on the sale row. Future enhancement can split a single payment across multiple sales using `payment_allocations`.

4. **Returns (goods returned, before cash refund)**  
   When a return sale is created in Step 4, the system posts:  
   - **Dr 5000 – Sales Returns (contra income)**  
   - **Cr 1100 – Accounts Receivable**  
   This reduces revenue and the receivable. `entry_type = refund` with `reference_type = Sale`, `reference_id = return_sale.id`.

5. **Refund (cash back to customer)**  
   POST `/api/payments/{id}/refund` with amount and account_id. Creates a new payment and a journal entry with:  
   - **Dr 5000 – Sales Returns**  
   - **Cr Cash/Bank**  
   `entry_type = refund`, `reference_type = Payment`. This reverses part of the original cash receipt.

6. **Status** – Only completed payments post journal entries. Pending/failed/cancelled do not. `journal_entries.status` is set to `posted` and `is_locked = true` for normal postings; future adjustments can use `draft` or `reversed` if needed.

## Validation & business rules

- Amounts: 0.01 ≤ amount ≤ 999,999,999.99.
- Payment status: PHP enum `PaymentStatus` + DB ENUM on `payments.status` (MySQL): `pending`, `completed`, `failed`, `refunded`, `cancelled`.
- Account type: PHP enum `AccountType` + DB ENUM on `accounts.type` (MySQL).
- Accounts and payment methods must belong to the same company as the payment and be active.
- Cannot overpay a sale (total of completed payments ≤ sale total). This also prevents negative receivables per sale per customer.
- Journal entries are immutable once `status = posted` and `is_locked = true`; corrections via adjustment or refund entries (new rows), never by editing existing ones.
- `payments` and `payment_lines` use soft deletes for safety; **only non-completed payments** can be soft-deleted. Completed payments cannot be deleted; they should be reversed via refund or marked with status `cancelled` if never actually processed.

## API (Vue.js ready)

All under `Authorization: Bearer <token>`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/accounts | List active accounts (id, code, name, type) for dropdowns |
| GET | /api/payment-methods | List active payment methods (id, name, type) for dropdowns |
| GET | /api/payments | List payments. Query: sale_id, payment_method_id, status, branch_id, date_from, date_to, per_page |
| POST | /api/payments | Create payment. Body: sale_id?, branch_id, warehouse_id?, status?, notes?, lines: [{payment_method_id, account_id, amount, reference?, description?}] |
| GET | /api/payments/{id} | Payment detail with lines, accounts, methods, and journal entries (header + lines) |
| POST | /api/payments/{id}/refund | Refund. Body: amount, account_id |

### Create payment example (multiple methods)

```json
POST /api/payments
{
  "sale_id": 123,
  "branch_id": 1,
  "lines": [
    { "payment_method_id": 1, "amount": 40, "account_id": 1 },
    { "payment_method_id": 2, "amount": 60, "account_id": 2, "reference": "CARD-987654321" }
  ]
}
```

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
- **Ledger:** After posting a completed sale, Accounts Receivable and Sales Revenue update. After creating a payment, Cash/Bank and Accounts Receivable update (receivable goes down). Refund decreases Cash/Bank and increases Sales Returns.
- **Partial payments:** Remaining due = sale total − sum(completed payments for that sale); overpay is rejected.
- **API:** Vue.js can list accounts, create payments with multiple methods, show payment detail with journal entries (including `entry_type`), and call refund.

## Performance

- Indexes on payments: sale_id, company_id, branch_id, status, created_at.
- Indexes on journal_entries: company_id, reference_type, reference_id, entry_type, posted_at.
- Indexes on journal_entry_lines: journal_entry_id, account_id, customer_id, supplier_id.
- For high volume, consider cached balance totals or materialized views; batch journal entry inserts if processing bulk payments and reporting on account or customer balances.
