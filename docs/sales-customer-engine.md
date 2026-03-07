# Step 8: Sales & Customer Engine

A multi-tenant Sales & Customer Engine that manages customers, sales (orders), POS checkout, discounts, returns, and payment linkage. Integrates with the Inventory Engine (stock movements), Payments Engine (payments and allocations), and is ready for a future Tax Engine.

---

## Overview

The Sales & Customer Engine follows the professional architecture used by systems like Shopify POS, Odoo, and SAP:

- **Orders ≠ Payments** — A sale has its own `status` (draft, pending, completed, cancelled, refunded) and a separate `payment_status` (unpaid, partial, paid, refunded). **Payment status is derived only** — updated by PaymentService on payment/refund; never directly editable.
- **Inventory-first** — Every sale is tied to a `warehouse_id` for stock deduction, reservation, and return validation. Line-level `warehouse_id` must satisfy **warehouse.branch_id = sale.branch_id**.
- **Multi-tenant** — All records are scoped by `company_id`. Child tables (`sale_lines`, `sale_discounts`, `customer_addresses`, `sale_return_items`) also carry `company_id` for row-level filtering and faster queries.
- **Single return system** — Returns use **sale_returns** and **sale_return_items** only. The `sales` table holds only **sale** and **quotation** (no `type = return`); no `return_for_sale_id` on sales.

### High-level workflow

```
POS creates order (draft)     →  sale.status = draft, no stock change
       ↓
Checkout: add items, discounts, tax
       ↓
POST /sales/{id}/complete     →  stock validated, sale_out movements, status = completed
       ↓
Payment(s) attached           →  payment_status updated by PaymentService only (unpaid → partial | paid)
       ↓
Optional return               →  sale_returns + sale_return_items, ReturnIn movements
```

---

## Core design principles

### 1. Multi-tenant safety

Every record includes `company_id` where applicable:

- **customers:** `company_id`
- **sales:** `company_id`, `branch_id`, `warehouse_id`
- **sale_lines:** `company_id` (duplicated from sale for row-level filtering)
- **sale_discounts:** `company_id`
- **customer_addresses:** `company_id`
- **sale_returns:** `company_id`, `branch_id`, `warehouse_id`
- **sale_return_items:** `company_id`
- **stock_movements:** `company_id` (inventory is tenant-scoped; movement’s company_id set from product or context)

Enforced via Laravel global scopes and validation (e.g. sale’s `company_id` must match customer’s `company_id` when `customer_id` is set).

### 2. Inventory-first design

- **sales.warehouse_id** — Warehouse from which stock is deducted (or reserved for quotations).
- **sale_lines.warehouse_id** — Optional line-level warehouse; **must belong to sale’s branch** (`line.warehouse.branch_id == sale.branch_id`). Validated in SaleService.

### 3. Orders ≠ payments | Payment status is derived

- **sale.status** — Lifecycle: `draft` → `pending` | `completed` | `cancelled` | `refunded`.
- **sale.payment_status** — **Never directly editable.** Only updated by:
  - **PaymentService** when a payment is completed or refunded.
  - **SaleService** when completing a draft (recalculated from `paid_amount` vs `grand_total`).

Cached amounts: `paid_amount`, `due_amount`; `payment_status` is derived from these to avoid bugs (e.g. paid_amount = grand_total but payment_status = unpaid).

### 4. Single return system (no sales.type = return)

- **sales.type** — Only `sale` and `quotation`. Returns are **not** stored as a sale with `type = return`.
- **sale_returns** + **sale_return_items** — The only return model. One return header per original sale (or multiple partial returns), with lines and `ReturnIn` stock movements.
- **POST /api/sales/{id}/return** — Creates a **SaleReturn** and **SaleReturnItem** records (and ReturnIn movements), not a Sale.

---

## Database

### customers

| Field           | Type          | Notes                                |
|----------------|---------------|--------------------------------------|
| id             | bigint        | PK                                    |
| company_id     | bigint        | FK → companies.id                     |
| name           | varchar       | Required                              |
| email          | varchar(255)  | Optional                              |
| phone          | varchar(50)   | Optional                              |
| tax_number     | varchar(50)   | VAT/NTN                               |
| address        | text          | Optional                              |
| city           | varchar(100)  | Optional                              |
| country        | varchar(100)  | Optional                              |
| loyalty_points | decimal(15,2) | Default 0 (future loyalty)            |
| credit_limit   | decimal(15,2) | Optional (B2B / credit sales)        |
| status         | varchar(20)   | active, inactive, blocked             |
| notes          | text          | Optional                              |
| created_by     | bigint        | FK → users.id (nullable)              |
| timestamps     |               | created_at, updated_at                |
| deleted_at     | timestamp     | Soft deletes                          |

**Indexes:** `(company_id, email)`, `(company_id, phone)`, `(company_id, status)`

### customer_addresses

| Field       | Type         | Notes                    |
|------------|--------------|--------------------------|
| id         | bigint       | PK                        |
| customer_id| bigint       | FK → customers.id         |
| company_id | bigint       | FK → companies.id (row-level tenant) |
| type       | varchar(20)  | billing, shipping         |
| address    | text         | Optional                  |
| city       | varchar(100)| Optional                  |
| state      | varchar(100)| Optional                  |
| country    | varchar(100)| Optional                  |
| postal_code| varchar(20) | Optional                  |
| is_default | boolean      | Default false             |
| timestamps |              | created_at, updated_at    |

**Indexes:** `(customer_id, type)`, `(company_id)`

### sales

| Field           | Type          | Notes                                                                 |
|-----------------|---------------|-----------------------------------------------------------------------|
| id              | bigint        | PK                                                                   |
| uuid            | char(36)      | Unique UUID for offline POS sync; prevents sync conflicts            |
| company_id      | bigint        | FK → companies.id                                                    |
| branch_id       | bigint        | FK → branches.id                                                     |
| warehouse_id    | bigint        | FK → warehouses.id (inventory source)                                |
| customer_id     | bigint        | FK → customers.id (nullable)                                         |
| number          | varchar(32)   | Auto-generated (e.g. SAL-2026-000001). Unique per company.             |
| type            | enum          | **sale**, **quotation** only (no return)                              |
| status          | enum          | draft, pending, completed, cancelled, refunded                       |
| payment_status  | varchar(20)   | unpaid, partial, paid, refunded (derived; only PaymentService updates)|
| subtotal        | decimal(15,2) | Sum of line subtotals before order-level discount/tax                |
| discount_total  | decimal(15,2) | Order-level discount total (from sale_discounts)                     |
| tax_total       | decimal(15,2) | Order-level tax (future Tax Engine)                                  |
| total           | decimal(15,2) | **Deprecated.** Must equal `grand_total`; kept for backward compatibility. Canonical amount is **grand_total**. |
| grand_total     | decimal(15,2) | **Canonical total.** subtotal − discount_total + tax_total            |
| paid_amount     | decimal(15,2) | Cached sum of completed payments                                     |
| due_amount      | decimal(15,2) | grand_total − paid_amount                                            |
| currency        | varchar(10)   | Sale currency (e.g. PKR, USD). Default PKR.                          |
| exchange_rate   | decimal(18,8) | Rate to base currency at sale time. Default 1.                         |
| notes           | text          | Optional                                                              |
| created_by      | bigint        | FK → users.id (nullable)                                              |
| updated_by      | bigint        | FK → users.id (nullable)                                              |
| device_id       | varchar(100)  | **POS device/terminal ID** — fraud tracking, terminal reports, shift analytics, offline sync debugging |
| pos_session_id  | bigint        | FK → pos_sessions.id (nullable). Links sale to POS session for cash management. |
| timestamps      |               | created_at, updated_at                                                |
| deleted_at      | timestamp     | Soft deletes. **Only draft sales can be soft-deleted.** Completed/refunded/cancelled are retained. |

**Indexes:** `(company_id, number)` unique, `(company_id, type, status)`, `(branch_id, created_at)`, `(warehouse_id)`, `(customer_id)`, `(company_id, created_at)`, `(company_id, payment_status)`, `uuid` unique

### pos_sessions

POS cash management: open session → sales/payments → close session → cash count (Shopify/Square-style). Extended with device tracking, cashier assignment, shifts, and offline sync reconciliation.

| Field          | Type          | Notes                          |
|----------------|---------------|--------------------------------|
| id             | bigint        | PK                             |
| company_id     | bigint        | FK → companies.id              |
| branch_id      | bigint        | FK → branches.id               |
| device_id      | varchar(100)  | **POS terminal identifier** — links session to physical device |
| device_name    | varchar(255)  | Human-readable device label    |
| cashier_id     | bigint        | FK → users.id — assigned cashier for shift |
| shift          | varchar(30)   | e.g. morning, afternoon, night |
| session_number | varchar(50)   | Optional                       |
| opened_at      | timestamp     | Session start                  |
| closed_at      | timestamp     | Session end (nullable)          |
| opened_by      | bigint        | FK → users.id (nullable)       |
| closed_by      | bigint        | FK → users.id (nullable)       |
| opening_cash   | decimal(15,2) | Cash in drawer at open         |
| expected_cash  | decimal(15,2) | Expected cash at close         |
| counted_cash   | decimal(15,2) | Actual counted cash            |
| cash_difference| decimal(15,2) | counted_cash − expected_cash (over/short) |
| status         | varchar(20)   | open, closed                   |
| notes          | text          | Optional                       |
| close_notes    | text          | Shift handover / close remarks |
| synced         | boolean       | Default true; false for offline sessions pending sync |
| timestamps     |               | created_at, updated_at         |

**Indexes:** `(company_id, status)`, `(branch_id, opened_at)`. Sales and payments can link via `pos_session_id`.

### pos_cash_movements

Cash movement log within a POS session: tracks every cash in/out, drawer event, and links to payments.

| Field          | Type          | Notes                          |
|----------------|---------------|--------------------------------|
| id             | bigint        | PK                             |
| company_id     | bigint        | FK → companies.id              |
| pos_session_id | bigint        | FK → pos_sessions.id           |
| type           | varchar(30)   | pay_in, pay_out, sale_cash, refund_cash, float_adjustment, drawer_open |
| amount         | decimal(15,2) | Movement amount (signed)       |
| reason         | varchar(255)  | Optional reason/description    |
| reference      | varchar(100)  | External reference             |
| payment_id     | bigint        | FK → payments.id (nullable; links cash payment to movement) |
| created_by     | bigint        | FK → users.id (nullable)       |
| timestamps     |               | created_at, updated_at         |

**Indexes:** `(pos_session_id, type)`, `(company_id, created_at)`. Enables full cash reconciliation and audit trail per session.

### sale_lines

Line items of a sale. **Naming:** table and code use **sale_lines** consistently (ERP-style).

- **Duplicate prevention:** Same (product_id, variant_id) in one request is **merged** in SaleService (one line per product+variant with summed quantity). No duplicate lines from the same create request.

| Field             | Type          | Notes                                      |
|-------------------|---------------|--------------------------------------------|
| id                | bigint        | PK                                         |
| sale_id               | bigint        | FK → sales.id                              |
| company_id            | bigint        | FK → companies.id (row-level tenant)       |
| warehouse_id           | bigint        | FK → warehouses.id; **warehouse.branch_id = sale.branch_id** (validated). |
| product_id             | bigint        | FK → products.id                            |
| product_name_snapshot  | varchar(255)  | **Snapshot at sale** — historical accuracy if product renamed |
| sku_snapshot           | varchar(100)  | **Snapshot at sale** — historical accuracy if SKU changes |
| barcode_snapshot       | varchar(100)  | **Snapshot at sale** — historical accuracy if barcode changes |
| tax_class_id_snapshot  | bigint        | **Snapshot at sale** (nullable) — for Tax Engine; historical if tax class changes |
| variant_id             | bigint        | FK → product_variants.id (nullable)         |
| quantity               | decimal(15,2) | Sold quantity                               |
| unit_price             | decimal(15,2) | Price per unit                              |
| cost_price_at_sale     | decimal(15,4) | **Snapshot cost at sale** — profit/margin and analytics. |
| line_total             | decimal(15,2) | quantity × unit_price                       |
| discount               | decimal(15,2) | Line discount                               |
| subtotal               | decimal(15,2) | line_total − discount                       |
| stock_movement_id      | bigint        | FK → stock_movements.id (set on completion)  |
| reservation_id    | bigint        | FK → stock_reservations (quotations)       |
| lot_number        | varchar       | Optional                                   |
| imei_id           | bigint        | FK → product_serials (serialized products) |
| timestamps        |               | created_at, updated_at                     |

**Indexes:** `(sale_id)`, `(company_id)`

**Risk — line warehouse:** Line-level `warehouse_id` is powerful but must be validated: **line.warehouse.branch_id == sale.branch_id** so stock deduction happens in the correct warehouse. Enforced **at two layers**: (1) SaleService application-level validation, (2) **MySQL BEFORE INSERT/UPDATE trigger** (`sale_lines_warehouse_branch_check`) that raises SQLSTATE 45000 if the constraint is violated — prevents data corruption from direct DB inserts or API bypasses.

### sale_taxes

Per-rate tax breakdown for VAT/GST reporting and future Tax Engine.

| Field            | Type          | Notes                          |
|------------------|---------------|--------------------------------|
| id               | bigint        | PK                             |
| sale_id          | bigint        | FK → sales.id                  |
| tax_rate_id      | bigint        | FK to tax_rates (nullable when table exists) |
| tax_name         | varchar(100)  | Optional display name          |
| tax_rate_percent | decimal(8,4)  | Rate at time of sale           |
| taxable_amount   | decimal(15,2) | Amount subject to this tax     |
| tax_amount       | decimal(15,2) | Tax amount                     |
| timestamps       |               | created_at, updated_at         |

**Indexes:** `(sale_id)`, `(tax_rate_id)`. Enables multi-tax jurisdictions and per-rate reporting.

### sale_discounts

| Field       | Type          | Notes                                    |
|------------|---------------|------------------------------------------|
| id         | bigint        | PK                                       |
| sale_id    | bigint        | FK → sales.id                            |
| company_id | bigint        | FK → companies.id (row-level tenant)    |
| type       | varchar(20)   | percentage, fixed, promotion, coupon, manual |
| value      | decimal(15,4) | Amount or percentage                     |
| description| varchar(255)  | Optional                                 |
| timestamps |               | created_at, updated_at                   |

**Indexes:** `(sale_id)`, `(company_id)`

**Calculation order (professional POS):**  
Line discounts → **subtotal** → order discount (sale_discounts) → **tax** → **grand_total**. Documented and applied in this order.

### sale_returns

Return header for a completed sale. **This is the only return entity** (no sales with type=return).

| Field          | Type          | Notes                          |
|----------------|---------------|--------------------------------|
| id             | bigint        | PK                             |
| sale_id        | bigint        | FK → sales.id (original sale)  |
| company_id     | bigint        | FK → companies.id              |
| branch_id      | bigint        | FK → branches.id (nullable)    |
| warehouse_id   | bigint        | FK → warehouses.id (receiving) |
| customer_id    | bigint        | Optional                       |
| return_number  | varchar(50)   | Auto-generated (e.g. SR-2026-000001) |
| refund_amount       | decimal(15,2) | Default 0                      |
| status              | enum          | draft, completed, cancelled    |
| reason              | text          | Optional free-text reason      |
| return_reason_code  | varchar(30)   | **Structured reason** — damaged, customer_return, wrong_item, warranty, fraud, other. Enables analytics. |
| created_by          | bigint        | FK → users.id (nullable)       |
| timestamps     |               | created_at, updated_at         |
| deleted_at     | timestamp     | Soft deletes                   |

**Unique:** `(company_id, return_number)`. **Indexes:** `(company_id, status)`, `(company_id, sale_id)` (returns for sale with tenant filter), `(sale_id)`, `(warehouse_id)`

### sale_return_items

| Field             | Type          | Notes                          |
|-------------------|---------------|--------------------------------|
| id                | bigint        | PK                             |
| sale_return_id    | bigint        | FK → sale_returns.id           |
| company_id        | bigint        | FK → companies.id (row-level tenant) |
| product_id        | bigint        | FK → products.id               |
| variant_id        | bigint        | FK → product_variants (nullable)|
| quantity          | decimal(15,4) | Returned quantity              |
| unit_price        | decimal(15,4) | Snapshot at return             |
| total             | decimal(15,2) | quantity × unit_price          |
| stock_movement_id | bigint        | FK → stock_movements (ReturnIn)|
| timestamps        |               | created_at, updated_at         |

**Indexes:** `(sale_return_id)`, `(company_id)`

### sale_adjustments

Admin overrides for completed (immutable) sales. Instead of editing posted entries, adjustments create reversal journal entries — the ERP-standard approach (SAP credit/debit memo, Odoo refund invoice).

| Field            | Type          | Notes                          |
|------------------|---------------|--------------------------------|
| id               | bigint        | PK                             |
| company_id       | bigint        | FK → companies.id              |
| sale_id          | bigint        | FK → sales.id                  |
| adjustment_number| varchar(50)   | Auto-generated (e.g. ADJ-2026-000001) |
| type             | varchar(30)   | price_correction, quantity_correction, discount_correction, tax_correction, cancellation, other |
| amount           | decimal(15,2) | Adjustment amount              |
| reason           | varchar(500)  | Required explanation           |
| approved_by      | bigint        | FK → users.id (four-eyes: cannot be same as creator) |
| approved_at      | timestamp     | When approved (nullable)       |
| status           | varchar(20)   | pending, approved              |
| metadata         | json          | Optional structured data       |
| journal_entry_id | bigint        | FK → journal_entries.id (set on approval) |
| created_by       | bigint        | FK → users.id (nullable)       |
| timestamps       |               | created_at, updated_at         |

**Unique:** `(company_id, adjustment_number)`. **Indexes:** `(company_id, sale_id)`, `(company_id, status)`.

**Workflow:** Create (status=pending) → Approve (status=approved, journal entry posted). **Four-eyes principle:** the creator cannot approve their own adjustment.

### sale_line_history

Audit trail for draft sale line edits before completion. Tracks quantity, price, and discount changes for compliance, analytics, and error recovery.

| Field           | Type          | Notes                          |
|-----------------|---------------|--------------------------------|
| id              | bigint        | PK                             |
| sale_id         | bigint        | FK → sales.id                  |
| sale_line_id    | bigint        | FK → sale_lines.id (nullable)  |
| company_id      | bigint        | FK → companies.id              |
| action          | varchar(20)   | added, updated, removed        |
| product_id      | bigint        | FK → products.id               |
| variant_id      | bigint        | FK → product_variants (nullable)|
| old_quantity     | decimal(15,2) | Previous quantity (nullable)    |
| new_quantity     | decimal(15,2) | New quantity (nullable)         |
| old_unit_price   | decimal(15,2) | Previous price (nullable)       |
| new_unit_price   | decimal(15,2) | New price (nullable)            |
| old_discount     | decimal(15,2) | Previous discount (nullable)    |
| new_discount     | decimal(15,2) | New discount (nullable)         |
| changed_by      | bigint        | FK → users.id (nullable)       |
| changed_at      | timestamp     | When change occurred            |
| timestamps      |               | created_at, updated_at         |

**Indexes:** `(sale_id, changed_at)`, `(company_id, sale_id)`. Used for draft edit audit; logged automatically when lines are added/updated/removed on draft sales.

---

## Sales workflow

### 1. POS creates order (draft)

- **POST /api/sales** with `type: "sale"`, `status: "draft"`, `lines`, optional `customer_id`, `discounts`, `notes`.
- Sale is created with `status = draft`, `payment_status = unpaid`, `uuid` generated.
- Lines with same (product_id, variant_id) are **merged** into one line per product+variant.
- No stock deduction; no stock movements.

### 2. Checkout / complete

- **POST /api/sales/{id}/complete**
- **Inventory locking:** `StockCache` rows for the warehouse and products are locked with **SELECT ... FOR UPDATE** inside the transaction to prevent race conditions.
- Validates available stock; creates `sale_out` stock movements per line; updates `status` to `completed` and `payment_status` (from paid_amount vs grand_total).
- Posts accounting via PaymentService.

### 3. Payment attached

- **POST /api/payments** with `sale_id`, `lines`. Only **PaymentService** updates the sale’s `paid_amount`, `due_amount`, and **payment_status**.

### 4. Return (sale_returns only)

- **POST /api/sales/{id}/return** — Creates a **SaleReturn** and **SaleReturnItem** records (and ReturnIn movements). Returns the created SaleReturn with items. No Sale with type=return is created.

### 5. Cancel

- **POST /api/sales/{id}/cancel** — Cancels a sale. Allowed only for **draft** or **pending**. **Completed sales cannot be cancelled**; use returns or refunds instead. Idempotent for already-cancelled (returns error).

---

## Immutable completed sales

**Rule:** Completed sales are immutable.

- **Line edits, price changes, or quantity changes are forbidden** after a sale is completed. This protects accounting and inventory integrity.
- **Exchange rate and currency cannot be changed** on completed sales (enforced in Sale model `updating` hook). Historical rates must be preserved.
- **Only returns, refunds, or sale adjustments are allowed** for completed sales.
- There is no **PUT /sales/{id}** that updates lines or totals; create is one-shot and complete/cancel/return/adjust are the only state transitions.

### Sale adjustments (admin override)

For human errors on completed sales (wrong price, wrong discount, etc.), the system provides a controlled **adjustment workflow** instead of direct edits:

1. **Create adjustment** — `POST /api/sales/{saleId}/adjustments` with `type`, `amount`, and `reason`. Status = `pending`.
2. **Approve adjustment** — `POST /api/sale-adjustments/{id}/approve`. **Four-eyes principle:** the approver must be a different user than the creator. On approval, a reversal journal entry is posted (Dr Sales Revenue, Cr Accounts Receivable).
3. Both creation and approval are logged in `sale_audit_log` for full traceability.

This avoids legal/accounting issues with editing posted entries while still allowing corrections.

---

## Quotation workflow

- **sales.type = quotation** — Quotation (pending conversion).
- **Convert to sale:** **POST /api/sales/{id}/convert** — Converts quotation to sale: validates stock, creates sale_out movements, releases reservations, posts accounting. (Documented as “convert-to-sale” in API.)
- **Reserve stock (optional):** Quotations can reserve stock via `stock_reservations`; released on convert or expire.
- **Expire:** Quotations can be left as pending or cancelled; expiry logic can be added (e.g. cron that marks old pending quotations as expired).

---

## Inventory integration

- **stock_movements** — Inventory is tenant-scoped. The `stock_movements` table includes **company_id** (FK → companies); set from the product’s company or context. All movement queries should be scoped by company for multi-tenant safety.
- **Sale completion:** For each sale_line, a stock_movement with `type = sale_out`, `reference_type = 'Sale'`, `reference_id = sale.id`. **Locking:** `SELECT ... FOR UPDATE` on relevant `stock_cache` rows inside the same transaction.
- **Return:** For each sale_return_item, a stock_movement with `type = return_in`, `reference_type = 'SaleReturn'`, `reference_id = sale_return.id`.
- Movements are append-only; corrections use new (reversal) movements, never deletes.

---

## Refund accounting integration

Refunds are fully integrated with accounting and inventory:

1. **Refund (payment)** — **PaymentService::refund()** creates a negative payment and:
   - **Accounting:** Dr Sales Returns, Cr Cash/Bank (reversal of original receipt).
   - **Sale:** Decrements sale’s `paid_amount`, recalculates `due_amount` and **payment_status** (only place payment_status is updated for refunds).
2. **Return (goods)** — **SaleService::createReturn()** creates **SaleReturn** + **SaleReturnItem** and:
   - **Inventory:** ReturnIn movements restore stock.
   - **Accounting:** **PaymentService::postReturnPosting()** — Dr Sales Returns, Cr Accounts Receivable (goods returned, receivable reduced before any cash refund).
3. **Order of operations:** Return (goods) can be posted first; then refund (payment) when money is repaid. Both paths update accounting and sale amounts/status correctly.

---

## Currency and base-currency rule

- **sales.currency** and **sales.exchange_rate** — Sale can be in any currency; `exchange_rate` is the rate to the **base currency** at sale time.
- **Immutable rate:** Exchange rate and currency **cannot be changed** on completed sales (enforced in Sale model). Historical rates are preserved for reporting accuracy.
- **Rule:** All accounting postings (journal entries) must use the **base currency**. When sale currency differs from base (e.g. sale in USD, base PKR), amounts are converted using `exchange_rate` and stored in base currency for consistent reporting. Store converted amounts for accounting; do not post in transaction currency only.

### Multi-currency safeguards

- **Rate stored at transaction time** — `sales.exchange_rate` and `payments.exchange_rate` capture the rate when the transaction is created. Never update historical rates.
- **Rounding:** Use `CurrencyRounding::round()` (banker's rounding / `PHP_ROUND_HALF_EVEN`) for all monetary calculations to ensure consistent rounding across services.
- **Base conversion:** `CurrencyRounding::toBaseCurrency($amount, $rate)` — transaction amount × exchange_rate, rounded to 2 decimals.
- **Exchange differences:** `CurrencyRounding::exchangeDifference()` calculates gain/loss between rates. Future: post exchange gain/loss entries when rates differ between sale and payment date.
- **Mid-day rate changes:** Rates are locked per transaction, so intra-day rate updates do not affect already-posted sales or payments. Reporting drift is avoided.

---

## Customer ledger

The system maintains a **customer_ledger** (see [Payment & Accounting](payment-accounting.md#customer-ledger)) that records per-customer:

- **invoice** — When a sale is completed (amount = grand_total; increases balance).
- **payment** — When a payment is completed (amount negative; decreases balance).
- **refund** — When a payment is refunded or a sale return is posted (reduces balance).

Entries are created automatically by **CustomerLedgerService** when sales are completed, payments are recorded, or returns/refunds are processed. The ledger powers **aging reports**, **credit control**, and **customer statements**.

---

## Payment integration

- **payments** — `sale_id` links a payment to a sale; **pos_session_id** links to POS session. One sale can have many payments (split payments).
- **payment_allocations** — For splitting one payment across multiple sales (future).
- **payment_status** on sale — **Derived only.** Updated solely by PaymentService (and SaleService when completing a draft). Never set via API or mass assignment.
- **Race condition:** PaymentService uses **SELECT ... FOR UPDATE** on the sale row when recording a payment so that `paid_amount` / `due_amount` / `payment_status` stay consistent when multiple terminals pay the same sale.

---

## Status architecture

### Sale status

| Value      | Description                                  |
|-----------|----------------------------------------------|
| draft     | Order created at POS; no stock change        |
| pending   | e.g. quotation not yet converted             |
| completed | Order fulfilled; stock deducted              |
| cancelled | Order cancelled                               |
| refunded  | Fully refunded (when appropriate)             |

### Sale type

| Value      | Description        |
|-----------|--------------------|
| sale      | Normal sale        |
| quotation | Quotation (convert via POST /sales/{id}/convert) |

**No** `return` type on sales; returns use **sale_returns** only.

### Payment status (on sale)

Derived from `paid_amount` and `grand_total`; only PaymentService (and SaleService on complete) may set it.

---

## POS session management

POS sessions follow the Square/Shopify/Odoo model: **open session → sales/payments → close session → cash count**.

### Session enforcement

Controlled via `config/pos.php`:

- **`pos.require_session`** (env: `POS_REQUIRE_SESSION`) — When `true`, all sales and payments must include a valid `pos_session_id` linked to an **open** session. Prevents orphaned transactions and ensures cash reconciliation integrity.
- **`pos.require_device_id`** (env: `POS_REQUIRE_DEVICE_ID`) — When `true`, every sale must include a `device_id`.

When a `pos_session_id` is provided (even if not required), SaleService and PaymentService validate that the session exists and has status = `open`. Transactions against closed sessions are rejected.

### Extended session features

- **Device tracking:** `device_id` and `device_name` on sessions link to physical POS terminals.
- **Cashier assignment:** `cashier_id` links to users.id; combined with `shift` (morning/afternoon/night) for shift reporting.
- **Cash movements:** `pos_cash_movements` table logs every cash event (pay_in, pay_out, sale_cash, refund_cash, float_adjustment, drawer_open) within a session.
- **Cash reconciliation:** `opening_cash`, `expected_cash`, `counted_cash`, `cash_difference` (counted − expected) enable end-of-shift reconciliation.
- **Offline sync:** `synced` flag on sessions; set to `false` for offline sessions pending sync.

---

## Offline POS and UUID

- **sales.uuid** — Unique UUID per sale for offline POS sync. Prevents sync conflicts when multiple devices create or update sales; sync logic can use `uuid` as stable id.
- **number** — Human-readable sale number (e.g. SAL-2026-000001) for display and search.

---

## Constraints and safety

- **Immutable completed sales** — No line edits, price or quantity changes after completion; only returns/refunds/adjustments. Exchange rate and currency locked on completion.
- **Stock overselling** — Validated before completion; lock on `stock_cache` with **SELECT ... FOR UPDATE** inside transaction.
- **Line warehouse (dual-layer)** — `line.warehouse.branch_id` must equal `sale.branch_id`. Enforced at **application layer** (SaleService) and **database layer** (MySQL BEFORE INSERT/UPDATE trigger `sale_lines_warehouse_branch_check`).
- **Cross-tenant** — sale’s company_id must match customer’s when customer_id is set; branch/warehouse belong to company.
- **payment_status** — Not in Sale’s fillable; only updated by PaymentService and SaleService.
- **total vs grand_total** — Keep `total = grand_total` whenever grand_total is set; `total` is deprecated to avoid data drift.
- **Soft deletes** — **Only draft sales can be soft-deleted.** Completed, refunded, or cancelled sales must be retained for reporting; attempting to delete them throws.
- **Race conditions in payments** — PaymentService locks the sale row with **SELECT ... FOR UPDATE**; deadlock retry applies for high concurrency.
- **Soft deletes** (other) — sale_returns; movements are never deleted (use reversal).
- **Soft deletes (customers)** — Soft-deleted customers remain referenced by historical sales. **Reporting must join soft-deleted rows** (`withTrashed()`) for customer statements, aging reports, or sales history.
- **Draft line audit trail** — Line additions on draft/quotation sales are logged to `sale_line_history` for compliance, error recovery, and analytics.
- **Sale adjustment four-eyes** — Adjustments on completed sales require two different users: one to create, one to approve.
- **Multi-currency immutability** — Exchange rate and currency frozen on completed sales (Sale model `updating` hook). Use `CurrencyRounding` helper (banker's rounding) for consistent monetary calculations.
- **Deadlock retry** — All stock-locking transactions (sale create/complete/convert, payment create/refund) use `RetryOnDeadlock` trait: exponential back-off with up to 3 retries on MySQL deadlock (SQLSTATE 40001). Recovers from transient lock conflicts without surfacing errors.
- **POS session validation** — When `pos_session_id` is provided, session must exist and be `open`. Configurable enforcement via `config/pos.php` (`POS_REQUIRE_SESSION`, `POS_REQUIRE_DEVICE_ID`).

---

## API summary

### Sales

| Method | Endpoint                   | Description                                      |
|--------|----------------------------|--------------------------------------------------|
| GET    | /api/sales                 | List sales (type, branch_id, status, date filters) |
| POST   | /api/sales                 | Create sale or quotation (optional draft, discounts) |
| GET    | /api/sales/{id}            | Sale detail with lines, movements, discounts     |
| POST   | /api/sales/{id}/complete   | Complete draft sale (stock deduction, accounting) |
| POST   | /api/sales/{id}/convert    | Convert quotation to sale (stock, accounting)    |
| POST   | /api/sales/{id}/cancel     | Cancel sale (draft or pending only; completed → use return/refund) |
| POST   | /api/sales/{id}/return     | Create SaleReturn + items (ReturnIn movements)    |
| GET    | /api/sales/{id}/stock-check| Current stock per line for sale’s warehouse      |

### Sale Adjustments

| Method | Endpoint                            | Description                    |
|--------|-------------------------------------|--------------------------------|
| GET    | /api/sale-adjustments               | List adjustments (sale_id, status filters) |
| POST   | /api/sales/{saleId}/adjustments     | Create adjustment for completed sale |
| POST   | /api/sale-adjustments/{id}/approve  | Approve adjustment (four-eyes, posts journal entry) |

### Customers

| Method | Endpoint              | Description                    |
|--------|------------------------|--------------------------------|
| GET    | /api/customers        | List (search, status, pagination) |
| POST   | /api/customers        | Create (optional addresses)    |
| GET    | /api/customers/{id}   | Show with addresses            |
| PUT    | /api/customers/{id}   | Update                         |
| GET    | /api/customers/{id}/warranties | Customer warranties     |

### Payments

| Method | Endpoint                | Description                    |
|--------|-------------------------|--------------------------------|
| GET    | /api/payments           | List payments                  |
| POST   | /api/payments           | Create payment (sale_id, lines) |
| GET    | /api/payments/{id}      | Payment detail                 |
| POST   | /api/payments/{id}/refund | Refund (amount, account_id)  |

---

## Profit and margin analytics

- **sale_lines.cost_price_at_sale** — Snapshot of product cost at sale time (from `product.cost_price` or `product.average_cost`). Enables:
  - **Profit per line:** `(unit_price - cost_price_at_sale) × quantity` (before line discount).
  - **Product margins** and margin reports.
  - **Inventory valuation** alignment (cost at sale vs. movement cost).
- Use **grand_total** and line **subtotal** for revenue; use **cost_price_at_sale** for cost of goods; difference is gross profit.

---

## Risks and mitigations

### Addressed risks

| Risk | Mitigation | Implementation |
|------|-----------|----------------|
| **Line warehouse cross-branch** | DB trigger + service validation (dual-layer) | MySQL BEFORE INSERT/UPDATE trigger `sale_lines_warehouse_branch_check`; SaleService validation |
| **Payment race conditions / deadlocks** | SELECT FOR UPDATE + deadlock retry | `RetryOnDeadlock` trait on SaleService and PaymentService (3 retries, exponential back-off) |
| **Immutable sale corrections** | Sale adjustment workflow with four-eyes approval | `sale_adjustments` table, `SaleAdjustmentService`, reversal journal entries |
| **POS session orphaned transactions** | Configurable session enforcement | `config/pos.php` (`POS_REQUIRE_SESSION`); session open-state validation |
| **No audit trail for draft edits** | Sale line edit history | `sale_line_history` table, logged on line add/update/remove |
| **Exchange rate drift** | Rate immutability on completed sales | Sale model `updating` hook prevents exchange_rate/currency changes |
| **Soft-deleted customer reporting** | Documented guidance | Use `withTrashed()` when querying historical sales/statements |
| **Multi-currency rounding** | Centralized rounding utility | `CurrencyRounding` helper with banker's rounding (PHP_ROUND_HALF_EVEN) |

### Future risks to monitor

| Risk | Suggestion | Status |
|------|-----------|--------|
| **Payment allocations (split across sales)** | Implement `payment_allocations` for bulk partial payments | Future (schema ready) |
| **UUID sync conflicts between devices** | Implement conflict resolution queue (last-write-wins or manual merge) | Future |
| **Stock below minimum alerts** | Real-time notifications for low stock, overdue payments, returns requiring approval | Future |
| **High-volume POS payment/refund locking** | Async payment/refund queue to reduce lock contention | Future |
| **Return fraud detection** | Advanced return analytics with `return_reason_code` trend reporting | Future (data capturing in place) |

---

## Future improvements

1. **Notifications & alerts** — Stock below minimum, pending payments overdue, and returns requiring approval as real-time events (WebSocket/push).
2. **Advanced return analytics** — Dashboards for `return_reason_code` breakdowns: fraud detection, warranty trend analysis, damaged goods patterns.
3. **Payment & refund queue** — For high-volume POS, async refund/payment posting queue to avoid locking delays under extreme concurrency.
4. **Payment allocations** — Split one payment across multiple sales (B2B/wholesale). Schema (`payment_allocations`) is ready.
5. **Extended POS reconciliation** — End-of-day cash reports per session, over/short analysis, shift performance metrics.
6. **Multi-currency accounting entries** — Post exchange gain/loss journal entries when payment exchange rate differs from sale rate.
7. **Offline sync conflict resolution** — Queue-based merge strategy for UUID conflicts between POS devices.

---

## File reference

- **Migrations:** `database/migrations/2026_03_26_*` … `2026_03_30_*` (customers, addresses, sales columns, sale_discounts, sale_returns, sale_return_items, company_id on child tables, remove return from sales, sales.uuid, indexes, cost_price_at_sale, **pos_sessions**, **sales.device_id / pos_session_id**, **sale_lines snapshots**, **payments.pos_session_id**, **sale_taxes**, **sale_returns.return_reason_code**, **customer_ledger**, **warehouse-branch trigger**, **sale_adjustments**, **sale_line_history**, **pos_cash_movements**, **extended pos_sessions**).
- **Enums:** `SaleStatus`, `SalePaymentStatus`, `DiscountType`, `SaleReturnStatus`, `CustomerStatus`, `SaleType`, **`ReturnReasonCode`**, **`SaleAdjustmentType`** (price_correction, quantity_correction, discount_correction, tax_correction, cancellation, other).
- **Models:** `Customer`, `CustomerAddress`, `Sale`, `SaleLine`, `SaleDiscount`, **`SaleTax`**, `SaleReturn`, `SaleReturnItem`, **`PosSession`** (extended: device_id, cashier_id, shift, cash_difference, synced), **`PosCashMovement`**, **`SaleAdjustment`**, **`SaleLineHistory`**, **`CustomerLedger`**.
- **Services:** `SaleService` (create, complete, cancel, returns, deadlock retry, POS enforcement, line history), `CustomerService`, `PaymentService` (payment_status sync, sale row lock, deadlock retry, POS session validation), **`SaleAdjustmentService`** (create/approve with four-eyes), **`CustomerLedgerService`** (postInvoice, postPayment, postRefund, postReturnCredit).
- **Support:** **`RetryOnDeadlock`** trait (exponential back-off on deadlocks), **`CurrencyRounding`** (banker's rounding, base currency conversion, exchange difference calculation).
- **Config:** **`config/pos.php`** (`require_session`, `require_device_id`).
- **Controllers:** `SaleController`, `PaymentController`, **`SaleAdjustmentController`** (create/approve/list).

This document describes the Sales & Customer Engine: single return system, company_id on child tables and stock_movements, **price/product snapshots**, **device_id** and **pos_session_id** for POS, **pos_sessions** (extended with device/cashier/shift/cash movements), **sale_taxes** breakdown, **return_reason_code**, **dual-layer warehouse validation** (service + DB trigger), derived payment_status, line merging, **immutable completed sales** (exchange rate locked), **sale adjustments** (four-eyes admin override), **sale line edit history** (draft audit trail), **deadlock retry** (exponential back-off), **POS session enforcement** (configurable), **multi-currency safeguards** (CurrencyRounding, immutable rates), **cancel endpoint**, **only draft sales can be soft-deleted**, **payment race condition** (lock + retry), **customer ledger** (see payment-accounting.md), cost_price_at_sale, total deprecated/grand_total canonical, base-currency rule, refund accounting, quotation workflow, locking, UUID/indexes, and comprehensive risk mitigations.
