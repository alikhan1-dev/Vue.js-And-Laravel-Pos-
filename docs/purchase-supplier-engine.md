# Step 7: Purchase & Supplier Engine

A fully tenant-aware purchase and supplier module. Manages suppliers, purchase orders, goods receipts (including partial shipments), supplier invoices, and supplier payments. Integrates with inventory (stock movements) and accounting (journal entries).

---

## Overview

The purchase workflow follows the enterprise standard:

```
Create Purchase Order
        ↓
Confirm order (draft → confirmed)
        ↓
Mark ordered (sent to supplier)
        ↓
Goods received in warehouse
        ↓
Inventory increases
        ↓
Supplier invoice created
        ↓
Payment to supplier
```

**Example:** Buy 50 iPhones from supplier

- **Purchase Order** → Created with supplier, branch, warehouse, lines (product, quantity, unit_cost), and optional `expected_delivery_date`
- **Goods Receipt** → Receive 30 first, then 20 (partial receipts supported); each line tracks `received_status`
- **Inventory** → +50 iPhones via `purchase_in` stock movements
- **Supplier Invoice** → Created (optionally linked to purchase), stores vendor's own `supplier_invoice_number` and `exchange_rate`
- **Supplier Payment** → Dr Accounts Payable, Cr Cash/Bank; stores `payment_reference` (bank transfer ID, cheque number, etc.)

---

## Database

### suppliers

| Field        | Type          | Notes                                                |
|-------------|---------------|------------------------------------------------------|
| id          | bigint        | PK                                                   |
| company_id  | bigint        | FK → companies.id                                    |
| name        | varchar       | Supplier name                                        |
| code        | varchar(50)   | Unique per company (e.g. SUP-001)                     |
| email       | varchar(255)  | Optional                                             |
| phone       | varchar(50)   | Optional                                             |
| address     | text          | Optional                                             |
| tax_number  | varchar(50)   | VAT/NTN                                              |
| currency_id | bigint        | FK → currencies.id (nullable)                         |
| credit_limit| decimal(15,2) | Optional                                             |
| is_active   | boolean       | Default true                                         |
| created_by  | bigint        | FK → users.id                                        |
| timestamps  |               | created_at, updated_at                               |
| deleted_at  | timestamp     | **Soft deletes.** ERP systems never hard-delete suppliers. |

**Unique:** `(company_id, code)`  
**Index:** `(company_id, is_active)`

### purchases

| Field                  | Type          | Notes                                    |
|------------------------|---------------|------------------------------------------|
| id                     | bigint        | PK                                       |
| company_id             | bigint        | FK → companies.id                        |
| branch_id              | bigint        | FK → branches.id                         |
| warehouse_id           | bigint        | FK → warehouses.id                       |
| supplier_id            | bigint        | FK → suppliers.id                        |
| purchase_number        | varchar(50)   | Auto-generated (e.g. PO-2026-00001)      |
| status                 | enum          | draft, confirmed, ordered, partially_received, received, cancelled |
| currency_id            | bigint        | FK → currencies.id (nullable)            |
| exchange_rate          | decimal(18,8) | Default 1                                 |
| total                  | decimal(15,2) | Sum of line subtotals                    |
| tax_total              | decimal(15,2) | Default 0                                 |
| discount_total         | decimal(15,2) | Default 0                                 |
| notes                  | text          | Optional                                 |
| purchase_date          | date          | Optional                                 |
| expected_delivery_date | date          | **Optional.** For procurement planning, supplier performance tracking, late-delivery analytics. |
| created_by             | bigint        | FK → users.id                            |
| timestamps             |               | created_at, updated_at                   |

**Unique:** `(company_id, purchase_number)`  
**Indexes:** `(company_id, status)`, `(supplier_id, status)`, `purchase_date`

### purchase_lines

| Field             | Type          | Notes                                                    |
|-------------------|---------------|----------------------------------------------------------|
| id                | bigint        | PK                                                       |
| purchase_id       | bigint        | FK → purchases.id                                        |
| product_id        | bigint        | FK → products.id                                         |
| quantity          | decimal(15,4) | Ordered quantity                                         |
| received_quantity | decimal(15,4) | **Cached** total received (incremented on each receipt). Remaining = quantity − received_quantity. Enterprise ERPs (SAP, Odoo, NetSuite) store this to avoid heavy queries on goods_receipt_lines. |
| received_status   | enum          | **Line-level receiving state:** `pending`, `partially_received`, `received`. Auto-synced after each receipt. Eliminates per-request computation — ERP systems always store line status. |
| unit_cost         | decimal(15,4) | Cost per unit                                            |
| tax_id            | bigint        | Nullable (future taxes)                                   |
| discount          | decimal(15,4) | Line discount                                            |
| subtotal          | decimal(15,2) | quantity × unit_cost − discount                          |
| timestamps        |               | created_at, updated_at                                   |

**Index:** `(purchase_id)`

### goods_receipts

| Field          | Type        | Notes                                              |
|----------------|-------------|-----------------------------------------------------|
| id             | bigint      | PK                                                  |
| company_id     | bigint      | FK → companies.id                                   |
| branch_id      | bigint      | FK → branches.id                                    |
| warehouse_id   | bigint      | FK → warehouses.id                                  |
| purchase_id    | bigint      | FK → purchases.id                                   |
| receipt_number | varchar(50) | Auto-generated (e.g. GR-2026-00001)                |
| status         | enum        | **draft, posted, cancelled** (API creates `posted` receipts; `draft` available for future two-step workflows) |
| received_at    | timestamp   | When goods were received                            |
| created_by     | bigint      | FK → users.id                                       |
| received_by    | bigint      | FK → users.id (nullable). **Warehouse receiving staff** — may differ from creator. Useful for audit trails. |
| timestamps     |             | created_at, updated_at                              |

**Unique:** `(company_id, receipt_number)`  
**Indexes:** `(company_id, status)`, `(purchase_id, status)`

### goods_receipt_lines

| Field             | Type          | Notes                                                                 |
|-------------------|---------------|-----------------------------------------------------------------------|
| id                | bigint        | PK                                                                    |
| goods_receipt_id  | bigint        | FK → goods_receipts.id                                                |
| purchase_line_id  | bigint        | FK → purchase_lines.id                                                |
| product_id        | bigint        | FK → products.id                                                      |
| quantity_received | decimal(15,4) | Quantity received in this receipt                                     |
| unit_cost         | decimal(15,4) | **Snapshot** of unit cost at receipt. Enterprise ERPs always store this for correct inventory valuation when currency, supplier invoice price, or landed cost changes later. Optional override in receive API. |
| timestamps        |               | created_at, updated_at                                                |

**Indexes:** `(goods_receipt_id)`, `(purchase_line_id)`

### supplier_invoices

| Field                    | Type          | Notes                                                                 |
|--------------------------|---------------|-----------------------------------------------------------------------|
| id                       | bigint        | PK                                                                    |
| company_id               | bigint        | FK → companies.id                                                     |
| supplier_id              | bigint        | FK → suppliers.id                                                     |
| purchase_id              | bigint        | FK → purchases.id (nullable)                                          |
| invoice_number           | varchar(50)   | Auto-generated system number (e.g. SI-2026-00001)                      |
| supplier_invoice_number  | varchar(100)  | **Vendor's own invoice reference** (e.g. INV-88931). ERP systems always store both system and supplier numbers. |
| total                    | decimal(15,2) | Invoice total                                                         |
| paid_amount              | decimal(15,2) | **Cached** total paid (incremented on each supplier payment). Remaining = total − paid_amount. |
| currency_id              | bigint        | FK → currencies.id (nullable)                                         |
| exchange_rate            | decimal(18,8) | **Snapshot** of exchange rate at invoice creation. Required for multi-currency accounting — entries convert to base currency. Default 1. |
| status                   | enum          | draft, posted, paid, cancelled                                        |
| invoice_date             | date          | Optional                                                              |
| due_date                 | date          | Optional                                                              |
| timestamps               |               | created_at, updated_at                                                |

**Unique:** `(company_id, invoice_number)`  
**Indexes:** `(company_id, status)`, `(supplier_id, status)`

### supplier_payments

| Field               | Type          | Notes                                                                 |
|---------------------|---------------|-----------------------------------------------------------------------|
| id                  | bigint        | PK                                                                    |
| company_id          | bigint        | FK → companies.id                                                     |
| supplier_id         | bigint        | FK → suppliers.id                                                     |
| supplier_invoice_id | bigint        | FK → supplier_invoices.id (nullable)                                  |
| account_id          | bigint        | FK → accounts.id. **Required:** Cash, Bank, Wallet, or card-clearing account paid from. |
| payment_reference   | varchar(100)  | **External reference:** bank transfer ID, cheque number, gateway transaction ID, etc. |
| amount              | decimal(15,2) | Payment amount                                                        |
| currency_id         | bigint        | FK → currencies.id (nullable)                                         |
| payment_date        | date          | Optional                                                              |
| status              | enum          | pending, completed, failed, cancelled                                 |
| created_by          | bigint        | FK → users.id                                                         |
| timestamps          |               | created_at, updated_at                                                |

**Indexes:** `(company_id, status)`, `(supplier_id, payment_date)`, `(supplier_invoice_id)`

### landed_costs

Landed cost allocation (shipping, duty, insurance, etc.) for accurate inventory valuation. Without it, unit cost stays at purchase price and inventory valuation is wrong when extra costs apply.

| Field             | Type          | Notes                                          |
|-------------------|---------------|-------------------------------------------------|
| id                | bigint        | PK                                              |
| company_id        | bigint        | FK → companies.id                               |
| purchase_id       | bigint        | FK → purchases.id (nullable)                    |
| goods_receipt_id  | bigint        | FK → goods_receipts.id (nullable)               |
| total_amount      | decimal(15,2) | Total landed cost                               |
| currency_id       | bigint        | FK → currencies.id (nullable)                   |
| status            | string        | draft, allocated, cancelled                     |
| created_by        | bigint        | FK → users.id                                   |
| timestamps        |               | created_at, updated_at                          |

### landed_cost_lines

| Field             | Type          | Notes                                                         |
|-------------------|---------------|---------------------------------------------------------------|
| id                | bigint        | PK                                                            |
| landed_cost_id    | bigint        | FK → landed_costs.id                                          |
| cost_type         | varchar(50)   | shipping, duty, insurance, handling, etc.                     |
| amount            | decimal(15,2) | Line amount                                                   |
| allocation_method | varchar(50)   | quantity, value, weight, manual (for allocating to receipt lines) |
| notes             | text          | Optional                                                      |
| timestamps        |               | created_at, updated_at                                        |

### landed_cost_allocations

Stores the **actual allocation result** of each landed cost line to each goods receipt line. Enables auditing, adjusting inventory valuation per receipt line, and tracing every cost component back to a specific product receipt.

| Field                 | Type          | Notes                                                    |
|-----------------------|---------------|----------------------------------------------------------|
| id                    | bigint        | PK                                                       |
| landed_cost_line_id   | bigint        | FK → landed_cost_lines.id (cascade delete)               |
| goods_receipt_line_id | bigint        | FK → goods_receipt_lines.id (cascade delete)             |
| allocated_amount      | decimal(15,2) | Amount allocated to this receipt line                    |
| timestamps            |               | created_at, updated_at                                   |

**Indexes:** `(landed_cost_line_id)`, `(goods_receipt_line_id)`

---

## Inventory Integration

When goods are received via `POST /api/purchases/{id}/receive`:

1. **Warehouse mismatch prevention:** Receipt warehouse is always the purchase's warehouse. Goods cannot be received to a different warehouse — this would break inventory tracking.
2. **Goods receipt** is created with status `posted`.
3. **Goods receipt lines** store **unit_cost** (snapshot from purchase line, or optional override in request) for correct valuation.
4. **purchase_lines.received_quantity** is incremented per line; **received_status** is auto-synced (`pending` → `partially_received` → `received`).
5. **Stock movements** are created with:
   - `type` = `purchase_in`
   - `reference_type` = `GoodsReceipt`
   - `reference_id` = goods receipt id
   - `quantity` = quantity received (positive)
   - `unit_cost` = from receipt line (snapshotted)
6. **Inventory** increases in the purchase's warehouse.
7. **Accounting:** Dr Inventory (1200), Cr GRNI (2100) for receipt total value (see 3-way matching below).

**Example:** Receive 30 of 50 ordered

- Goods receipt: 30 units, unit_cost snapshotted on each receipt line
- purchase_line.received_quantity += 30, received_status → `partially_received`
- Stock movement: +30 (reference: GoodsReceipt)
- Purchase status: `partially_received`
- Second receipt of 20 → purchase_line.received_status → `received`, purchase status → `received`

---

## Accounting Integration (3-Way Matching)

Enterprise ERPs use **Goods Received Not Invoiced (GRNI)** so the books stay correct when the invoice arrives weeks after receipt.

### Flow

1. **Goods receipt** (when goods arrive):
   - **Dr 1200 – Inventory**
   - **Cr 2100 – GRNI (Goods Received Not Invoiced)**
   - Journal: `reference_type` = `GoodsReceipt`, `entry_type` = `goods_receipt`.

2. **Supplier invoice posting** (when invoice arrives):
   - **Dr 2100 – GRNI**
   - **Cr 2000 – Accounts Payable**
   - Journal: `reference_type` = `SupplierInvoice`, `entry_type` = `purchase_invoice`.
   - Uses `exchange_rate` from invoice for multi-currency conversion.

3. **Supplier payment**:
   - **Dr 2000 – Accounts Payable**
   - **Cr** `account_id` (e.g. 1000 Cash, 1010 Bank)
   - Journal: `reference_type` = `SupplierPayment`, `entry_type` = `supplier_payment`.
   - `payment_reference` stored for bank reconciliation.

### Required accounts

| Code | Name                          | Type     |
|------|-------------------------------|----------|
| 1200 | Inventory                     | Asset    |
| 2100 | Goods Received Not Invoiced (GRNI) | Liability |
| 2000 | Accounts Payable              | Liability|

These are created by the database seeder. Run `php artisan db:seed` if missing.

---

## Models & Relationships

- **Supplier** – Belongs to Company, Currency (optional). Has many Purchase, SupplierInvoice, SupplierPayment. **Soft-deleted** (`deleted_at`) — ERP systems never hard-delete supplier master data.
- **Purchase** – Belongs to Company, Branch, Warehouse, Supplier. Has many PurchaseLine, GoodsReceipt, SupplierInvoice. Stores `expected_delivery_date` for procurement planning. Status: draft → confirmed → ordered → partially_received → received (or cancelled).
- **PurchaseLine** – Belongs to Purchase, Product. Has many GoodsReceiptLine. **received_quantity** cached; **received_status** (`pending`, `partially_received`, `received`) auto-synced via `syncReceivedStatus()`. Remaining = quantity − received_quantity.
- **GoodsReceipt** – Belongs to Company, Branch, Warehouse, Purchase, Creator, **Receiver** (`received_by`). Has many GoodsReceiptLine. Status: **draft, posted, cancelled** (API creates `posted`).
- **GoodsReceiptLine** – Belongs to GoodsReceipt, PurchaseLine, Product. **unit_cost** snapshotted at receipt.
- **SupplierInvoice** – Belongs to Company, Supplier, Purchase (optional). Has many SupplierPayment. Stores **supplier_invoice_number** (vendor's own reference) and **exchange_rate** (snapshot for multi-currency). **paid_amount** cached; remaining = total − paid_amount. Status = paid when paid_amount ≥ total.
- **SupplierPayment** – Belongs to Company, Supplier, SupplierInvoice (optional), **Account** (payment from). Stores **payment_reference** (bank transfer ID, cheque number, gateway txn ID). Stores **account_id** (Cash/Bank/Wallet).
- **LandedCost** – Belongs to Company, Purchase (optional), GoodsReceipt (optional). Has many LandedCostLine. For shipping, duty, etc. allocation.
- **LandedCostLine** – Belongs to LandedCost. Has many **LandedCostAllocation**. cost_type, amount, allocation_method.
- **LandedCostAllocation** – Belongs to LandedCostLine, GoodsReceiptLine. Stores `allocated_amount` per receipt line for auditable inventory valuation adjustments.

---

## API (Vue.js Ready)

All under `Authorization: Bearer <token>`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/suppliers | List suppliers (query: search, per_page) |
| POST | /api/suppliers | Create supplier |
| GET | /api/purchases | List purchases (query: supplier_id, status, branch_id, date_from, date_to, per_page) |
| POST | /api/purchases | Create purchase order. Accepts `expected_delivery_date`. |
| GET | /api/purchases/{id} | Purchase detail with lines (including received_status), receipts |
| POST | /api/purchases/{id}/confirm | Confirm purchase (draft → confirmed) |
| POST | /api/purchases/{id}/mark-ordered | Mark as ordered (draft/confirmed → ordered) |
| POST | /api/purchases/{id}/receive | Receive goods (partial or full). Body: lines[{ purchase_line_id, quantity_received, unit_cost? }], optional `received_by`. Warehouse always matches purchase warehouse. Auto-syncs line received_status. |
| GET | /api/supplier-invoices | List supplier invoices (query: supplier_id, status, per_page) |
| POST | /api/supplier-invoices | Create supplier invoice. Accepts `supplier_invoice_number` and `exchange_rate`. |
| POST | /api/supplier-invoices/{id}/post | Post invoice (Dr GRNI, Cr AP) |
| POST | /api/supplier-payments | Create supplier payment. Body must include `account_id` and optional `payment_reference`. Updates invoice paid_amount; invoice status → paid when paid_amount ≥ total. |

---

## Example: Create Purchase

```json
POST /api/purchases
{
  "supplier_id": 1,
  "branch_id": 1,
  "warehouse_id": 1,
  "purchase_date": "2026-03-23",
  "expected_delivery_date": "2026-04-05",
  "lines": [
    {
      "product_id": 10,
      "quantity": 50,
      "unit_cost": 100,
      "discount": 0
    }
  ]
}
```

Response: Purchase with `purchase_number` (e.g. PO-2026-00001), `total` = 5000, `status` = draft.

---

## Example: Receive Goods (Partial)

```json
POST /api/purchases/1/receive
{
  "received_by": 5,
  "lines": [
    {
      "purchase_line_id": 1,
      "quantity_received": 30
    }
  ]
}
```

- Creates goods receipt GR-2026-00001 (status `posted`); each receipt line stores unit_cost (from purchase line or optional override)
- `received_by` = 5 (warehouse staff who physically received the goods)
- Updates purchase_line: `received_quantity` += 30, `received_status` → `partially_received`
- Creates stock movement: +30 units, type `purchase_in`, reference GoodsReceipt
- Posts Dr Inventory Cr GRNI for receipt value
- Purchase status → `partially_received` (20 still to receive)

Second receipt:

```json
POST /api/purchases/1/receive
{
  "lines": [
    {
      "purchase_line_id": 1,
      "quantity_received": 20
    }
  ]
}
```

- purchase_line.received_status → `received`
- Purchase status → `received`

---

## Example: Supplier Invoice & Payment

**Create invoice (from purchase, with supplier's own reference):**

```json
POST /api/supplier-invoices
{
  "supplier_id": 1,
  "purchase_id": 1,
  "supplier_invoice_number": "INV-88931",
  "total": 5000,
  "exchange_rate": 1
}
```

System number: SI-2026-00001. Supplier ref: INV-88931.

**Post invoice:** `POST /api/supplier-invoices/1/post`  
→ Dr GRNI (2100) 5000, Cr Accounts Payable (2000) 5000

**Pay supplier:** (account_id + payment_reference)

```json
POST /api/supplier-payments
{
  "supplier_id": 1,
  "supplier_invoice_id": 1,
  "amount": 5000,
  "account_id": 1,
  "payment_reference": "TXN-2026-44820"
}
```

→ Dr Accounts Payable 5000, Cr account_id (e.g. Cash). Invoice paid_amount += 5000; if paid_amount ≥ total, status → paid. Payment reference stored for bank reconciliation.

---

## Validation & Business Rules

- **Purchase:** At least one line; quantity > 0, unit_cost ≥ 0. Branch and warehouse must belong to user's company. Optional `expected_delivery_date`.
- **Goods receipt:** `quantity_received` ≤ remaining (quantity − received_quantity) per line. Cannot receive for cancelled purchase. **Warehouse must match purchase warehouse** — prevents inventory mismatch. Optional `unit_cost` per line overrides snapshot from purchase line. Optional `received_by` for warehouse staff audit trail.
- **Supplier invoice:** Total > 0. Only draft invoices can be posted. `supplier_invoice_number` optional (vendor's reference). `exchange_rate` snapshot for multi-currency. paid_amount updated on each payment; status = paid when paid_amount ≥ total.
- **Supplier payment:** Amount > 0. **account_id** required (Cash/Bank/Wallet). `payment_reference` optional (bank transfer ID, cheque number, gateway txn). Account must belong to company and be active.
- **Partial receipts:** Fully supported; purchase_line.received_status tracks per-line state; purchase status = `partially_received` until all lines fully received, then `received`.
- **Supplier soft deletes:** Suppliers are soft-deleted (`deleted_at`). ERP systems never hard-delete supplier master data.

---

## Status Flow

| Entity           | Statuses                                                                 |
|------------------|--------------------------------------------------------------------------|
| Purchase         | draft → confirmed → ordered → partially_received → received, cancelled  |
| Purchase Line    | **pending → partially_received → received** (auto-synced on each receipt) |
| Goods Receipt    | **draft, posted, cancelled** (API creates `posted`; `draft` for future two-step workflows) |
| Supplier Invoice | draft → posted → paid (when paid_amount ≥ total), cancelled             |
| Supplier Payment | pending, completed, failed, cancelled                                   |

**Purchase status:** Use **confirm** and **mark-ordered** for analytics; **partially_received** is set when at least one receipt exists but not all lines are fully received.

**Purchase line received_status:** Eliminates per-request computation — no need to query goods_receipt_lines to determine line receiving state.

---

## Why This Design Is Enterprise Grade

- **received_quantity on purchase_lines** – Remaining = quantity − received_quantity without querying goods_receipt_lines (SAP, Odoo, NetSuite pattern).
- **received_status on purchase_lines** – Line-level receiving state (`pending`, `partially_received`, `received`) auto-synced; no runtime computation needed for analytics or status display.
- **unit_cost on goods_receipt_lines** – Cost snapshot at receipt for correct valuation when currency, invoice price, or landed cost changes.
- **paid_amount on supplier_invoices** – Partial payments supported; remaining = total − paid_amount without summing payments.
- **account_id on supplier_payments** – Explicit Cash/Bank/Wallet (or card clearing) for each payment.
- **payment_reference on supplier_payments** – Bank transfer IDs, cheque numbers, gateway txn IDs for reconciliation.
- **supplier_invoice_number on supplier_invoices** – Vendor's own invoice reference alongside system-generated number.
- **exchange_rate on supplier_invoices** – Snapshot for multi-currency accounting; entries convert to base currency.
- **received_by on goods_receipts** – Warehouse receiving staff audit trail (may differ from creator).
- **expected_delivery_date on purchases** – Procurement planning, supplier performance, late-delivery analytics.
- **Supplier soft deletes** – `deleted_at` ensures no hard deletion of master data.
- **Purchase status** – draft, confirmed, ordered, **partially_received**, received, cancelled for real ERP analytics.
- **Goods receipt status** – draft, **posted**, cancelled (replaces pending/completed for ERP consistency).
- **3-way matching (GRNI)** – Receipt: Dr Inventory Cr GRNI; Invoice: Dr GRNI Cr AP. Books correct when invoice arrives weeks later.
- **Landed costs** – `landed_costs`, `landed_cost_lines`, and **`landed_cost_allocations`** support shipping, duty, etc. with per-receipt-line allocation results for auditable inventory valuation.
- **Warehouse mismatch prevention** – Receipt warehouse must match purchase warehouse; validated in service layer.
- **Index optimization** – Explicit indexes on `purchase_lines(purchase_id)`, `goods_receipt_lines(goods_receipt_id, purchase_line_id)`, `supplier_payments(supplier_invoice_id)` for reporting performance.
- **Partial receipts** – Multiple goods receipts per purchase; status partially_received until fully received.
- **Tenant isolation** – All tables scoped by `company_id`; global scopes on models.
