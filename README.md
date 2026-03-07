# Vue.js & Laravel POS System

A **multi-tenant POS and ERP system** built for retail chains with multiple companies, branches, and warehouses. Backend: **PHP Laravel** with **MySQL**; frontend: **Vue.js**. The system supports sales, quotations, returns, inventory, warranty management, **customers & customer ledger**, **POS sessions & cash management**, **double-entry accounting**, **purchase & supplier engine**, and **professional risk mitigations** (deadlock retry, DB constraints, audit trails, four-eyes adjustments).

---

## Overview

### Core architecture

- **Multi-tenant:** Companies → Branches → Warehouses. All data is scoped by `company_id` for strict tenant isolation.
- **Authentication & authorization:** Laravel Sanctum (API tokens), Spatie Laravel Permission (company-scoped roles). Users belong to a company and branch; optional warehouse-level access.
- **Inventory engine:** Movement-based stock (sale_out, return_in, transfers, adjustments). Stock cache, reservations for quotations, batch/serial tracking.
- **Sales & Customer Engine (Step 8):** Customers, sales, quotations, returns. **Orders ≠ Payments** — sale has `status` and derived `payment_status`. Line-level warehouse validation (**application + DB trigger**). **Product/snapshots** on sale lines (name, SKU, barcode, tax_class). **POS:** `device_id`, `pos_session_id`; **pos_sessions** with device/cashier/shift and **pos_cash_movements**. **sale_taxes** per-rate breakdown; **return_reason_code** (damaged, wrong_item, warranty, fraud, etc.). **Immutable completed sales** — corrections via **sale adjustments** (four-eyes approval). **Sale line history** for draft edit audit; **customer_ledger** for aging/statements.
- **Payments & accounting:** Double-entry journal engine (`journal_entries` + `journal_entry_lines`), human-readable JE numbers (e.g. `JE-2026-00001`), document references (e.g. `INV-2026-0001`, `PAY-2026-00005`). Cached `paid_amount` / `due_amount` on sales. **Deadlock retry** on payment/sale transactions; **POS session** validation; **customer_ledger** (invoice, payment, refund) for aging and credit control.
- **Purchase & Supplier Engine:** Suppliers, purchase orders, goods receipts (partial), supplier invoices, supplier payments. Inventory and accounting integration.
- **Warranty & serials:** Warranty registrations linked to sales; serial/IMEI handling for returns and claims.
- **Documentation:** Step-by-step architecture and API docs in `docs/`.

### Risk mitigations (implemented)

| Area | Mitigation |
|------|------------|
| **Line warehouse cross-branch** | Application validation in SaleService + **MySQL BEFORE INSERT/UPDATE trigger** on `sale_lines` |
| **Payment/sale race conditions** | **SELECT … FOR UPDATE** on sale row + **RetryOnDeadlock** trait (3 retries, exponential back-off) |
| **Immutable sale corrections** | **Sale adjustments** — create (pending) → approve by different user (four-eyes) → reversal journal entry |
| **POS orphaned transactions** | Configurable **POS_REQUIRE_SESSION** / **POS_REQUIRE_DEVICE_ID**; session must be open when provided |
| **Draft edit audit** | **sale_line_history** table (added/updated/removed per line) |
| **Exchange rate drift** | **Exchange rate & currency locked** on completed sales (Sale model); **CurrencyRounding** helper (banker's rounding) |
| **Multi-currency** | Rate stored at transaction time; base-currency conversion and rounding rules documented |

---

## Tech Stack

| Layer      | Technology |
|-----------|------------|
| Backend   | PHP 8.x, Laravel, MySQL |
| API Auth  | Laravel Sanctum (token expiration, rotation) |
| Roles     | Spatie Laravel Permission (company-scoped) |
| Frontend  | Vue.js (API consumer) |

---

## Repository Structure

```
├── app/
│   ├── Enums/                    # SaleStatus, SalePaymentStatus, ReturnReasonCode, SaleAdjustmentType, etc.
│   ├── Http/Controllers/Api/     # Auth, Sales, Payments, Customers, SaleAdjustments, Purchases, etc.
│   ├── Models/                   # Sale, SaleLine, SaleReturn, SaleAdjustment, SaleLineHistory, PosSession,
│   │                             # PosCashMovement, Customer, CustomerLedger, Payment, JournalEntry, etc.
│   ├── Services/                 # SaleService, PaymentService, SaleAdjustmentService, CustomerLedgerService,
│   │                             # InventoryService, WarrantyService, etc.
│   ├── Support/                  # RetryOnDeadlock, CurrencyRounding
│   └── DataTransferObjects/      # SaleAuditMetadata, etc.
├── config/
│   ├── pos.php                   # POS_REQUIRE_SESSION, POS_REQUIRE_DEVICE_ID
│   └── ...                       # Laravel, Sanctum, Permission
├── database/
│   ├── migrations/               # Companies, branches, warehouses, users, roles, sales, sale_lines,
│   │                             # sale_returns, sale_adjustments, sale_line_history, pos_sessions,
│   │                             # pos_cash_movements, customer_ledger, sale_taxes, warehouse-branch trigger, etc.
│   └── seeders/
├── docs/
│   ├── tenant-auth.md            # Step 1: Auth, tenants, branches, roles
│   ├── branches-warehouses.md    # Step 2: Branches & warehouses
│   ├── inventory-movements.md    # Step 3: Inventory engine
│   ├── sales-customer-engine.md  # Step 8: Sales, customers, POS, returns, adjustments, risks
│   ├── payment-accounting.md     # Step 5: Payments, journal entries, customer ledger
│   ├── purchase-supplier-engine.md # Step 7: Purchase & supplier
│   └── ...
├── routes/api.php
└── README.md
```

---

## Documentation (docs/)

| Document | Description |
|----------|-------------|
| [tenant-auth.md](docs/tenant-auth.md) | Companies, branches, warehouses, users, company-scoped roles, Sanctum, tenant isolation |
| [branches-warehouses.md](docs/branches-warehouses.md) | Branch/warehouse schema, FK rules, soft deletes, data hierarchy |
| [inventory-movements.md](docs/inventory-movements.md) | Stock movements, stock cache, reservations, inventory journal, alerts |
| [sales-customer-engine.md](docs/sales-customer-engine.md) | **Step 8:** Sales, quotations, returns, customers, POS sessions, device_id, snapshots, sale_taxes, return_reason_code, sale adjustments, sale line history, customer ledger, constraints, risks & mitigations, API |
| [payment-accounting.md](docs/payment-accounting.md) | **Step 5:** Payments, journal entries, customer_ledger, due amounts, deadlock retry, POS session, refunds |
| [purchase-supplier-engine.md](docs/purchase-supplier-engine.md) | **Step 7:** Suppliers, purchase orders, goods receipts, supplier invoices, supplier payments |

---

## Getting Started

### Requirements

- PHP 8.1+
- Composer
- MySQL 8.x (or MariaDB)
- Node.js & npm (for Vue.js frontend when added)

### Installation

1. **Clone the repository**

   ```bash
   git clone https://github.com/alikhan1-dev/Vue.js-And-Laravel-Pos-.git
   cd Vue.js-And-Laravel-Pos-
   ```

2. **Install PHP dependencies**

   ```bash
   composer install
   ```

3. **Environment**

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Edit `.env`: set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Optionally:

   - `SANCTUM_EXPIRATION` (e.g. `480` for 8h)
   - `POS_REQUIRE_SESSION=true` to enforce POS session on sales/payments
   - `POS_REQUIRE_DEVICE_ID=true` to require device_id on sales

4. **Database**

   ```bash
   php artisan migrate --seed
   ```

   Creates tables (including triggers for sale_lines warehouse-branch check) and seeds default company, roles, branches, warehouses, accounts, and super admin user.

5. **Run the API server**

   ```bash
   php artisan serve
   ```

   API base URL: **http://127.0.0.1:8000**

### Default Login (after seed)

- **Email:** `admin@company.test`
- **Password:** `password`

Use `POST /api/auth/login` with JSON: `{"email":"admin@company.test","password":"password"}` to get a token.

---

## API Overview

All endpoints below (except auth) require: `Authorization: Bearer <token>`.

### Auth

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/register` | Register user (company, branch, role) |
| POST | `/api/auth/login` | Login; returns token; token rotation by device |
| POST | `/api/auth/logout` | Revoke current token |
| GET | `/api/user` | Current user (company, branch, roles) |

### Master data & inventory

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/branches` | List branches (tenant-scoped) |
| GET | `/api/warehouses` | List warehouses (tenant-scoped) |
| GET | `/api/products` | List products |
| POST | `/api/products` | Create product |
| GET | `/api/products/{id}/stock` | Product stock |
| GET | `/api/categories` | List categories |
| POST | `/api/categories` | Create category |
| GET | `/api/brands` | List brands |
| POST | `/api/brands` | Create brand |
| GET | `/api/units` | List units |
| POST | `/api/units` | Create unit |
| GET | `/api/stock-movements` | List stock movements (filters: product_id, warehouse_id, type) |
| POST | `/api/stock-movements` | Create stock movement |
| GET | `/api/warehouse-stock` | Warehouse stock report |
| POST | `/api/transfers` | Inter-warehouse transfer |

### Sales & customers

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/sales` | List sales (type, branch_id, status, date_from, date_to) |
| POST | `/api/sales` | Create sale or quotation (optional draft, device_id, pos_session_id) |
| GET | `/api/sales/{id}` | Sale detail with lines, movements, discounts |
| POST | `/api/sales/{id}/complete` | Complete draft sale (stock deduction, accounting) |
| POST | `/api/sales/{id}/convert` | Convert quotation to sale |
| POST | `/api/sales/{id}/cancel` | Cancel sale (draft or pending only) |
| POST | `/api/sales/{id}/return` | Create SaleReturn + items (return_reason_code, reason) |
| GET | `/api/sales/{id}/stock-check` | Current stock per line for sale warehouse |
| GET | `/api/sale-adjustments` | List sale adjustments (sale_id, status) |
| POST | `/api/sales/{saleId}/adjustments` | Create adjustment for completed sale (type, amount, reason) |
| POST | `/api/sale-adjustments/{id}/approve` | Approve adjustment (four-eyes; posts journal entry) |
| GET | `/api/customers` | List customers (search, status, pagination) |
| POST | `/api/customers` | Create customer |
| GET | `/api/customers/{id}` | Customer detail with addresses |
| PUT | `/api/customers/{id}` | Update customer |
| GET | `/api/customers/{id}/warranties` | Customer warranties |

### Payments & accounting

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/accounts` | List active accounts (code, name, type) |
| GET | `/api/payment-methods` | List active payment methods |
| GET | `/api/payments` | List payments (sale_id, status, branch_id, date_from, date_to) |
| POST | `/api/payments` | Create payment (sale_id, branch_id, lines, pos_session_id?, payment_date?) |
| GET | `/api/payments/{id}` | Payment detail with lines and journal entries |
| POST | `/api/payments/{id}/refund` | Refund (amount, account_id) |

### Warranty

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/warranty/lookup` | Warranty lookup |
| GET | `/api/warranty-claims` | List warranty claims |
| POST | `/api/warranty-claims` | Create warranty claim |
| PUT | `/api/warranty-claims/{id}` | Update warranty claim |

### Purchase & suppliers

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/suppliers` | List suppliers |
| POST | `/api/suppliers` | Create supplier |
| GET | `/api/purchases` | List purchases (supplier_id, status, branch_id, date filters) |
| POST | `/api/purchases` | Create purchase order |
| GET | `/api/purchases/{id}` | Purchase detail |
| POST | `/api/purchases/{id}/confirm` | Confirm purchase |
| POST | `/api/purchases/{id}/mark-ordered` | Mark as ordered |
| POST | `/api/purchases/{id}/receive` | Receive goods (partial or full) |
| GET | `/api/supplier-invoices` | List supplier invoices |
| POST | `/api/supplier-invoices` | Create supplier invoice |
| POST | `/api/supplier-invoices/{id}/post` | Post invoice (Dr Inventory, Cr AP) |
| POST | `/api/supplier-payments` | Create supplier payment |

---

## Key Conventions

- **Tenant isolation:** Business tables include `company_id`; many use `branch_id` and `warehouse_id`. Global scopes enforce company scope in HTTP context.
- **Roles:** Stored per company (`roles.company_id`). Assign by role instance with role filtered by company.
- **Returns:** Use **sale_returns** + **sale_return_items** and stock type `return_in` only (no sales with type=return).
- **Payment status:** Derived only — updated by PaymentService (and SaleService on complete). Never set via API or mass assignment.
- **Completed sales:** Immutable. No line/price/quantity edits; exchange rate and currency locked. Use **sale adjustments** (four-eyes) or returns/refunds for corrections.
- **Soft deletes:** Only **draft** sales can be soft-deleted. Customers, sale_returns use soft deletes; reporting on historical sales should use `withTrashed()` for customers where needed.
- **Line warehouse:** `sale_lines.warehouse_id` must satisfy `warehouse.branch_id = sale.branch_id` (enforced in SaleService and by MySQL trigger).
- **POS:** Optional `device_id` and `pos_session_id` on sales and payments. Set `POS_REQUIRE_SESSION=true` or `POS_REQUIRE_DEVICE_ID=true` in `.env` to enforce (see `config/pos.php`).
- **Rounding:** Use `CurrencyRounding::round()` / `toBaseCurrency()` for consistent banker's rounding in multi-currency flows.

---

## API testing

### Smoke test (recommended)

A full API smoke test hits every endpoint in order (auth → master data → sales → payments → purchase/suppliers). **The API server must be running.**

1. Start the server (in one terminal):
   ```bash
   php artisan serve
   ```
2. Run the smoke test (in another terminal):
   ```bash
   php artisan api:smoke-test
   ```
   Optional: `--base-url=http://localhost:8000` if different; `--show-errors` to print response body on failure.

3. Expected: **Passed: 28+, Failed: 0, Skipped: 0–2**. If any fail, check `storage/logs/laravel.log` for the exception. Some endpoints may be skipped when seeded data doesn’t include related records (e.g. warranty lookup when there are no warranty registrations).

### PHPUnit feature tests

The project includes a comprehensive API test suite in `tests/Feature/Api/FullApiTest.php`. It uses `RefreshDatabase` and the `DatabaseSeeder` to test each endpoint. Run it with:

```bash
php artisan test tests/Feature/Api/FullApiTest.php
```

If PHPUnit fails to run (e.g. class not found), use the smoke test above instead.

---

## Configuration

- **`config/pos.php`**
  - `require_session` — When true, sales and payments must include `pos_session_id` (open session).
  - `require_device_id` — When true, sales must include `device_id`.
  - Set via `.env`: `POS_REQUIRE_SESSION`, `POS_REQUIRE_DEVICE_ID` (default: false).

---

## License

This project is open-sourced software. Use and modify as needed for your organization.
