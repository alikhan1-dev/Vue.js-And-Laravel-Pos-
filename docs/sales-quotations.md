## Step 4: POS Sales & Quotation Engine – Professional Integration with Inventory

Sales, quotations, and returns with warehouse-level stock integration, fully tenant-aware and movement-based. Designed for production-grade ERP/POS, fully consistent with the inventory engine.

---

### 1. System Architecture & Invariants

#### Overview

- **Source of truth**: Completed **sales** and **returns** are backed by one or more `stock_movements`.
- **Types mapping**:

| Sales Type | Stock Movement Type | Notes                                                       |
|-----------|---------------------|-------------------------------------------------------------|
| sale      | sale_out            | Deducts stock from the selected warehouse                   |
| quotation | none + reservation  | Does not move stock; creates `stock_reservations` per product |
| return    | return_in           | Increases stock; uses canonical `return_in` movements        |

- **Tenant invariants**:
  - `sale.company_id == branch.company_id == warehouse.company_id == user.company_id`.
  - `sale_lines.product_id` belongs to the same `company_id`.
  - `warehouse` must belong to the same `company_id` as the sale.
- **Warehouse–branch rule**: Every `sale_lines.warehouse_id` (including per-line overrides) must belong to `sales.branch_id`. Validated on create so that e.g. a sale for branch Karachi cannot reference a warehouse belonging to branch Lahore.

- **Immutability**:
  - Once a sale is **completed**, quantity changes are done via **returns** or **inventory adjustments**, never by inline update.
  - Audit logs are **append-only**.

- **Concurrency & isolation**:
  - Sale creation and quotation conversion lock the relevant `stock_cache` rows using `SELECT … FOR UPDATE` (as described in the Inventory Engine).
  - Multi-line, multi-warehouse operations are wrapped in a **single DB transaction** for atomicity.

---

### 2. Database Schema

#### 2.1 `sales`

| Field              | Type           | Notes                                                 |
|--------------------|----------------|-------------------------------------------------------|
| id                 | bigint         | PK (internal; do not expose in receipts/invoices)     |
| number             | string(32)     | Human-readable sale number, e.g. `SAL-2026-000012`; unique per `company_id`; used for receipts, invoices, POS UI |
| company_id         | bigint         | FK → `companies.id`                                   |
| branch_id          | bigint         | FK → `branches.id`                                    |
| warehouse_id       | bigint         | FK → `warehouses.id` (header-level default warehouse) |
| customer_id        | bigint         | Optional; used for warranty/accounting flows          |
| type               | ENUM (MySQL)   | `sale`, `quotation`, `return` (backed by PHP enum)    |
| status             | ENUM (MySQL)   | `pending`, `completed`, `cancelled` (PHP enum)       |
| total              | decimal(15,2)  | Sum of line subtotals                                 |
| currency           | string(10)     | ISO currency code (default `PKR`); supports multi-currency |
| exchange_rate      | decimal(15,6)  | Exchange rate to base currency (default `1.000000`)   |
| return_for_sale_id | bigint         | When `type=return`, links to original sale            |
| deleted_at         | timestamp      | Soft delete                                           |
| created_by         | bigint         | FK → `users.id` (creator)                             |
| approved_by        | bigint (null)  | FK → `users.id` (approver for returns/high-value)     |
| created_at         | timestamp      |                                                       |
| updated_at         | timestamp      |                                                       |

**Indexes:**

- `UNIQUE(company_id, number)` — for lookups by sale number; sequence per company per year.
- `(company_id, branch_id, type, status, created_at)`
- `INDEX(return_for_sale_id)`
- `INDEX(customer_id)`

#### 2.2 `sale_lines`

| Field             | Type           | Notes                                                |
|-------------------|----------------|------------------------------------------------------|
| id                | bigint         | PK                                                   |
| sale_id           | bigint         | FK → `sales.id`                                      |
| warehouse_id      | bigint (null)  | FK → `warehouses.id`; defaults to `sales.warehouse_id`; enables future multi-warehouse sales |
| product_id        | bigint         | FK → `products.id`                                   |
| variant_id        | bigint (null)  | FK → `product_variants.id`; optional per-line variant |
| quantity          | decimal(15,2)  | Sold/returned quantity                               |
| unit_price        | decimal(15,2)  | Price at time of sale                                |
| line_total        | decimal(15,2)  | Gross line total = `unit_price × quantity` (before discount); improves reporting/analytics speed |
| discount          | decimal(15,2)  | ≤ line_total                                         |
| subtotal          | decimal(15,2)  | `line_total − discount` = `(unit_price × quantity) − discount` |
| stock_movement_id | bigint (null)  | FK → `stock_movements.id` (null for quotation)      |
| reservation_id    | bigint (null)  | FK → `stock_reservations.id`; links quotation line to its reservation for traceability |
| lot_number        | string (null)  | Optional batch/lot label (mirrors underlying batch)  |
| imei_id           | bigint (null)  | Optional FK → `product_serials.id` for serialized items |
| deleted_at        | timestamp      | Soft delete                                          |
| created_at        | timestamp      |                                                      |
| updated_at        | timestamp      |                                                      |

**Indexes:**

- `INDEX(sale_id)`
- `INDEX(product_id, sale_id)`
- Optional design: `UNIQUE(sale_id, product_id, variant_id)` if you want one line per product/variant.

#### 2.3 `sale_audit_log`

| Field           | Type           | Notes                                                             |
|-----------------|----------------|-------------------------------------------------------------------|
| id              | bigint         | PK                                                                |
| sale_id         | bigint         | FK → `sales.id`                                                   |
| event           | ENUM (MySQL)   | `created`, `converted_to_sale`, `return_created`, `status_changed`, `stock_reserved` |
| from_status     | string (null)  | Previous status                                                   |
| to_status       | string (null)  | New status                                                        |
| from_type       | string (null)  | Previous type                                                     |
| to_type         | string (null)  | New type                                                          |
| metadata        | json (null)    | Movement IDs, lines, return_for_sale_id, etc.                     |
| idempotency_key | string (null)  | Optional: prevent duplicate operations                            |
| created_by      | bigint (null)  | FK → `users.id`                                                   |
| created_at      | timestamp      | Append-only                                                       |

**Indexes:**

- `INDEX(sale_id, created_at)`
- `INDEX(idempotency_key)`

**Metadata schema** (standardized via `SaleAuditMetadata` DTO in code):

- `lines_count` (int)
- `stock_movement_ids` (int[])
- `return_for_sale_id` (int, for returns)
- `lines` (array of `{ product_id, variant_id?, quantity, stock_movement_id, lot_number?, imei_id? }`)

---

### 3. Workflow & Atomicity

All flows run inside a **single database transaction**. Any failure rolls back the entire operation (sale, lines, movements, audit).

#### Create Sale

1. **Lock cache**: Lock `stock_cache` rows for the selected `warehouse_id` + involved `product_id`s using `lockForUpdate()`.
2. **Validate stock**:
   - Cannot go negative (respecting `product.allow_negative_stock` rules from Inventory Engine).
3. **Create records**:
   - Insert `sales` row with `type = sale`, `status = completed`, `currency`, `exchange_rate`; then assign human-readable `number` (format `SAL-YYYY-NNNNNN`, sequence per company per year).
   - Insert `sale_lines` with per-line `warehouse_id` (defaults to `sales.warehouse_id`; must belong to sale’s branch), `line_total` = `unit_price × quantity`, plus optional `variant_id`, `lot_number`, `imei_id`.
   - `SaleService` creates one or more `stock_movements` with type `sale_out` (including `variant_id`, `batch_id`, `serial_id`).
4. **Inventory updates**:
   - `StockMovementObserver` updates `stock_cache`, `batch_stock_cache`, `inventory_journal`, and alerts.
5. **Audit & events**:
   - Append row to `sale_audit_log` (event = `created`) with line-level metadata (including `variant_id`, `lot_number`, `imei_id` when applicable).

#### Create Quotation

1. **No stock deduction**:
   - Insert `sales` row with `type = quotation`, `status = pending`, `currency`, `exchange_rate`; assign `number` (SAL-YYYY-NNNNNN).
   - Insert `sale_lines` with `stock_movement_id = null`, per-line `warehouse_id` (must belong to sale’s branch), `line_total` = `unit_price × quantity`, plus optional `variant_id`, `lot_number`, `imei_id`.
2. **Stock reservations (enabled with direct linkage)**:
   - Aggregate required quantities per underlying physical product (expanding bundles into component products).
   - Insert rows into `stock_reservations` with:
     - `company_id`, `product_id`, `warehouse_id`
     - `quantity` (total reserved for that product in this quotation)
     - `reference_type = 'Quotation'`, `reference_id = sale.id`
     - `status = 'active'`
   - Each `sale_line` is linked to its reservation via `sale_lines.reservation_id` for end-to-end traceability.
   - These reservations are considered when validating future sales and quotation conversions (see **Concurrency** below).
3. **Audit**:
   - Append `sale_audit_log` event = `created` with metadata describing the quotation lines.

#### Convert Quotation → Sale

1. **Lock cache**:
   - Lock `stock_cache` for the quotation’s `warehouse_id` + products.
2. **Validate stock (reservation-aware)**:
   - Same validation as create sale; available stock is computed as `stock_cache.quantity - active_reservations`.
3. **Create movements**:
   - `SaleService` creates `sale_out` movements for each line (or bundle component).
   - Update `sales.type` to `sale`, `status` to `completed`.
4. **Reservations**:
   - All `stock_reservations` where `reference_type = 'Quotation'` and `reference_id = sale.id` are marked `status = 'released'` and stamped with `expires_at`.
5. **Audit**:
   - Append event = `converted_to_sale` with line-level movement metadata.

#### Process Return

1. **Validate quantities**:
   - Per line, return quantity ≤ original sale line quantity.
2. **Create records**:
   - Insert new `sales` row with `type = return`, `status = completed`, `return_for_sale_id` set; assign `number` (SAL-YYYY-NNNNNN).
   - Insert `sale_lines` with `line_total` = `unit_price × quantity`, referencing the returned products (and variants when applicable).
   - Create `stock_movements` with type `return_in` to add stock back into the original warehouse.
3. **Inventory updates**:
   - `StockMovementObserver` updates cache, journal, alerts as for any movement.
4. **Audit**:
   - Append event = `return_created` with `return_for_sale_id` and per-line movement metadata.

---

### 4. Validation & Error Semantics

All domain validation failures return **HTTP 422** with a **standardized JSON payload**:

```json
{
  "message": "Sale validation failed.",
  "errors": {
    "sale": ["Human-readable error message"]
  }
}
```

**Key validations:**

- Negative stock (after considering reservations) → 422.
- Return quantity > sold quantity → 422.
- Discount > line total (`unit_price × quantity`) → 422.
- Stock reservation conflicts (e.g. reserved in another branch/warehouse when reservations are enabled) → 422.
- **Warehouse–branch mismatch** → 422: if a line specifies `warehouse_id`, that warehouse must belong to the sale’s `branch_id` (e.g. sale branch Karachi with line warehouse from Lahore is rejected).
- **Duplicate IMEI/serial** → 422: same `serial_id` cannot appear twice in one request; `SerialSaleGuard` also rejects serials whose `product_serials.status = 'sold'` (prevents the same phone being sold twice across separate requests).

**Validation matrix:**

| Type      | Key Validation                                                                              | Response on failure |
|-----------|----------------------------------------------------------------------------------------------|---------------------|
| sale      | `lines.length > 0`, stock movements created, stock cannot go negative, no duplicate IMEI, line `warehouse_id` in sale’s branch | 422                 |
| quotation | `stock_movement_id` null, quotation reservations created consistently, line `warehouse_id` in sale’s branch               | 422                 |
| return    | `return_for_sale_id` required, lines reference original sale, return qty ≤ sold qty                                       | 422                 |

---

### 5. Event Ordering, Idempotency & Retries

- **Event / side-effect ordering (within a sale transaction)**:
  - `SaleService` creates `sales` + `sale_lines` (and any `stock_movements`) inside a single DB transaction.
  - `StockMovementObserver` updates `stock_cache`, `batch_stock_cache`, `inventory_journal`, and emits inventory events synchronously when each `stock_movement` is created.
  - `sale_audit_log` entries are written **inside the same transaction**, after core records have been prepared but before commit.
  - The net guarantee is: **either all of sale, lines, movements, cache/journal updates, and audit rows commit, or none do.**
- **Idempotency hooks**:
  - `sale_audit_log.idempotency_key` exists to correlate external retries and orchestrations.
  - Movement-level APIs (`/api/stock-movements`) support idempotency keys for individual stock operations; the sales engine reuses these primitives indirectly via `StockMovement`.
- **Retry behaviour**:
  - Because all core operations run in a single transaction, partial failures (e.g. exception in validation or inventory posting) roll back the entire sale.
  - Clients can safely retry operations that fail with **5xx** responses; business-rule violations remain **422** and must be fixed client-side.

---

### 6. Data Lineage

**Sale path:**

`sale` → `sale_lines` → `InventoryService::sale()` → `stock_movements (sale_out)` → `stock_cache` → `inventory_journal` → `inventory_alerts` / `inventory_events`.

**Return path:**

`sale (type=return)` → `sale_lines` → `SaleService::createReturn()` → `stock_movements (return_in)` → `stock_cache` → `inventory_journal` → warranty adjustments (see Step 6.1).

This guarantees full traceability from POS operations to inventory and accounting.

---

### 7. Customer & Warranty Linkage

- `sales.customer_id` is used by:
  - Warranty Engine (Step 6.1) to link `warranty_registrations` and claims to customers.
  - Accounting and reporting for customer-based KPIs.
- Returns involving serialized/IMEI products:
  - `imei_id` on `sale_lines` points at `product_serials.id`.
  - On completed sales, `WarrantyService` creates `warranty_registrations` per line and serial, based on product-level warranty configuration.
  - On returns, serials are marked as **returned** and disassociated from the original sale; the warranty layer can use `sale_audit_log` + `warranty_registrations` to recalculate remaining coverage or close warranties as needed (see Step 6.1).

> See **Warranty Engine (Step 6.1)** for full details on warranty registrations and claims.

---

### 8. API (Vue.js Ready)

All endpoints require `Authorization: Bearer <token>`.

| Method | Endpoint                    | Description                                                                 |
|--------|-----------------------------|-----------------------------------------------------------------------------|
| GET    | `/api/sales`                | List sales. Query: `type`, `branch_id`, `status`, `date_from`, `date_to`, `per_page`. Eager-loads `branch`, `warehouse`, `creator`, `lines.product`. |
| POST   | `/api/sales`                | Create sale or quotation. Body: `branch_id`, `warehouse_id`, `type` (`sale\|quotation`), optional `currency`, `exchange_rate`, `lines: [{product_id, variant_id?, warehouse_id?, quantity, unit_price, discount?, serial_id?}]`. |
| GET    | `/api/sales/{id}`           | Sale detail with lines, stock movement info, `number` (for receipts/invoices), and optional lot/IMEI fields. |
| POST   | `/api/sales/{id}/convert`   | Convert quotation → sale. Locks `stock_cache`, validates stock, creates movements. |
| POST   | `/api/sales/{id}/return`    | Create return. Optional body: `lines` for per-line return quantity overrides. |
| GET    | `/api/sales/{id}/stock-check` | Current stock per line; includes warehouse (id, name, code), per-line `sufficient`, and `all_sufficient`. |

---

### 9. Seeder

`DatabaseSeeder` creates (after products/warehouses):

- One **completed sale**: 5 units of sample product, one line linked to a `sale_out` movement.
- One **quotation**: 3 units, no movement.
- One **return**: 2 units against the first sale, one line linked to a `return_in` movement.

Run: `php artisan migrate --seed` or `php artisan db:seed`.

---

### 10. Listing Performance & Production Considerations

- **Listing performance**:
  - `GET /api/sales` eager-loads `branch`, `warehouse`, `creator`, and `lines.product` in a single query set to avoid N+1 when serializing.
  - Pagination defaults and caps (e.g. `per_page` default 25, max 100) to protect performance.

- **Read replicas**:
  - Use read DB connections for heavy `/api/sales` listing/reporting, while writes go to the primary DB, similar to the inventory engine.

- **Caching**:
  - Frequently accessed recent sales can be cached in Redis for POS UI dashboards (e.g. last 10 receipts per branch).

- **Concurrency**:
  - Multi-terminal concurrency is handled via `SELECT … FOR UPDATE` on `stock_cache` rows, as described in the Inventory Engine.

---

### 11. Front-end Integration (Vue.js)

- **Stock-check before submit**:
  - Call `GET /api/sales/{id}/stock-check` before confirming a sale or converting a quotation.
  - Use `all_sufficient` to disable the submit button or show a warning when `all_sufficient === false`.
  - Use per-line `sufficient` and `current_stock` to show which lines are short and by how much.

- **Multi-line / multi-warehouse UX**:
  - The stock-check response includes warehouse info and per-line sufficiency.
  - UX can suggest:
    - Reducing quantities.
    - Changing warehouse.
    - Delaying sale when stock is insufficient.

---

### 12. Verification

- **Tenant isolation**:
  - List sales as a user of Company A; create a sale for Company B in tinker; listing still shows only Company A.
- **Stock integration**:
  - Create sale → `GET /api/products/{id}/stock` shows reduced quantity; create return → stock increases.
- **Quotation behaviour**:
  - Create quotation → no movements; `stock_reservations` rows are created for the required products.
  - `POST /api/sales/{id}/convert` → `sale_out` movements created, type and status updated, and reservations for that quotation are marked as released.
- **Return validation**:
  - `POST /api/sales/{id}/return` with a line quantity greater than the original sale line → 422.
- **Stock-check**:
  - `GET /api/sales/{id}/stock-check` returns warehouse info, `current_stock`, per-line `sufficient`, and `all_sufficient`.
- **Multi-currency**:
  - Create a sale with `currency = 'USD'`, `exchange_rate = 280`; verify `sales.currency` and `sales.exchange_rate` are stored and returned via API.
- **Per-line warehouse**:
  - Create a sale with `lines[].warehouse_id` set per line; verify `sale_lines.warehouse_id` is populated correctly (defaults to `sales.warehouse_id` when omitted).
- **Reservation linkage**:
  - Create a quotation; verify `sale_lines.reservation_id` points to the corresponding `stock_reservations` row.
- **Duplicate IMEI prevention**:
  - Attempt to sell the same `serial_id` twice in one request → 422 with "Duplicate serial_id" error.
  - Attempt to sell a serial with `status = 'sold'` → 422 with "already sold" error.
- **Sale number**:
  - After create (sale, quotation, or return), `sales.number` is set to `SAL-YYYY-NNNNNN` (sequence per company per year). Use `number` (not `id`) on receipts, invoices, and POS UI.
- **Line total & warehouse–branch**:
  - Each `sale_line` has `line_total` = `unit_price × quantity` for reporting. Any per-line `warehouse_id` is validated to belong to the sale’s branch.
- **Audit**:
  - After create/convert/return, `sale_audit_log` has a row for that sale with `event`, `metadata`, and optionally `idempotency_key`.

