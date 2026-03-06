# Vue.js & Laravel POS System

A **multi-tenant POS and ERP system** built from scratch. The backend is **PHP Laravel** with **MySQL**; the frontend is **Vue.js**. The system is designed for retail chains with multiple companies, branches, and warehouses—supporting sales, quotations, returns, inventory, and warranty management.

---

## Overview

- **Multi-tenant:** Companies → Branches → Warehouses. All data is scoped by `company_id` for strict tenant isolation.
- **Authentication & authorization:** Laravel Sanctum (API tokens), Spatie Laravel Permission (company-scoped roles). Users belong to a company and branch; optional warehouse-level access.
- **Inventory engine:** Movement-based stock (sale_out, return_in, transfers, adjustments). Stock cache, reservations for quotations, batch/serial tracking.
- **POS sales & quotations:** Sales, quotations, and returns with warehouse-level stock integration. Quotations can reserve stock; returns use canonical `return_in` movements.
- **Warranty & serials:** Warranty registrations linked to sales; serial/IMEI handling for returns and claims.
- **Documentation:** Step-by-step architecture and API docs in the `docs/` folder.

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
│   ├── Http/Controllers/Api/   # API controllers (auth, sales, stock, etc.)
│   ├── Models/                 # User, Company, Branch, Warehouse, Sale, StockMovement, etc.
│   ├── Services/               # SaleService, WarrantyService, InventoryJournalService, etc.
│   └── ...
├── config/                     # Laravel + Sanctum, Permission, etc.
├── database/
│   ├── migrations/             # Schema (companies, branches, warehouses, users, roles, sales, stock_*)
│   └── seeders/                # Default company, roles, admin user, sample data
├── docs/                       # Project documentation (Step 1–4+)
│   ├── tenant-auth.md          # Step 1: Auth, tenants, branches, roles, indexes
│   ├── branches-warehouses.md  # Step 2: Branches & warehouses schema and rules
│   ├── inventory-movements.md  # Step 3: Inventory engine
│   ├── sales-quotations.md     # Step 4: POS sales, quotations, returns
│   └── payment-accounting.md   # Payments & accounting
├── routes/
│   └── api.php                 # API routes (auth, sales, stock movements, etc.)
└── README.md                   # This file
```

---

## Documentation (docs/)

| Document | Description |
|----------|-------------|
| [tenant-auth.md](docs/tenant-auth.md) | Companies, branches, warehouses, users, company-scoped roles, Sanctum, indexes, tenant isolation |
| [branches-warehouses.md](docs/branches-warehouses.md) | Branch/warehouse schema, FK rules, soft deletes, warehouse types, default warehouse, data hierarchy |
| [inventory-movements.md](docs/inventory-movements.md) | Stock movements, stock cache, reservations, inventory journal, alerts |
| [sales-quotations.md](docs/sales-quotations.md) | Sales, quotations, returns, reservations, return_in, validation, audit log, API |
| [payment-accounting.md](docs/payment-accounting.md) | Payments and accounting integration |

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

   Edit `.env`: set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Optionally set `SANCTUM_EXPIRATION` (e.g. `480` for 8h, `1440` for 24h).

4. **Database**

   ```bash
   php artisan migrate --seed
   ```

   This creates tables and seeds a default company, roles, permissions, branches, warehouses, and a super admin user.

5. **Run the API server**

   ```bash
   php artisan serve
   ```

   API base URL: **http://127.0.0.1:8000**

### Default Login (after seed)

- **Email:** `admin@company.test`
- **Password:** `password`

Use `POST /api/auth/login` with JSON body `{"email":"admin@company.test","password":"password"}` to get a token.

---

## API Overview

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/register` | Register user (company, branch, role) |
| POST | `/api/auth/login` | Login; returns token; updates last_login_at / last_login_ip; token rotation by device |
| POST | `/api/auth/logout` | Revoke current token |
| GET | `/api/user` | Current user (company, branch, roles) |
| GET | `/api/sales` | List sales (filter: type, branch_id, status, date_from, date_to) |
| POST | `/api/sales` | Create sale or quotation |
| GET | `/api/sales/{id}` | Sale detail with lines and stock movements |
| POST | `/api/sales/{id}/convert` | Convert quotation to sale |
| POST | `/api/sales/{id}/return` | Create return for a sale |
| GET | `/api/sales/{id}/stock-check` | Current stock per line (all_sufficient, per-line sufficient) |
| GET | `/api/stock-movements` | List stock movements (filter: product_id, warehouse_id, type) |
| POST | `/api/stock-movements` | Create stock movement (with optional idempotency_key) |

All API endpoints (except login/register) require: `Authorization: Bearer <token>`.

---

## Key Conventions

- **Tenant isolation:** Business tables include `company_id`; many also use `branch_id` and `warehouse_id`. Global scopes on User, Branch, Warehouse (and others) enforce company scope in HTTP context.
- **Roles:** Stored per company (`roles.company_id`). Assign by role instance: `$user->assignRole($role)` with `$role` already filtered by company.
- **Returns:** Use stock movement type `return_in` (canonical) for consistent reporting and accounting.
- **Soft deletes:** Used on users, branches, warehouses (and related tables) to preserve history.
- **Indexes:** Documented in `docs/tenant-auth.md` and `docs/branches-warehouses.md` (e.g. `company_id`, `branch_id`, composite indexes for POS performance).

---

## License

This project is open-sourced software. Use and modify as needed for your organization.
