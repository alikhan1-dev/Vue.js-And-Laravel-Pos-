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
| timestamps      |               | created_at, updated_at                                                |
| deleted_at      | timestamp     | Soft deletes                                                          |

**Indexes:** `(company_id, number)` unique, `(company_id, type, status)`, `(branch_id, created_at)`, `(warehouse_id)`, `(customer_id)`, `(company_id, created_at)`, `(company_id, payment_status)`, `uuid` unique

### sale_lines

Line items of a sale. **Naming:** table and code use **sale_lines** consistently (ERP-style).

- **Duplicate prevention:** Same (product_id, variant_id) in one request is **merged** in SaleService (one line per product+variant with summed quantity). No duplicate lines from the same create request.

| Field             | Type          | Notes                                      |
|-------------------|---------------|--------------------------------------------|
| id                | bigint        | PK                                         |
| sale_id           | bigint        | FK → sales.id                              |
| company_id        | bigint        | FK → companies.id (row-level tenant)      |
| warehouse_id      | bigint        | FK → warehouses.id; must satisfy warehouse.branch_id = sale.branch_id |
| product_id        | bigint        | FK → products.id                           |
| variant_id        | bigint        | FK → product_variants.id (nullable)        |
| quantity            | decimal(15,2) | Sold quantity                              |
| unit_price          | decimal(15,2) | Price per unit                             |
| cost_price_at_sale  | decimal(15,4) | **Snapshot cost at sale** (product.cost_price or average_cost). For profit/margin reports and analytics. |
| line_total          | decimal(15,2) | quantity × unit_price                      |
| discount            | decimal(15,2) | Line discount                              |
| subtotal            | decimal(15,2) | line_total − discount                      |
| stock_movement_id   | bigint        | FK → stock_movements.id (set on completion) |
| reservation_id    | bigint        | FK → stock_reservations (quotations)       |
| lot_number        | varchar       | Optional                                   |
| imei_id           | bigint        | FK → product_serials (serialized products) |
| timestamps        |               | created_at, updated_at                     |

**Indexes:** `(sale_id)`, `(company_id)`

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
| refund_amount  | decimal(15,2) | Default 0                      |
| status         | enum          | draft, completed, cancelled    |
| reason         | text          | Optional                       |
| created_by     | bigint        | FK → users.id (nullable)       |
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
- **Only returns or refunds are allowed** for completed sales (via SaleReturn + refund payments).
- There is no **PUT /sales/{id}** that updates lines or totals; create is one-shot and complete/cancel/return are the only state transitions.

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
- **Rule:** All accounting postings (journal entries) must use the **base currency**. When sale currency differs from base (e.g. sale in USD, base PKR), amounts are converted using `exchange_rate` and stored in base currency for consistent reporting. Store converted amounts for accounting; do not post in transaction currency only.

---

## Payment integration

- **payments** — `sale_id` links a payment to a sale. One sale can have many payments (split payments).
- **payment_allocations** — For splitting one payment across multiple sales (future).
- **payment_status** on sale — **Derived only.** Updated solely by PaymentService (and SaleService when completing a draft). Never set via API or mass assignment.

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

## POS session (future)

Real POS systems (Square, Shopify, Odoo) use **pos_sessions**: open session → transactions (sales, payments) → close session → cash count. This would link sales and payments to a session and support cash drawer reconciliation. Not implemented yet; schema is ready to add `pos_sessions` and optional `session_id` on sales/payments when needed.

---

## Offline POS and UUID

- **sales.uuid** — Unique UUID per sale for offline POS sync. Prevents sync conflicts when multiple devices create or update sales; sync logic can use `uuid` as stable id.
- **number** — Human-readable sale number (e.g. SAL-2026-000001) for display and search.

---

## Constraints and safety

- **Immutable completed sales** — No line edits, price or quantity changes after completion; only returns/refunds.
- **Stock overselling** — Validated before completion; lock on `stock_cache` with **SELECT ... FOR UPDATE** inside transaction.
- **Line warehouse** — `line.warehouse.branch_id` must equal `sale.branch_id`; enforced in SaleService.
- **Cross-tenant** — sale’s company_id must match customer’s when customer_id is set; branch/warehouse belong to company.
- **payment_status** — Not in Sale’s fillable; only updated by PaymentService and SaleService.
- **total vs grand_total** — Keep `total = grand_total` whenever grand_total is set; `total` is deprecated to avoid data drift.
- **Soft deletes** — customers, sales, sale_returns; movements are never deleted (use reversal).

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

## File reference

- **Migrations:** `database/migrations/2026_03_26_*`, `2026_03_27_*`, `2026_03_28_*` (customers, addresses, sales columns, sale_discounts, sale_returns, sale_return_items, company_id on child tables, remove return from sales, sales.uuid, indexes, sale_returns `(company_id, sale_id)` index, sale_lines `cost_price_at_sale`).
- **Enums:** `SaleStatus`, `SalePaymentStatus`, `DiscountType`, `SaleReturnStatus`, `CustomerStatus`, `SaleType` (sale, quotation only).
- **Models:** `Customer`, `CustomerAddress`, `Sale`, `SaleLine` (with `cost_price_at_sale`), `SaleDiscount`, `SaleReturn`, `SaleReturnItem`.
- **Services:** `SaleService` (create draft/complete/cancel, discounts, returns via SaleReturn, line merge, branch validation, cost snapshot), `CustomerService`, `PaymentService` (payment_status sync only).

This document describes the Sales & Customer Engine: single return system, company_id on child tables and stock_movements, line warehouse validation, derived payment_status, line merging, **immutable completed sales**, **cancel endpoint**, **cost_price_at_sale** for profit analytics, **total deprecated / grand_total canonical**, **base-currency rule** for accounting, refund accounting, quotation workflow, locking, and UUID/indexes.
