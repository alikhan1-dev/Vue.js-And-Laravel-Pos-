# Step 3, 6 & 6.1: Inventory, Stock & Warranty Engine

Movement-based inventory with categories, brands, units, product variants, batch/serial tracking, inter-warehouse transfers, warehouse stock reporting, and a tenant-aware warranty management system.

---

## System Architecture

- **Single source of truth:** The **movement ledger** (`stock_movements`) is append-only. Every stock change is a new row; no updates or deletes. Corrections are done by posting new movements (e.g. adjustment_in/out).
- **Movement UUID:** Every movement is assigned a globally-unique `uuid` (auto-generated via `Str::uuid()` on `creating`). API route model binding resolves movements by UUID (`getRouteKeyName()`), making it safe for external integrations, distributed systems, and microservices.
- **Event sourcing preparation:** Each movement optionally carries `event_id` (UUID grouping related movements, e.g. both legs of a transfer share the same event_id) and `source` (POS, API, IMPORT, TRANSFER, ADJUSTMENT, RETURN, PRODUCTION). Useful for debugging, replay, and audit trails. Source constants are defined on `StockMovement::VALID_SOURCES`.
- **Materialized snapshot:** `stock_cache` holds per-(company, product, variant, warehouse) quantity and reserved_quantity for fast POS and reporting. It is updated by `StockMovementObserver` on each new movement. Use `php artisan inventory:rebuild-cache` to fully rebuild from the ledger (e.g. after recovery or audit).
- **Batch stock cache:** `batch_stock_cache` holds per-(batch, product, warehouse) quantity for fast batch-level reads. Updated by the observer on every movement that carries a `batch_id`. Use `php artisan inventory:rebuild-batch-cache` to rebuild from the ledger. Eliminates the need for `SUM(stock_movements)` per batch on every read.
- **Availability:** Available quantity = `stock_cache.quantity - stock_cache.reserved_quantity`. Reservations come from `stock_reservations` (e.g. quotations, carts). Sales and transfers must not exceed available quantity unless `product.allow_negative_stock` is true.
- **Inventory alerts:** The `inventory_alerts` table persists actionable alerts (low_stock, expiry_near, serial_conflict, negative_stock_attempt) with severity levels (info, warning, critical) for dashboards and notification workflows. Alerts are created by `InventoryAlertService` and can be resolved by users. Scoped by `company_id`.
- **Tenant isolation:** All inventory is scoped by `company_id` (via product and movement). Warehouses belong to branches, which belong to companies.

---

## Movement Flow

1. **Creation:** Controllers or services create a `StockMovement` via **InventoryService** or directly, with `type`, `quantity` (signed), `product_id`, `warehouse_id`, optional `movement_date` (ledger date; **NOT NULL**, default CURRENT_TIMESTAMP), optional `source` (POS, API, IMPORT, etc.), optional `event_id` (UUID grouping related movements), optional `reason_code` (stock_count, damage, expired, theft, manual_adjustment, etc. for audits), and other fields.
2. **Model behaviour:** On `creating`:
   - `uuid` is auto-generated via `Str::uuid()` if not already set.
   - `company_id` is filled from the product if empty.
   - `movement_date` defaults to `now()` if not set.
   - Serialized products enforce `quantity = 1` per movement.
   - Quantity sign is normalized so out-types are stored negative.
3. **Observer:** After save, `StockMovementObserver` runs:
   - Calls `InventoryCostService::updateAverageCostIfApplicable()` for stock-increasing types (purchase_in, adjustment_in, return_in, production_in, initial_stock) so product `average_cost` is updated before cache.
   - Updates or creates `stock_cache` by adding the movement's signed quantity to the matching (product_id, variant_id, warehouse_id) row.
   - Updates or creates `batch_stock_cache` if the movement carries a `batch_id` (materialized batch-level quantity).
   - Logs the change via `InventoryAuditLogService` (old_quantity, new_quantity, action, user_id).
   - Creates a persistent `InventoryAlert` (via `InventoryAlertService`) if stock goes negative.
4. **Events:** `StockMovementCreated` is dispatched for every new movement (for audit, accounting, notifications). `NegativeStockDetected` is dispatched if cache goes negative.
5. **Journal:** `InventoryJournalService::postFromMovement()` posts double-entry rows to `inventory_journal` (if the table exists) for accounting integration (e.g. Dr Inventory / Cr AP for purchase_in, Dr COGS / Cr Inventory for sale_out).
6. **Immutability:** Updating or deleting movements is disabled at the model level. To reverse or correct, post a new movement.

---

## Cost Calculation (Weighted Average Cost)

- **Column:** `products.average_cost` (DECIMAL 15,4) holds the current weighted average cost per unit.
- **When it updates:** Only when stock **increases** (movement types: `purchase_in`, `adjustment_in`, `return_in`, `production_in`, `initial_stock`). `InventoryCostService` runs inside `StockMovementObserver` **before** the cache is updated, using current cache quantity and product average cost:
  - `new_avg_cost = ((current_stock * current_avg_cost) + (incoming_qty * unit_cost)) / (current_stock + incoming_qty)`  
  - If current stock is 0, `average_cost` is set to the movement's `unit_cost`.
- **Sales:** `sale_out` and other out-types do **not** recalculate cost; they only consume stock. Sales and reporting read the existing `average_cost`.

---

## Serial Lifecycle

- **Uniqueness:** `product_serials.serial_number` is unique. The same serial cannot be sold twice.
- **States:** `in_stock` -> `sold` -> (optional) `returned`.
- **Before sale_out:** For serialized products, the sale line must include `serial_id`. `SerialSaleGuard` validates: serial exists, `status = in_stock`, and serial's warehouse matches the sale warehouse. The movement is created with that `serial_id`.
- **On sale:** After the movement is created, the serial is updated: `status = sold`, `sale_id` and reference set. This is done by `SerialSaleGuard::markSerialSold()`.
- **On return:** When a return sale is created for an original sale, all `product_serials` rows with `sale_id = original_sale_id` are updated to `status = returned`, `sale_id` (and reference) cleared, so they can be restocked or sold again.
- **DB safeguard:** A unique index on `stock_movements(serial_id, reference_type, reference_id)` prevents the same serial being used twice for the same reference (e.g. same sale).
- **Serial warehouse on transfer:** When a serialized unit is transferred, the serial's `warehouse_id` is updated to the destination after both movements are created.
- **Serial transfer validation (before transfer_out):** When `serial_id` is provided, **TransferService** validates: (1) serial exists, (2) serial's warehouse = source warehouse (`from_warehouse_id`), (3) serial `status = in_stock`. If any check fails, the transfer is rejected.

---

## Transfer Atomicity & Concurrency

- **Movement atomicity:** Transfers require two movements (`transfer_out` and `transfer_in`). If only one were recorded (e.g. crash after transfer_out), stock would disappear. **TransferService::executeTransfer()** runs the entire operation inside **DB::transaction()**. Either both movements and the serial warehouse update commit, or none do.
- **Prevent negative stock race (multi-terminal POS):** Under concurrency, two POS terminals can both pass "available stock" validation and then both sell, resulting in negative stock. The solution is **row locking**: inside the same DB transaction, **SELECT ... FOR UPDATE** is used on the relevant `stock_cache` rows (e.g. `StockCache::lockForUpdate()`). Sale and transfer flows lock affected cache rows before validating and creating movements. This ensures only one transaction at a time can reduce stock for that product/warehouse.

---

## InventoryService Layer

All stock logic is centralized in **InventoryService** so controllers stay thin and behaviour is consistent:

- **InventoryService::purchase()** -- stock received from supplier (`purchase_in`)
- **InventoryService::sale()** -- delegates to SaleService (creates sale + lines + movements)
- **InventoryService::transfer()** -- delegates to TransferService::executeTransfer() (uses DB::transaction())
- **InventoryService::adjustmentIn()** / **adjustmentOut()** -- correction movements (e.g. after stock count)
- **InventoryService::returnIn()** -- customer return
- **InventoryService::damage()** -- damaged stock removed (optional damage_report_id)
- **InventoryService::productionIn()** / **productionOut()** -- finished goods in, raw material out

Use these methods from controllers or jobs instead of creating movements directly.

---

## Soft Delete Protection

Critical inventory entities **cannot be deleted** (soft or force) if stock movements reference them. This keeps the movement ledger auditable and referentially consistent.

- **Product** — Deleting or force-deleting is blocked if any `stock_movements.product_id` references it. Use **deactivate** (`is_active = false`) or archive instead.
- **Warehouse** — Deletion is blocked if any movement has `warehouse_id` set to it.
- **ProductBatch** — Deletion is blocked if any movement has `batch_id` set to it.
- **ProductVariant** — Deleting or force-deleting is blocked if any movement has `variant_id` set to it.

**Implementation:** `InventoryDeletionGuard` (`App\Services\InventoryDeletionGuard`) is invoked from each model’s `deleting` (and `forceDeleting` where applicable) event. If movements exist, it throws `CannotDeleteEntityWithMovementsException`, which returns **422** for JSON API requests. Use `canDeleteProduct()`, `canDeleteWarehouse()`, `canDeleteBatch()`, or `canDeleteVariant()` to check before showing a delete button.

---

## Idempotency Protection

APIs can accidentally submit duplicate requests (e.g. purchase posted twice due to double-click or retry). To prevent duplicate movements, the client sends an **idempotency_key** (unique per logical operation, e.g. UUID or client-generated token).

- **Column:** `stock_movements.idempotency_key` (varchar 64, nullable, **unique**). When present, the system rejects duplicates.
- **Behaviour:** Before creating a movement, if `idempotency_key` is provided, the API looks up an existing movement with that key. If found, it returns **200** with the existing movement (no duplicate created). If not found, it creates the movement and returns **201**.
- **Usage:** Include `idempotency_key` in `POST /api/stock-movements` (and in any other inventory endpoints that create movements). Use the same key for retries of the same logical request. Keys should be unique per operation (e.g. one key per purchase receipt).
- **Helper:** `StockMovement::findByIdempotencyKey(string $key)` returns the existing movement or null.

---

## Movement Versioning

To track corrections and reversals in the audit trail:

- **version** — `stock_movements.version` (tinyint, default 1). Revision number for the movement; useful for future optimistic locking or schema versioning.
- **reversal_movement_id** — `stock_movements.reversal_movement_id` (nullable, FK to `stock_movements.id`). When movement B is a reversal of movement A, set `B.reversal_movement_id = A.id`. Then:
  - `A->reversedBy()` returns movement B (the one that reversed A).
  - `B->reversalOf()` returns movement A (the original that B reverses).

Example: Movement A (adjustment_in +10). Later, correction: create movement B (adjustment_out -10) with `B.reversal_movement_id = A.id`. Audits and reports can follow the link to see the correction.

---

## Database Schema

### categories

| Field      | Type          | Notes                                  |
|------------|---------------|----------------------------------------|
| id         | bigint        | PK                                     |
| company_id | bigint        | FK -> companies.id                     |
| name       | varchar(255)  | Category name                          |
| parent_id  | bigint (null) | FK -> categories.id (self-referencing) |
| is_active  | boolean       | Default true                           |
| timestamps |               | created_at, updated_at                 |
| deleted_at | datetime (null)| Soft delete (nullable)                 |

Unique: `(company_id, name, parent_id)`. Supports nested hierarchy.

### brands

| Field      | Type         | Notes              |
|------------|--------------|--------------------|
| id         | bigint       | PK                 |
| company_id | bigint       | FK -> companies.id |
| name       | varchar(255) | Brand name         |
| is_active  | boolean      | Default true       |
| timestamps |              | created_at, updated_at |
| deleted_at | datetime (null)| Soft delete           |

Unique: `(company_id, name)`.

### units

| Field      | Type         | Notes                       |
|------------|--------------|-----------------------------|
| id         | bigint       | PK                          |
| company_id | bigint       | FK -> companies.id          |
| name       | varchar(100) | e.g. "Piece", "Kilogram"   |
| short_name | varchar(20)  | e.g. "pc", "kg"            |
| is_active  | boolean      | Default true                |
| timestamps |              | created_at, updated_at      |
| deleted_at | datetime (null)| Soft delete                |

Unique: `(company_id, short_name)`.

### products

| Field                | Type           | Notes                                       |
|----------------------|----------------|---------------------------------------------|
| id                   | bigint         | PK                                          |
| company_id           | bigint         | FK -> companies.id                          |
| name                 | varchar(255)   | Product name                                |
| sku                  | varchar(50)    | Unique per company                          |
| barcode              | varchar(50)    | Optional, unique per company                |
| description          | text           | Optional                                    |
| unit_price           | decimal(15,2)  | Legacy base price                           |
| uom                  | varchar(20)    | Optional unit of measure (config/pos.php)   |
| is_active            | boolean        | Default true                                |
| category_id          | bigint (null)  | FK -> categories.id                         |
| brand_id             | bigint (null)  | FK -> brands.id                             |
| unit_id              | bigint (null)  | FK -> units.id                              |
| type                 | varchar(20)    | `simple` or `variable` (default: simple)    |
| cost_price           | decimal(15,4)  | Cost/purchase price                         |
| selling_price        | decimal(15,4)  | Selling price                               |
| track_stock          | boolean        | Default true                                |
| track_serial         | boolean        | Default false                               |
| track_batch          | boolean        | Default false                               |
| allow_negative_stock | boolean        | Default false (block oversell when false)   |
| average_cost         | decimal(15,4)  | Weighted average cost (updated on in-movements) |
| timestamps           |                | created_at, updated_at                      |
| deleted_at           | datetime (null)| Soft delete                                 |

Unique per company: `(company_id, sku)`, `(company_id, barcode)`. **Indexes:** `products_barcode_index` (barcode), `products_sku_index` (sku) for fast POS barcode/SKU lookups.

### product_units (per-product units & conversion)

| Field                     | Type           | Notes                                             |
|---------------------------|----------------|---------------------------------------------------|
| id                        | bigint         | PK                                                |
| product_id                | bigint         | FK -> products.id                                 |
| unit_name                 | varchar(50)    | e.g. "box", "kg", "liter"                         |
| conversion_factor_to_base | decimal(18,6)  | Multiplier to convert this unit to base UOM      |
| is_default                | boolean        | Marks the primary unit for this product          |
| timestamps                |                | created_at, updated_at                            |

Example: base unit = piece, `unit_name = "box"` with `conversion_factor_to_base = 12` means 1 box = 12 pieces.

### product_variants

| Field         | Type          | Notes                            |
|---------------|---------------|----------------------------------|
| id            | bigint        | PK                               |
| product_id    | bigint        | FK -> products.id (cascade)      |
| name          | varchar(255)  | e.g. "Black", "128GB"           |
| sku           | varchar(50)   | Unique per product               |
| cost_price    | decimal(15,4) | Variant-specific cost            |
| selling_price | decimal(15,4) | Variant-specific sell price      |
| is_active     | boolean       | Default true                     |
| timestamps    |               | created_at, updated_at           |
| deleted_at    | datetime (null)| Soft delete                     |

Unique: `(product_id, sku)`.

### product_bundles (kits / bundles)

| Field               | Type          | Notes                                                         |
|---------------------|---------------|---------------------------------------------------------------|
| id                  | bigint        | PK                                                            |
| bundle_product_id   | bigint        | FK -> products.id (the bundle/kit SKU shown on the invoice)  |
| component_product_id| bigint        | FK -> products.id (physical component SKU)                    |
| quantity            | decimal(15,4) | Component quantity per 1 unit of bundle                       |
| is_active           | boolean       | Whether this mapping is currently used                        |
| timestamps          |               | created_at, updated_at                                        |

Unique: `(bundle_product_id, component_product_id)`.

### product_batches

| Field            | Type          | Notes                                        |
|------------------|---------------|----------------------------------------------|
| id               | bigint        | PK                                           |
| product_id       | bigint        | FK -> products.id (cascade)                  |
| warehouse_id     | bigint        | FK -> warehouses.id (cascade)                |
| manufacture_date | date (null)   | Optional manufacturing/production date       |
| batch_number     | varchar(100)  | Batch / lot identifier                       |
| expiry_date      | date (null)   | Optional expiry (used for FEFO)              |
| timestamps       |               | created_at, updated_at                       |

Unique: `(product_id, warehouse_id, batch_number)`.

**Batch quantity:** The `batch_stock_cache` table holds the materialized quantity per batch. Alternatively, batch stock can be derived from the movement ledger: **batch stock = SUM(stock_movements.quantity) WHERE batch_id = ?**. The cache is faster; the ledger is the source of truth.

### product_serials

| Field          | Type           | Notes                                                        |
|----------------|----------------|--------------------------------------------------------------|
| id             | bigint         | PK                                                           |
| product_id     | bigint         | FK -> products.id (cascade)                                  |
| warehouse_id   | bigint         | FK -> warehouses.id (cascade)                                |
| sale_id        | bigint (null)  | FK -> sales.id (nullable; set when sold)                     |
| serial_number  | varchar(255)   | Globally unique (IMEI/serial)                               |
| status         | ENUM           | `in_stock`, `sold`, `returned`                               |
| reference_type | varchar(50)    | Optional polymorphic (e.g. Sale, WarrantyClaim)             |
| reference_id   | bigint (null)  | Optional polymorphic id                                     |
| timestamps     |                | created_at, updated_at                                       |

**Pro tip:** `warranty_registrations.serial_id` -> `product_serials.id` gives a strict link between warranty, sale, and a specific physical unit. Serialized products should always be sold with a concrete `serial_id` in the sale line.

### stock_movements

| Field             | Type           | Notes                                       |
|-------------------|----------------|---------------------------------------------|
| id                | bigint         | PK                                          |
| **uuid**          | **uuid**       | **Globally unique; auto-generated via Str::uuid(). Used for API route model binding and external integrations.** |
| **event_id**      | **uuid (null)**| **Groups related movements (e.g. both legs of a transfer). For event sourcing and debugging.** |
| **idempotency_key** | **varchar(64) (null)** | **Unique key from client to prevent duplicate submission (e.g. purchase posted twice). If provided and a movement already exists with this key, API returns 200 with existing movement.** |
| **source**        | **varchar(30) (null)** | **Origin: POS, API, IMPORT, TRANSFER, ADJUSTMENT, RETURN, PRODUCTION. See StockMovement::VALID_SOURCES.** |
| **reason_code**   | **varchar(50) (null)** | **Audit reason: stock_count, damage, expired, theft, manual_adjustment, transfer, sale, purchase, return, production, initial. See MovementReasonCode enum.** |
| **version**       | **tinyint**   | **Revision number; default 1. Used for audit and future optimistic locking.** |
| company_id        | bigint (null)  | FK -> companies.id (auto-filled from product) |
| product_id        | bigint         | FK -> products.id                           |
| variant_id        | bigint (null)  | FK -> product_variants.id                   |
| warehouse_id      | bigint         | FK -> warehouses.id                         |
| quantity          | decimal(18,4)  | Signed quantity (+ in, - out); precision for kg/liter/meter |
| unit_cost         | decimal(15,4)  | Optional cost at time of movement           |
| type              | ENUM (MySQL)   | DB-level enum; see movement types below     |
| reference_type    | string         | e.g. PurchaseInvoice, Sale, Transfer        |
| reference_id      | bigint         | FK to reference record                      |
| movement_date     | datetime       | Ledger date; NOT NULL, default CURRENT_TIMESTAMP (required for audit) |
| stock_count_id    | bigint (null)  | FK -> stock_counts.id (for adjustment_in/out from count) |
| damage_report_id  | bigint (null)  | FK -> damage_reports.id (for damage_out)    |
| batch_id          | bigint (null)  | FK -> product_batches.id                    |
| serial_id         | bigint (null)  | FK -> product_serials.id                    |
| **reversal_movement_id** | **bigint (null)** | **FK -> stock_movements.id. Set when this movement reverses another (audit trail).** |
| created_by        | bigint         | FK -> users.id (audit)                      |
| timestamps        |                | created_at, updated_at                      |

**Movement types (with explanations):**

| Type | Meaning |
|------|---------|
| `purchase_in` | Stock received from supplier |
| `sale_out` | Stock sold to customer |
| `transfer_out` | Stock leaving warehouse (source) |
| `transfer_in` | Stock arriving at warehouse (destination) |
| `adjustment_in` | Correction increase (e.g. after stock count) |
| `adjustment_out` | Correction decrease |
| `return_in` | Customer return (goods back into stock) |
| `return_out` | Supplier return (goods sent back) |
| `damage_out` | Damaged stock removed (link damage_report_id for audit) |
| `production_in` | Finished goods into stock |
| `production_out` | Raw material consumption |
| `warranty_replacement_out` | Replacement unit out for warranty claim |
| `initial_stock` | Opening balance / initial load |

Enforced via `App\Enums\StockMovementType`.

**Stock rule:** `stock = SUM(quantity)` (signed quantities).

**Immutability:** Rows are append-only. Updates and deletes are disabled at the model level. To correct stock, record a new movement (e.g. adjustment_in/out, damage_out, initial_stock).

**Auto-fill company_id:** On `creating`, `company_id` is auto-populated from the product's `company_id` for backward compatibility. Existing code that omits `company_id` continues to work.

**UUID for APIs:** Each movement gets a unique `uuid` for safe external references. Use `StockMovement::findByUuid($uuid)` or route model binding (resolves by uuid automatically). Indexed with `UNIQUE(uuid)`.

**Event sourcing fields:** `event_id` groups movements belonging to the same business event (e.g. a transfer creates two movements with the same `event_id`). `source` identifies the origin channel. Both are nullable for backward compatibility.

### stock_cache (warehouse stock snapshot)

| Field             | Type           | Notes                                        |
|-------------------|----------------|----------------------------------------------|
| id                | bigint         | PK                                           |
| company_id        | bigint (null)  | FK -> companies.id                           |
| product_id        | bigint         | FK -> products.id                            |
| warehouse_id      | bigint         | FK -> warehouses.id                          |
| variant_id        | bigint (null)  | Optional variant-level row (per SKU/option)  |
| quantity          | decimal(18,4)  | Cached on-hand quantity (same precision as movements to avoid rounding drift) |
| reserved_quantity | decimal(18,4)  | Reserved; recomputed from active `stock_reservations` (see rebuild-cache) |
| reorder_level     | decimal(15,2)  | Threshold for low-stock alerting             |
| reorder_quantity  | decimal(15,2)  | Suggested reorder quantity when replenishing |
| timestamps        |                | created_at, updated_at                       |

**Critical unique index:** `UNIQUE(company_id, warehouse_id, product_id, variant_id)` to prevent duplicate rows.

Source of truth for quantities remains `stock_movements` (on hand) and `stock_reservations` (reserved). `stock_cache` is a materialized snapshot for high-performance queries. Accessors:

- `available_quantity = quantity - reserved_quantity`
- `reorder_level` / `reorder_quantity` drive the **Reorder Engine** (see LowStockService) and procurement dashboards.

### batch_stock_cache (materialized batch-level stock)

Materialized batch-level stock cache. Updated by `StockMovementObserver` whenever a movement carries a `batch_id`. Eliminates the `SUM(stock_movements)` per batch on every read when batches grow large.

| Field        | Type           | Notes                                       |
|--------------|----------------|---------------------------------------------|
| id           | bigint         | PK                                          |
| company_id   | bigint (null)  | FK -> companies.id                          |
| batch_id     | bigint         | FK -> product_batches.id (cascade)          |
| product_id   | bigint         | FK -> products.id (cascade)                 |
| warehouse_id | bigint         | FK -> warehouses.id (cascade)               |
| quantity     | decimal(18,4)  | Cached batch-level on-hand quantity         |
| timestamps   |                | created_at, updated_at                      |

**Unique index:** `UNIQUE(batch_id, product_id, warehouse_id)`.

**Rebuild command:** `php artisan inventory:rebuild-batch-cache` (optional `--company=ID`).

**How it works:** When `StockMovementObserver::created()` fires and the movement has a `batch_id`, the observer calls `syncBatchStockCache()` which either increments the existing row or inserts a new one -- identical pattern to `stock_cache`.

### stock_snapshots (daily snapshot history)

Optional daily (or periodic) snapshots of stock for fast analytics, daily reports, and trend graphs without scanning the full movement ledger.

| Field          | Type           | Notes                                        |
|----------------|----------------|----------------------------------------------|
| id             | bigint         | PK                                           |
| company_id     | bigint (null)  | FK -> companies.id                           |
| snapshot_date  | date           | Snapshot date (e.g. end-of-day)              |
| product_id     | bigint         | FK -> products.id                            |
| warehouse_id   | bigint         | FK -> warehouses.id                          |
| variant_id     | bigint (null)  | Optional variant                             |
| quantity       | decimal(18,4)  | Quantity at snapshot time                    |
| timestamps     |                | created_at, updated_at                       |

**Unique:** `(snapshot_date, product_id, warehouse_id, variant_id)`. Indexes: `(company_id, snapshot_date)`, `(product_id, snapshot_date)`.

**Command:** `php artisan inventory:snapshot` (optional `--date=Y-m-d`, `--company=ID`). Reads from `stock_cache` and upserts into `stock_snapshots`. Schedule daily (e.g. after midnight) for end-of-day reports and trend graphs.

### stock_reservations (POS concurrency)

Reservations hold stock for quotations, carts, or orders so it is not sold elsewhere. Without `expires_at`, reservations can become dead and block stock indefinitely.

| Field           | Type           | Notes                                        |
|-----------------|----------------|----------------------------------------------|
| id              | bigint         | PK                                           |
| company_id      | bigint         | FK -> companies.id                           |
| product_id      | bigint         | FK -> products.id                            |
| variant_id      | bigint (null)  | FK -> product_variants.id (nullable)         |
| warehouse_id    | bigint         | FK -> warehouses.id                           |
| quantity        | decimal(15,2)  | Reserved quantity (positive)                 |
| reference_type  | varchar(50)    | e.g. Quotation, Cart, Order                   |
| reference_id    | bigint         | ID of the quotation/order/cart               |
| expires_at      | datetime (null)| When the reservation auto-expires (optional) |
| status          | enum           | `active`, `released`, `cancelled`            |
| timestamps      |                | created_at, updated_at                       |

Indexes: `(company_id, product_id, warehouse_id)`, `(reference_type, reference_id)`, `status`. **reserved_quantity** in `stock_cache` is not stored on reservations; it is **recomputed from active reservations** (status = active) when running `php artisan inventory:rebuild-cache` or by application logic that aggregates `SUM(stock_reservations.quantity)` per (company, product, variant, warehouse) and writes to `stock_cache.reserved_quantity`.

### inventory_alerts (persistent notifications)

Persistent inventory alerts for dashboards, notification workflows, and operational awareness. Created by `InventoryAlertService`; resolved manually or by system rules.

| Field           | Type              | Notes                                        |
|-----------------|-------------------|----------------------------------------------|
| id              | bigint            | PK                                           |
| company_id      | bigint            | FK -> companies.id (cascade)                 |
| product_id      | bigint (null)     | FK -> products.id (nullable)                 |
| warehouse_id    | bigint (null)     | FK -> warehouses.id (nullable)               |
| variant_id      | bigint (null)     | Optional variant reference                   |
| batch_id        | bigint (null)     | Optional batch reference                     |
| alert_type      | varchar(50)       | `low_stock`, `expiry_near`, `serial_conflict`, `negative_stock_attempt` |
| severity        | varchar(20)       | `info`, `warning`, `critical` (default: warning) |
| message         | text (null)       | Human-readable alert description             |
| reference_type  | varchar(50) (null)| Optional polymorphic reference               |
| reference_id    | bigint (null)     | Optional polymorphic id                      |
| is_resolved     | boolean           | Default false                                |
| resolved_at     | datetime (null)   | When the alert was resolved                  |
| resolved_by     | bigint (null)     | FK -> users.id (who resolved)                |
| timestamps      |                   | created_at, updated_at                       |

**Indexes:** `(company_id, alert_type, is_resolved)`, `(product_id, warehouse_id)`, `created_at`.

**Alert types:**

| Type | Trigger | Severity |
|------|---------|----------|
| `low_stock` | Stock falls below reorder_level | warning (critical if qty <= 0) |
| `expiry_near` | Batch expiry within threshold days | warning (critical if <= 7 days) |
| `serial_conflict` | Duplicate serial attempt or status conflict | critical |
| `negative_stock_attempt` | Stock goes negative after movement | critical |

**Usage:** Query `InventoryAlert::unresolved()->ofType('low_stock')` for dashboard widgets. Call `$alert->resolve($userId)` to mark resolved.

**InventoryAlertService** methods:

- `lowStock(Product, Warehouse, currentQty)` -- creates low_stock alert
- `negativeStock(Product, Warehouse, currentQty)` -- creates negative_stock_attempt alert (auto-called by observer)
- `expiryNear(ProductBatch, daysRemaining)` -- creates expiry_near alert
- `serialConflict(companyId, productId, warehouseId, detail)` -- creates serial_conflict alert

### inventory_journal (financial layer for accounting)

Double-entry style journal linking inventory movements to accounting. Enables integration with GL (Inventory, COGS, Accounts Payable, etc.).

| Field               | Type           | Notes                                        |
|---------------------|----------------|----------------------------------------------|
| id                  | bigint         | PK                                           |
| company_id          | bigint         | FK -> companies.id                           |
| stock_movement_id   | bigint (null)  | FK to stock_movements.id                    |
| journal_date        | date           | Ledger date                                  |
| entry_type          | varchar(50)    | Mirrors movement type (purchase_in, sale_out, etc.) |
| account_type        | varchar(50)    | inventory, cogs, accounts_payable, inventory_adjustment |
| debit_amount        | decimal(18,4)  | Debit amount                                 |
| credit_amount       | decimal(18,4)  | Credit amount                                |
| product_id          | bigint (null)  | Optional reference                           |
| warehouse_id        | bigint (null)  | Optional reference                           |
| reference_type      | varchar(50) (null) | Polymorphic reference                     |
| reference_id        | bigint (null)  | Polymorphic id                               |
| notes               | text (null)    | Optional                                     |
| timestamps          |                | created_at, updated_at                       |

**Example mappings:** `purchase_in` / `return_in` / `production_in` / `initial_stock`: Debit Inventory, Credit Accounts Payable. `sale_out`: Debit COGS, Credit Inventory. `adjustment_in`: Debit Inventory, Credit Inventory Adjustment. `adjustment_out` / `damage_out` / `return_out`: Debit Inventory Adjustment, Credit Inventory.

**Posting:** `InventoryJournalService::postFromMovement(StockMovement)` is called from `StockMovementObserver` after each movement. It creates one or two journal rows per movement (double entry). Skips if `inventory_journal` table does not exist or movement type is not mapped.

### inventory_events (global event log)

Central event table for **microservices**, **analytics**, and **replay**. Every inventory-related event (e.g. movement created, transfer completed) can be written here in addition to Laravel's in-memory event dispatch.

| Field       | Type         | Notes                                  |
|-------------|--------------|----------------------------------------|
| id          | bigint       | PK                                      |
| event_type  | varchar(100) | e.g. StockMovementCreated, StockTransferCompleted |
| event_id    | uuid         | Unique event instance ID                |
| payload     | json (null)  | Serialized event data (movement_id, product_id, etc.) |
| timestamps  |              | created_at, updated_at                  |

**Indexes:** `event_type`, `created_at`. **Unique:** `event_id`.

**How it works:** The `RecordInventoryEvent` listener listens for `StockMovementCreated` and `StockTransferCompleted`. When fired, it calls `InventoryEvent::record($eventType, $payload)`, which inserts a row into `inventory_events`. Downstream consumers (other services, ETL, replay) can read from this table without subscribing to Laravel events. Use **Laravel Queue + Redis** to offload heavy processing triggered by these events.

### inventory_valuations (daily valuation for accounting)

Snapshot of inventory value per product/warehouse/variant per date for accounting and reporting.

| Field           | Type           | Notes                        |
|-----------------|----------------|------------------------------|
| id              | bigint         | PK                            |
| company_id      | bigint         | FK -> companies.id            |
| product_id      | bigint         | FK -> products.id             |
| warehouse_id    | bigint         | FK -> warehouses.id            |
| variant_id      | bigint (null)  | Optional variant              |
| valuation_date  | date           | Snapshot date                 |
| quantity        | decimal(18,4)  | Quantity at snapshot          |
| unit_cost       | decimal(18,4)  | Cost used for valuation       |
| total_value     | decimal(18,4)  | quantity * unit_cost          |
| timestamps      |                | created_at, updated_at         |

Unique: `(company_id, product_id, warehouse_id, variant_id, valuation_date)`. Index: `(company_id, valuation_date)`.

### stock_counts & stock_count_lines (physical inventory)

For physical inventory audits. Adjustment movements (`adjustment_in`, `adjustment_out`) should reference `stock_count_id` when they result from a count.

**stock_counts**

| Field        | Type           | Notes                          |
|--------------|----------------|--------------------------------|
| id           | bigint         | PK                              |
| company_id   | bigint         | FK -> companies.id              |
| warehouse_id | bigint         | FK -> warehouses.id             |
| count_number | varchar(50)    | Unique count reference          |
| count_date   | datetime       | When count was taken            |
| status       | varchar(20)    | draft, in_progress, completed, cancelled |
| created_by   | bigint (null)  | FK -> users.id                  |
| approved_by  | bigint (null)  | FK -> users.id                  |
| timestamps   |                | created_at, updated_at           |

**stock_count_lines**

| Field             | Type           | Notes                          |
|-------------------|----------------|--------------------------------|
| id                | bigint         | PK                              |
| stock_count_id    | bigint         | FK -> stock_counts.id           |
| product_id        | bigint         | FK -> products.id               |
| variant_id        | bigint (null)  | Optional variant                |
| system_quantity   | decimal(18,4)  | From cache at count time        |
| counted_quantity  | decimal(18,4)  | Physical count                  |
| variance          | decimal(18,4)  | counted - system                |
| timestamps        |                | created_at, updated_at           |

Unique: `(stock_count_id, product_id, variant_id)`. When completing a count, create `adjustment_in` or `adjustment_out` movements with `stock_count_id` set.

### damage_reports (damage / loss auditing)

For warehouse auditing of damage and loss. `damage_out` movements should reference `damage_report_id` when they result from a report.

| Field         | Type           | Notes                          |
|---------------|----------------|--------------------------------|
| id            | bigint         | PK                              |
| company_id    | bigint         | FK -> companies.id              |
| warehouse_id  | bigint         | FK -> warehouses.id             |
| report_number | varchar(50)    | Unique report reference         |
| report_date   | datetime       | When damage/loss was reported   |
| reason        | varchar(100)   | damage, loss, expiry, etc.      |
| notes         | text (null)    | Optional details                |
| created_by    | bigint (null)  | FK -> users.id                  |
| timestamps    |                | created_at, updated_at           |

Unique: `(company_id, report_number)`.

### warehouse_locations (optional zones / racks)

Optional multi-location within a warehouse (zones, racks, bins) for advanced warehouse management.

| Field        | Type           | Notes                          |
|--------------|----------------|--------------------------------|
| id           | bigint         | PK                              |
| warehouse_id | bigint         | FK -> warehouses.id             |
| parent_id    | bigint (null)  | FK -> warehouse_locations.id (self) |
| name         | varchar(100)   | e.g. "Rack A1", "Bin B2"       |
| code         | varchar(50)    | Optional short code             |
| is_active    | boolean        | Default true                    |
| timestamps   |                | created_at, updated_at           |

Unique: `(warehouse_id, code)`. Hierarchical via `parent_id`.

### warehouse_locks (inventory locking)

During **stock count**, **warehouse closing**, or **audit**, the system can lock a warehouse so no transactions (sales, transfers, adjustments) are allowed.

| Field       | Type           | Notes                          |
|-------------|----------------|--------------------------------|
| id          | bigint         | PK                              |
| warehouse_id| bigint         | FK -> warehouses.id             |
| locked_by   | bigint (null)  | FK -> users.id                  |
| reason      | varchar(100)   | stock_count, closing, audit     |
| locked_at   | datetime       | When lock started               |
| expires_at  | datetime (null)| Optional expiry                 |
| timestamps  |                | created_at, updated_at           |

**TransferService** checks for an active lock on source or destination warehouse before executing a transfer. Sales and other movement creators can optionally check the same.

### inventory_audit_logs (stock audit trail)

Helps debug inventory discrepancies by recording old/new quantity and action per change.

| Field           | Type           | Notes                          |
|-----------------|----------------|--------------------------------|
| id              | bigint         | PK                              |
| company_id      | bigint (null)  | FK -> companies.id              |
| product_id      | bigint (null)  | FK -> products.id               |
| warehouse_id    | bigint (null)  | FK -> warehouses.id             |
| variant_id      | bigint (null)  | Optional variant                |
| old_quantity    | decimal(18,4)  | Quantity before change          |
| new_quantity    | decimal(18,4)  | Quantity after change           |
| action          | varchar(50)    | movement_created, cache_updated, etc. |
| reference_type  | varchar(50)    | Optional                        |
| reference_id    | bigint (null)  | Optional                        |
| user_id         | bigint (null)  | FK -> users.id                  |
| notes           | text (null)    | Optional                        |
| timestamps      |                | created_at, updated_at           |

**InventoryAuditLogService** logs each movement creation (old_quantity, new_quantity, action, user_id) from the stock movement observer.

---

## Reorder Engine (Low Stock Detection)

- **reorder_level** and **reorder_quantity** on `stock_cache` define when to trigger purchase suggestions.
- **LowStockService** (`App\Services\LowStockService`):
  - `getLowStockItems(?companyId, ?warehouseId)`: returns all cache rows where `quantity <= reorder_level` and `reorder_level > 0`. Each item includes `stock_cache`, `product`, and `suggested_qty` (from `reorder_quantity` or derived from shortfall).
  - `isBelowReorderLevel(productId, warehouseId, ?variantId)`: returns whether that product/warehouse/variant is below reorder level.
- Use for: low-stock reports, automated purchase suggestions, and procurement dashboards.

---

## FEFO Engine (First Expiry First Out)

For expiry-dated products (e.g. medicine, food, cosmetics), the system supports **First Expiry First Out**. **BatchAllocationService::getEarliestValidBatch(productId, warehouseId)** returns the batch with the earliest valid `expiry_date` (non-expired, same warehouse). Use it when allocating stock for sale or transfer without a specific `batch_id` so the system automatically picks the earliest-expiring batch.

---

## Multi-Warehouse Reservation Strategy

Reservations are stored per **warehouse** (`stock_reservations.warehouse_id`). Strategies:

- **Warehouse reservation:** Reserve from a specific warehouse (e.g. in-store pickup). Availability is computed per warehouse.
- **Global / nearest-warehouse:** For online or multi-warehouse orders, application logic can choose which warehouse to reserve from (e.g. nearest, or first with enough stock) and create a reservation there. The same `stock_reservations` table supports both; the choice is in how you create and query reservations.

---

## Inventory Alert Events

The system emits events **and** persists alerts for notifications and dashboards:

**Transient events** (for listeners, queued jobs, broadcasting):

| Event | When |
|-------|------|
| **LowStockDetected** | When low-stock items are identified (e.g. via LowStockService or after cache update). Payload: StockCache, suggestedQuantity. |
| **BatchExpiringSoon** | When a batch is within a threshold of expiry (e.g. scheduled job). Payload: ProductBatch, daysUntilExpiry. |
| **NegativeStockDetected** | When cache quantity goes negative after a movement (observer). Payload: Product, Warehouse, quantity. |

**Persistent alerts** (for dashboards, resolved by users):

The `inventory_alerts` table stores each alert with `alert_type`, `severity`, `message`, `is_resolved`, and `resolved_at`. The `InventoryAlertService` creates alerts; the `InventoryAlert` model provides scopes (`unresolved()`, `ofType()`, `critical()`).

Subscribe in `EventServiceProvider` or listeners for notifications, alerts, or reporting.

---

## Inventory Dashboard (World-Class POS)

The system supports (or can be extended to show):

- **Top selling products** -- from sale_out movements or sales line aggregates.
- **Dead stock** -- products with no recent movements or no sales in X days.
- **Fast moving items** -- high turnover (e.g. movements or sales count in period).
- **Low stock items** -- from LowStockService::getLowStockItems().
- **Expiring batches** -- from ProductBatch where expiry_date is within X days; optionally emit BatchExpiringSoon.
- **Active alerts** -- from `InventoryAlert::unresolved()` grouped by type/severity.
- **Batch stock levels** -- from `batch_stock_cache` for quick batch-level reporting without ledger aggregation.

Implement dashboard endpoints or reports using the movement ledger, stock_cache, batch_stock_cache, inventory_alerts, and the services above.

---

## Models & Relationships

| Model              | Tenant-scoped | Key relationships                                                             |
|--------------------|:-------------:|-------------------------------------------------------------------------------|
| Category           | Yes           | company, parent, children (self-ref), products (soft-deleted via `deleted_at`)|
| Brand              | Yes           | company, products (soft-deleted via `deleted_at`)                             |
| Unit               | Yes           | company, products (soft-deleted via `deleted_at`)                             |
| Product            | Yes           | company, category, brand, unit, variants, batches, serials, units, stockMovements (soft-deleted via `deleted_at`) |
| ProductVariant     | No (via product) | product, stockMovements, stockCaches (soft-deleted via `deleted_at`)      |
| ProductBatch       | No (via product) | product, warehouse, stockMovements, batchStockCache                       |
| ProductSerial      | No (via product) | product, warehouse, sale                                                  |
| ProductUnit        | No (via product) | product                                                                    |
| ProductBundle      | No (via product) | bundle (parent product), component (child product)                        |
| StockMovement      | Yes           | company, product, warehouse, variant, batch, serial, stockCount, damageReport, creator. **uuid** for API binding, **event_id**/**source** for event sourcing. |
| StockCache         | No            | company, product, warehouse, variant                                         |
| **BatchStockCache**| No            | **company, batch, product, warehouse (materialized batch-level stock)**       |
| **StockSnapshot**  | Yes           | **company, product, warehouse, variant (daily snapshot for analytics)**       |
| **InventoryJournalEntry** | Yes   | **company, stockMovement, product, warehouse (double-entry for accounting)**  |
| **InventoryEvent** | No            | **event_type, event_id, payload (central event log for microservices/replay)**  |
| StockReservation   | Yes           | company, product, variant, warehouse (includes `expires_at`)                  |
| **InventoryAlert** | Yes           | **company, product, warehouse, resolvedByUser. Scopes: unresolved(), ofType(), critical().** |
| StockCount         | Yes           | company, warehouse, lines, creator, approver                                 |
| StockCountLine     | No            | stockCount, product                                                          |
| DamageReport       | Yes           | company, warehouse, creator                                                  |
| InventoryValuation | Yes           | company, product, warehouse                                                  |
| WarehouseLocation  | No (via warehouse) | warehouse, parent, children                                               |
| InventoryAuditLog   | No            | company, product, warehouse, user (audit trail)                              |
| WarehouseLock      | No (via warehouse) | warehouse, lockedBy (inventory lock during count/audit)                    |

---

## Stock Calculation

- **From movements (audit trail):** `Product::currentStock($warehouse_id)`, `Product::stockByWarehouses($warehouseIds)` -- SUM over movements.
- **From cache (heavy POS):** `Product::currentStockCached($warehouse_id)`, `Product::stockByWarehousesCached($warehouseIds)` -- single table read.
- **From batch cache:** `BatchStockCache::where('batch_id', $batchId)->first()->quantity` -- single row read for batch-level stock.
- **Availability vs reservation:** business logic (e.g. `SaleService::validateStockForLines`) computes **available** as `on_hand - reserved`, where `on_hand` comes from `stock_cache.quantity` (movement ledger) and `reserved` from `stock_reservations`. This avoids overselling when stock is already reserved for quotations or pending orders.
- `GET /api/products/{id}/stock` uses the cache for performance.
- `GET /api/warehouse-stock` returns paginated stock_cache rows with product/warehouse/variant eager-loaded.

### Bundles / Kits

- A product is treated as a **bundle** when it has one or more `product_bundles` rows where it is the `bundle_product_id`.
- **Bundle inventory behaviour:** When selling a bundle, the system performs a **stock explosion**: it expands the line into components (CPU, GPU, RAM, etc.) and creates **sale_out** movements only for those components. The **bundle product itself does not track stock** -- only components do. **Rule:** bundle products should have **track_stock = false** (or be treated as non-stock items); the bundle SKU is for invoicing/display only.
- During stock validation and posting:
  - For **non-bundle** products: required quantity and `sale_out` movements are calculated directly from the sale line.
  - For **bundle** products: the engine expands the line into its components **before** any movements are created; validates component availability; creates movements **only for component products**. Never create stock movements for the bundle SKU itself.
- This ensures: invoices show a single bundle SKU; inventory and costing are accurate at the component level.

---

## Inter-Warehouse Transfers

`POST /api/transfers` delegates to **TransferService::executeTransfer()**, which runs inside **DB::transaction()** so that transfer_out and transfer_in either both commit or both roll back (no "stock disappeared" crash).

1. **Validation:** `from_warehouse_id` != `to_warehouse_id`; warehouses and product belong to the user's company. If a **serial_id** is provided: serial must exist, be in the **source** warehouse, and have **status = in_stock**.
2. **Warehouse lock:** If `warehouse_locks` is in use and either warehouse is locked (e.g. stock count, audit), the transfer is rejected.
3. **Availability & locking:** The source `stock_cache` row is locked with **SELECT ... FOR UPDATE**. Available = `quantity - reserved_quantity`. Transfer is rejected if insufficient unless `allow_negative_stock` is true.
4. **Movements:** Create `transfer_out` then `transfer_in`; link via `reference_id`; both get `source = 'TRANSFER'`; both can share the same `event_id` for traceability. Update serial's `warehouse_id` to destination.
5. **Event:** `StockTransferCompleted` is dispatched.

Conceptually, the movement ledger supports events such as:

- **Purchase received** -> one or more `purchase_in` movements per item/batch.
- **Sale shipped** -> `sale_out` movements linked to `Sale` and, for serialized items, `product_serials`.
- **Inventory count / adjustment** -> `adjustment_in` / `adjustment_out` movements tied to stock counts.
- **Inter-warehouse transfer** -> `transfer_out` + `transfer_in` pair (as above).
- **Warranty replacement (future)** -> e.g. `purchase_in` / `sale_out` combinations or a dedicated movement type, linked via `reference_type = 'WarrantyClaim'`.

---

## Optional UOM

`products.uom` is optional (nullable). Allowed values are in `config('pos.allowed_uom')` (see `config/pos.php`). The dedicated `units` table provides a normalized alternative with `short_name` for display and `unit_id` FK on products.

For more complex scenarios, `product_units` defines **per-product** unit conversions (e.g. box vs piece), so you can:

- Store stock in a base unit (e.g. piece).
- Sell or purchase in alternative units (e.g. box, carton) using `conversion_factor_to_base`.

---

## Batch Expiry and FEFO

- When a product has `track_batch = true`, movements that reduce stock (e.g. `sale_out`) should reference a valid batch:
  - `batch_id` must exist, belong to the product, and be in the same warehouse as the movement.
  - Batch must not be expired: `expiry_date` is null or `expiry_date >= today`.
- **BatchAllocationService** provides:
  - `validateBatchForMovement(batchId, productId, warehouseId)` for explicit batch validation.
  - `getEarliestValidBatch(productId, warehouseId)` for **FEFO** (First Expiry First Out): returns the earliest non-expired batch, or null. Use this when the sale line does not specify `batch_id` so the system allocates the best batch automatically.

---

## Stock Cache Rebuild

To restore or audit `stock_cache` from the movement ledger:

```bash
php artisan inventory:rebuild-cache
```

Optional: rebuild only one tenant:

```bash
php artisan inventory:rebuild-cache --company=1
```

The command:

1. Deletes existing `stock_cache` rows (or only for the given company).
2. Recalculates quantity from `stock_movements` grouped by `company_id`, `product_id`, `variant_id`, `warehouse_id`.
3. Recomputes `reserved_quantity` from active `stock_reservations` per (company, product, variant, warehouse).

### Batch Stock Cache Rebuild

To rebuild `batch_stock_cache` from the movement ledger:

```bash
php artisan inventory:rebuild-batch-cache
```

Optional: rebuild only one tenant:

```bash
php artisan inventory:rebuild-batch-cache --company=1
```

The command:

1. Deletes existing `batch_stock_cache` rows (or only for the given company).
2. Recalculates quantity from `stock_movements` where `batch_id IS NOT NULL`, grouped by `company_id`, `batch_id`, `product_id`, `warehouse_id`.

Use after data recovery, batch imports, or to verify batch cache consistency.

### Daily Stock Snapshot

To create or update daily stock snapshots for analytics and trend graphs:

```bash
php artisan inventory:snapshot
```

Optional: snapshot for a specific date or tenant:

```bash
php artisan inventory:snapshot --date=2026-03-19
php artisan inventory:snapshot --company=1
```

Reads from `stock_cache` and upserts into `stock_snapshots`. Schedule daily (e.g. `schedule->command('inventory:snapshot')->dailyAt('01:00')`) for end-of-day reports.

---

## Inventory Auditing Events

The following events are dispatched (for future accounting, audit logs, notifications). No business logic is changed; they are emitted after the fact:

| Event | When |
|-------|------|
| `StockMovementCreated` | After every new `StockMovement` is saved and cache updated. |
| `StockTransferCompleted` | After a transfer's `transfer_out` and `transfer_in` are created and linked. |
| `WarrantyRegistered` | After each `WarrantyRegistration` is created (e.g. from `WarrantyService::registerForSale`). |
| `WarrantyClaimCreated` | After a `WarrantyClaim` is created via the API. |
| `NegativeStockDetected` | After a movement causes stock to go negative; also persists an `InventoryAlert`. |

Listen in `EventServiceProvider` or use Laravel's event/listener registration as needed.

---

## Validation & Performance

- **DB-level ENUM:** `stock_movements.type` is ENUM in MySQL.
- **Type enforcement:** Validated against `StockMovementType::values()` and stored via enum cast.
- **Quantity/price bounds:** `quantity` and `unit_price` capped at 999,999,999.99.
- **Cached stock:** `stock_cache` + `StockMovementObserver` keep cache in sync. `batch_stock_cache` keeps batch-level cache in sync.
- **Pagination:** `GET /api/stock-movements` and `GET /api/warehouse-stock` cap `per_page` at 100.
- **Indexes:** `stock_movements`: `(company_id, product_id)`, `(product_id, warehouse_id)`, `(reference_type, reference_id)`, unique `(serial_id, reference_type, reference_id)`, **unique `(uuid)`**, **unique `(idempotency_key)`**, **index `(event_id)`**, **index `(source)`**, **index `(reason_code)`**, **index `(reversal_movement_id)`**. `stock_cache`: **UNIQUE(company_id, warehouse_id, product_id, variant_id)** (critical; prevents duplicate rows), index `(company_id, warehouse_id, product_id)`. `batch_stock_cache`: **UNIQUE(batch_id, product_id, warehouse_id)**, index `(product_id, warehouse_id)`. `inventory_alerts`: `(company_id, alert_type, is_resolved)`, `(product_id, warehouse_id)`, `(created_at)`. `stock_reservations`: `(company_id, product_id, warehouse_id)`, `(reference_type, reference_id)`, `status`. `stock_snapshots`: unique `(snapshot_date, product_id, warehouse_id, variant_id)`, index `(company_id, snapshot_date)`, index `(product_id, snapshot_date)`. `inventory_journal`: index `(company_id, journal_date)`, index `(stock_movement_id)`, index `(entry_type, journal_date)`. `product_serials`: unique `(serial_number)`. Category, brand, unit, product, product_variant tables: soft deletes (`deleted_at`) on categories, brands, units, products, product_variants.

---

## Production Scale Recommendations

For SaaS ERP/POS with **hundreds of customers daily**, the movement-ledger architecture scales well. Add the following as traffic grows:

### Redis caching

- **POS product lookup** — Cache product by ID or barcode (e.g. `Cache::remember("product:{$id}", 300, fn () => Product::find($id))`). Invalidate on product update.
- **Barcode scanning** — Cache barcode → product_id (or variant_id) to avoid repeated DB lookups during rapid scans.
- **Stock reads** — Cache `stock_cache` rows for hot product/warehouse keys (e.g. `stock:{$productId}:{$warehouseId}`) with short TTL (e.g. 60s). Accept slight staleness for read-heavy POS; writes still update the DB and can invalidate cache.

Use Laravel `Cache::store('redis')` and set `CACHE_DRIVER=redis` in production.

### Background queues (Laravel Queue + Redis)

Offload heavy work so HTTP requests stay fast. The codebase provides these **Job** classes (use Laravel Queue + Redis):

- **RebuildStockCacheJob** — Runs `inventory:rebuild-cache`. Dispatch: `RebuildStockCacheJob::dispatch()` or `RebuildStockCacheJob::dispatch($companyId)`.
- **RebuildBatchStockCacheJob** — Runs `inventory:rebuild-batch-cache`. Dispatch: `RebuildBatchStockCacheJob::dispatch($companyId)`.
- **CreateStockSnapshotJob** — Runs `inventory:snapshot`. Dispatch: `CreateStockSnapshotJob::dispatch($date, $companyId)` (e.g. for yesterday).
- **ProcessInventoryAlertJob** — Processes low-stock and other alerts (create `InventoryAlert` rows, send notifications). Dispatch from event listeners with `alertType` and `payload`.

Schedule from `routes/console.php` or scheduler:

- `Schedule::job(new CreateStockSnapshotJob())->dailyAt('01:00');`
- `Schedule::job(new RebuildStockCacheJob())->weekly();` (if desired)

**Alerts** — After dispatching `LowStockDetected`, `NegativeStockDetected`, or `BatchExpiringSoon`, push notification/email to a queue (e.g. dispatch `ProcessInventoryAlertJob`) so the request returns immediately.

Configure `QUEUE_CONNECTION=redis` and run `php artisan queue:work` (or Supervisor) for queue consumers.

### Read replicas

When the system grows, use **one write DB** and **one or more read replicas**:

- **Write connection** — Use for: creating/updating movements, cache updates, reservations, and any mutation. Point `DB::connection('mysql')` or default to the primary.
- **Read connection** — Use for: product lists, stock cache reads, warehouse stock reports, movement history, and dashboards. Configure a second connection (e.g. `mysql_read`) that points to the replica and use `StockCache::on('mysql_read')` or `Product::on('mysql_read')` for read-only queries.

Laravel supports multiple DB connections and read/write splitting; configure in `config/database.php` and use `read` / `write` keys for the same connection name where supported.

---

## Seeding

`DatabaseSeeder` creates:

- 2 categories: Electronics (root), Accessories (child of Electronics).
- 2 brands: Apple, Samsung.
- 2 units: Piece (pc), Kilogram (kg).
- Product 1 (SKU `SAMPLE-001`): simple product, category=Electronics, brand=Apple, unit=Piece. 50 units purchased into Main Warehouse.
- Product 2 (SKU `PHONE-001`): variable product, category=Electronics, brand=Samsung. 2 variants: Black (20 units) and White (15 units) purchased into Main Warehouse.
- Secondary Warehouse (`SEC`): created for transfer demo.
- Transfer: 10 units of Product 1 transferred from Main to Secondary Warehouse.

Run: `php artisan migrate:fresh --seed`

---

## API Endpoints

All endpoints require `Authorization: Bearer <token>`.

### Master Data

| Method | Endpoint         | Description                                                |
|--------|------------------|------------------------------------------------------------|
| GET    | /api/categories  | List categories (tenant-aware). Query: `active_only=1`.    |
| POST   | /api/categories  | Create category (body: name, parent_id?, is_active?).      |
| GET    | /api/brands      | List brands (tenant-aware). Query: `active_only=1`.        |
| POST   | /api/brands      | Create brand (body: name, is_active?).                     |
| GET    | /api/units       | List units (tenant-aware). Query: `active_only=1`.         |
| POST   | /api/units       | Create unit (body: name, short_name, is_active?).          |

### Products

| Method | Endpoint                  | Description                                                                        |
|--------|---------------------------|------------------------------------------------------------------------------------|
| GET    | /api/products             | List products. Query: `active_only`, `category_id`, `brand_id`, `type`.            |
| POST   | /api/products             | Create product. New optional fields: category_id, brand_id, unit_id, type, cost_price, selling_price, track_stock, track_serial, track_batch, allow_negative_stock. |
| GET    | /api/products/{id}/stock  | Stock per warehouse for product (from cache).                                      |

### Stock Movements

| Method | Endpoint              | Description                                                                               |
|--------|-----------------------|-------------------------------------------------------------------------------------------|
| GET    | /api/stock-movements  | List movements. Query: `product_id`, `warehouse_id`, `type`, `source`, `per_page`.        |
| POST   | /api/stock-movements  | Record movement. Optional: variant_id, unit_cost, batch_id, serial_id, movement_date, stock_count_id, damage_report_id, **source**, **event_id**, **reason_code**, **idempotency_key**. If **idempotency_key** is sent and a movement already exists with that key, returns **200** with existing movement (no duplicate). |

### Warehouse Stock & Transfers

| Method | Endpoint             | Description                                                                           |
|--------|----------------------|---------------------------------------------------------------------------------------|
| GET    | /api/warehouse-stock | Paginated stock cache report. Query: `warehouse_id`, `product_id`, `variant_id`, `low_stock` (threshold), `per_page`. |
| POST   | /api/transfers       | Inter-warehouse transfer. Body: product_id, from_warehouse_id, to_warehouse_id, quantity, variant_id?, unit_cost?, batch_id?, serial_id?. |

---

## Step 6.1: Warranty Management

Tenant-aware warranty system linked to products, serial numbers, customers (via `customer_id`), and sales. Supports manufacturer, seller, and extended warranties, plus claims.

### Warranty Tables

#### warranties

| Field           | Type           | Notes                                       |
|-----------------|----------------|---------------------------------------------|
| id              | bigint         | PK                                          |
| company_id      | bigint         | FK -> companies.id                          |
| name            | varchar(255)   | e.g. "1 Year Manufacturer Warranty"         |
| duration_months | int            | Warranty duration in months                 |
| type            | enum           | `manufacturer`, `seller`, `extended`        |
| description     | text (null)    | Optional details                            |
| is_active       | boolean        | Default true                                |
| timestamps      |                | created_at, updated_at                      |

#### product_warranties

| Field       | Type    | Notes                                      |
|-------------|---------|--------------------------------------------|
| id          | bigint  | PK                                         |
| product_id  | bigint  | FK -> products.id                          |
| warranty_id | bigint  | FK -> warranties.id                        |
| is_default  | boolean | If true, auto-applied on sale              |
| timestamps  |         | created_at, updated_at                     |

Unique: `(product_id, warranty_id)`.

#### warranty_registrations

Concrete warranties issued to customers at sale time.

| Field        | Type           | Notes                                        |
|--------------|----------------|----------------------------------------------|
| id           | bigint         | PK                                           |
| company_id   | bigint         | FK -> companies.id                           |
| sale_id      | bigint         | FK -> sales.id                               |
| sale_line_id | bigint         | FK -> sale_lines.id                          |
| customer_id  | bigint (null)  | Optional customer reference                  |
| product_id   | bigint         | FK -> products.id                            |
| serial_id    | bigint (null)  | FK -> product_serials.id                     |
| warranty_id  | bigint         | FK -> warranties.id                          |
| start_date   | date           | Typically sale date                          |
| end_date     | date           | Calculated from duration                     |
| status       | enum           | `active`, `expired`, `void`                  |
| timestamps   |                | created_at, updated_at                       |

#### warranty_claims

| Field                  | Type           | Notes                                      |
|------------------------|----------------|--------------------------------------------|
| id                     | bigint         | PK                                         |
| warranty_registration_id | bigint       | FK -> warranty_registrations.id           |
| claim_number           | varchar(50)    | Unique claim reference                     |
| claim_type             | enum           | `repair`, `replacement`, `inspection`      |
| description            | text           | Customer issue description                 |
| status                 | enum           | `pending`, `approved`, `rejected`, `completed` |
| approved_by            | bigint (null)  | FK -> users.id                             |
| resolution_notes       | text (null)    | Optional technician/decision notes         |
| timestamps             |                | created_at, updated_at                     |

### Warranty Models & Relationships

- `Warranty` (tenant-scoped): belongs to `Company`, has many `ProductWarranty`, has many `WarrantyRegistration`.
- `ProductWarranty`: links `Product` to `Warranty` with `is_default`.
- `WarrantyRegistration` (tenant-scoped): belongs to `Company`, `Sale`, `SaleLine`, `Product`, optional `ProductSerial`, and `Warranty`; has many `WarrantyClaim`. Exposes `is_expired` accessor based on `end_date` and `status`.
- `WarrantyClaim`: belongs to `WarrantyRegistration`, optional approver (`User`).

### Warranty Workflow

- **During sale creation (completed sales only):**
  - `SaleService` calls `WarrantyService::registerForSale($sale, $lines)` after lines are created.
  - For each sale line:
    - Load `Product` and its active default `ProductWarranty` records.
    - Only proceed if `product.track_serial = true`.
    - Compute `start_date` from sale `created_at` and `end_date = start_date + duration_months`.
    - Optionally link `serial_id` when the sale line includes `serial_id` (API supports `lines.*.serial_id`).
    - Create `WarrantyRegistration` rows.
- **Warranty expiry:**
  - Registrations are considered expired when `today > end_date` or `status = expired`.
- **Claims:**
  - Claims can only be created against non-expired registrations.

### Warranty API

All endpoints require `Authorization: Bearer <token>`.

#### Warranty Lookup

| Method | Endpoint                 | Description                                                                 |
|--------|--------------------------|-----------------------------------------------------------------------------|
| GET    | /api/warranty/lookup    | Lookup by `serial` or `sale_id`. Returns matching warranty registrations.   |

Examples:

- `GET /api/warranty/lookup?serial=ABC123`
- `GET /api/warranty/lookup?sale_id=10`

#### Customer Warranties

| Method | Endpoint                          | Description                                  |
|--------|-----------------------------------|----------------------------------------------|
| GET    | /api/customers/{id}/warranties   | List all registrations for a `customer_id`.  |

> Note: A full `customers` table/module can be added later. For now, `customer_id` is an integer reference stored on `sales` and `warranty_registrations`.

#### Warranty Claims

| Method | Endpoint                 | Description                                                                  |
|--------|--------------------------|------------------------------------------------------------------------------|
| GET    | /api/warranty-claims    | List claims. Query: `status`, `per_page`.                                    |
| POST   | /api/warranty-claims    | Create claim: `warranty_registration_id`, `claim_type`, `description`.       |
| PUT    | /api/warranty-claims/{id} | Update claim: `status?`, `resolution_notes?`.                              |

Validation / rules:

- Claim creation:
  - Fails with 422 if the linked `WarrantyRegistration` is expired (`today > end_date` or status `expired`).
- Status updates:
  - When status moves to `approved`, `rejected`, or `completed`, `approved_by` is set to the current user.
- Replacement / repair flows:
  - Inventory and accounting side-effects (e.g. replacement stock movement `warranty_replacement`, Dr Warranty Expense / Cr Inventory) are planned as a future enhancement.

### Warranty-Related Seeding

`DatabaseSeeder` additionally creates:

- `1 Year Manufacturer Warranty` (12 months, type=manufacturer).
- `6 Month Seller Warranty` (6 months, type=seller).
- `2 Year Extended Warranty` (24 months, type=extended).
- Links:
  - `PHONE-001` (Smartphone) -> 1 Year Manufacturer Warranty (default).
  - `SAMPLE-001` (Sample Product) -> 6 Month Seller Warranty (default).

No demo registrations are created; they will be generated automatically for completed sales of serial-tracked products when you start posting real sales including serial information.

---

## Verification

1. **Tenant isolation** -- Log in as Company A user. GET /api/categories, /api/brands, /api/products only show Company A data.

2. **Category hierarchy** -- POST a parent category, then POST a child with `parent_id`. GET /api/categories shows nested relationship.

3. **Product with inventory fields** -- POST /api/products with `category_id`, `brand_id`, `unit_id`, `type=variable`. Confirm the new fields are returned.

4. **Warehouse stock** -- GET /api/warehouse-stock shows cached stock per product/warehouse. Filter by `warehouse_id` or `low_stock=5` for low-stock alerts.

5. **Transfer** -- POST /api/transfers with `from_warehouse_id=1`, `to_warehouse_id=2`, `product_id=1`, `quantity=5`. Confirm two movements created and stock_cache updated in both warehouses.

6. **Backward compatibility** -- All existing endpoints (sales, payments, stock-movements) continue to work without changes. The new columns are all nullable/defaulted.

7. **Movement UUID** -- POST /api/stock-movements. Confirm response includes `uuid`. Use GET /api/stock-movements/{uuid} for lookup.

8. **Inventory alerts** -- After a movement causes negative stock, verify a row is created in `inventory_alerts` with `alert_type = 'negative_stock_attempt'` and `severity = 'critical'`.

9. **Soft delete protection** -- Attempt to delete a product (or warehouse/batch/variant) that has stock movements. Expect 422 with message that movements exist; use deactivate or archive instead.

10. **Stock snapshots** -- Run `php artisan inventory:snapshot --date=yesterday`. Verify rows in `stock_snapshots` for that date. Use for daily reports and trend graphs.

11. **Movement reason_code** -- POST a movement with `reason_code: 'stock_count'` or `manual_adjustment`. Confirm it is stored and returned; useful for audits.

12. **Inventory journal** -- After creating a purchase_in or sale_out movement, verify corresponding rows in `inventory_journal` (Debit/Credit legs) for accounting integration.

13. **Idempotency** -- POST the same movement twice with the same `idempotency_key`. First request returns 201; second returns 200 with the same movement (no duplicate row).

14. **Inventory events** -- After creating a movement, verify a row in `inventory_events` with `event_type = 'StockMovementCreated'` and payload containing movement_id/uuid.

---

## Critical Guarantees

- **Movement UUID** -- every movement gets a globally-unique `uuid` (auto-generated on create). Safe for APIs, distributed systems, and microservices. Unique index enforced. Route model binding resolves by UUID.
- **Event sourcing ready** -- `event_id` (UUID) groups related movements; `source` (POS/API/IMPORT/TRANSFER/ADJUSTMENT/RETURN/PRODUCTION) identifies the origin channel. Both nullable for backward compatibility.
- **movement_date** is NOT NULL with default CURRENT_TIMESTAMP (ledger always has a transaction date).
- **stock_cache** quantity and reserved_quantity use decimal(18,4) to match movement precision and avoid rounding drift (e.g. 0.125 kg x 3).
- **stock_cache** has UNIQUE(company_id, warehouse_id, product_id, variant_id) to prevent duplicate rows.
- **batch_stock_cache** holds materialized batch-level quantities. Updated atomically by the observer. Eliminates SUM(stock_movements) per batch on every read. Rebuildable via `php artisan inventory:rebuild-batch-cache`.
- **Inventory alerts** persisted in `inventory_alerts` table with severity levels. Auto-created for negative stock; available for low_stock, expiry_near, serial_conflict. Queryable via scopes for dashboards.
- **Transfer atomicity:** TransferService::executeTransfer() uses **DB::transaction()** so transfer_out and transfer_in either both commit or both roll back (no "stock disappeared" crash).
- **Negative stock race:** Sale and transfer flows use **SELECT ... FOR UPDATE** (lockForUpdate) on stock_cache rows inside a transaction so multi-terminal POS cannot double-sell.
- **Serial transfer:** Before transfer_out, serial must exist, be in source warehouse, and status = in_stock; after transfer, serial's warehouse_id is set to destination.
- **Bundle products** should have track_stock = false; only components hold stock; sale_out movements are created for components only.
- **stock_reservations** include expires_at; reserved_quantity in cache is recomputed from active reservations (e.g. inventory:rebuild-cache).
- **Batch quantity** = SUM(stock_movements.quantity) WHERE batch_id = ? (source of truth) OR `batch_stock_cache.quantity` (fast read); **FEFO** via BatchAllocationService::getEarliestValidBatch().
- **InventoryService** centralizes purchase(), sale(), transfer(), adjustment(), return(), damage(), production(); controllers stay thin.
- **inventory_audit_logs** record old_quantity, new_quantity, action, user_id for debugging discrepancies.
- **warehouse_locks** allow blocking transactions during stock count or audit; TransferService checks before transfer.
- **Alert events:** LowStockDetected, BatchExpiringSoon, NegativeStockDetected for notifications and dashboards.
- **Soft delete protection:** Product, Warehouse, ProductBatch, and ProductVariant cannot be deleted (soft or force) if stock movements reference them. InventoryDeletionGuard throws CannotDeleteEntityWithMovementsException (422 for API). Use deactivate or archive instead.
- **reason_code** on stock_movements (optional) for audit: stock_count, damage, expired, theft, manual_adjustment, etc. See MovementReasonCode enum.
- **stock_snapshots** optional daily snapshot from stock_cache for fast analytics and trend graphs; `php artisan inventory:snapshot`.
- **inventory_journal** double-entry layer for accounting; InventoryJournalService posts from each movement (Dr Inventory / Cr AP, Dr COGS / Cr Inventory, etc.). Integrates inventory with GL.
- **Production scale:** Redis for product/barcode/stock caching; Laravel Queue + Redis for rebuilds, alerts, analytics; read replicas (1 write DB, read DBs) when system grows.
- **Idempotency:** Send `idempotency_key` with movement creation; duplicate key returns 200 with existing movement. Unique index on `stock_movements.idempotency_key`.
- **Movement versioning:** `version` (default 1) and `reversal_movement_id` (FK to stock_movements) for audit and corrections; use `reversalOf()` and `reversedBy()` relations.
- **inventory_events** central event log (event_type, event_id, payload) for microservices, analytics, and replay; written by `RecordInventoryEvent` listener.
- **Background jobs:** RebuildStockCacheJob, RebuildBatchStockCacheJob, CreateStockSnapshotJob, ProcessInventoryAlertJob; use Queue + Redis.
- **Barcode/SKU indexes:** `products.barcode` and `products.sku` have dedicated indexes for fast POS scans.
- Serialized products sold with serial_id; stock validation uses available_quantity and allow_negative_stock; reorder engine and inventory valuations/stock counts/damage_reports as documented above.
