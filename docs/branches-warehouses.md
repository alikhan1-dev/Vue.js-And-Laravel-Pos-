## Branches & Warehouses (Step 2)

Tenant-aware backbone for branches and warehouses: **Company → Branch → Warehouse**.

This step builds on Step 1 (tenant-auth) and encodes the data model, foreign key rules, soft deletes, indexes, and POS-ready attributes required for high-volume, multi-branch POS.

---

### 1. Database Tables

#### 1.1 `branches`

- **Fields**
  - `id`
  - `company_id` (FK → `companies.id`)
  - `default_warehouse_id` (FK → `warehouses.id`, nullable)
  - `name`
  - `code`
  - `address` (nullable)
  - `timezone` (nullable; e.g. `Asia/Karachi`, `Europe/London`)
  - `is_active` (boolean)
  - `created_by` (FK → `users.id`, nullable)
  - `updated_by` (FK → `users.id`, nullable)
  - `created_at`, `updated_at`, `deleted_at`

- **Constraints**
  - `UNIQUE(company_id, code)` — branch codes are unique **per company**, not globally.
  - Soft deletes via `deleted_at` (Laravel `SoftDeletes`):
    - Branches are never hard-deleted in normal flows; history (sales, stock, accounting) remains consistent.

- **Foreign key rules**
  - `company_id` → `companies.id` **ON DELETE CASCADE**
    - Removing a company removes its branches (tenant-level cleanup).
  - `default_warehouse_id` → `warehouses.id` **ON DELETE SET NULL**
    - If a default warehouse is removed (or soft-deleted), the branch falls back to “no default”.
  - `created_by`, `updated_by` → `users.id` **ON DELETE SET NULL**
    - If a user is deleted/soft-deleted, audit fields are nulled but branch persists.

#### 1.2 `warehouses`

- **Fields**
  - `id`
  - `company_id` (FK → `companies.id`)
  - `branch_id` (FK → `branches.id`)
  - `name`
  - `slug` (nullable, SEO/API/code-friendly identifier)
  - `code`
  - `type` (ENUM in MySQL, default `store`)
    - Allowed values (recommended):
      - `store`
      - `distribution`
      - `transit`
      - `returns`
      - `damaged`
      - `production`
  - `location` (nullable text)
  - `status` (string/enum-like, default `active`)
    - Recommended values:
      - `active`
      - `inactive`
      - `maintenance`
      - `closed`
  - `is_active` (boolean; legacy/simple flag, typically mirrors `status === 'active'`)
  - `allow_sales` (boolean, default `true`)
  - `allow_purchases` (boolean, default `true`)
  - `is_default` (boolean, default `false`)
  - `capacity_items` (nullable, approximate max item count)
  - `capacity_weight` (nullable decimal, e.g. kg)
  - `latitude` (nullable decimal)
  - `longitude` (nullable decimal)
  - `created_by` (FK → `users.id`, nullable)
  - `updated_by` (FK → `users.id`, nullable)
  - `created_at`, `updated_at`, `deleted_at`

- **Constraints**
  - `UNIQUE(company_id, branch_id, code)` — warehouse codes are unique **per company + branch**.
  - Soft deletes via `deleted_at` (Laravel `SoftDeletes`):
    - Warehouses are soft-deleted, not hard-deleted, preserving historical inventory & sales references.
  - Application logic and a MySQL constraint together enforce at most **one `is_default = true` warehouse per branch**:
    - In MySQL, a generated column `is_default_enforced` and unique index on `(branch_id, is_default_enforced)` ensure only one default per branch.

- **Foreign key rules**
  - `company_id` → `companies.id` **ON DELETE CASCADE**
    - Removing a company removes its warehouses (tenant-level cleanup).
  - `branch_id` → `branches.id` **ON DELETE RESTRICT**
    - You cannot delete a branch that still has warehouses; this protects the inventory engine (Step 3+).
  - `created_by`, `updated_by` → `users.id` **ON DELETE SET NULL**
    - Audit fields are nulled if the user is removed; warehouses remain.

> **Data Hierarchy Enforcement**
>
> - `branch.company_id == company.id`
> - `warehouse.company_id == branch.company_id`
> - `user.company_id == branch.company_id`
>
> All application logic must respect this hierarchy; you should never attach:
> - a `warehouse` to a `branch` from another company, or
> - a `user` to a `branch` from another company.
>
> In the `Warehouse` model, a `saving` hook validates that `warehouse.company_id == branch.company_id` whenever both are set.

---

### 2. Indexes (Performance-Critical)

High-volume POS requires explicit indexes to keep queries fast.

- **branches**
  - `INDEX(company_id)` — for tenant-scoped branch listings.

- **warehouses**
  - `INDEX(company_id, branch_id)` — for tenant + branch-scoped queries.
  - `INDEX(branch_id)` — for common lookups like:
    - `SELECT * FROM warehouses WHERE branch_id = ?`
  - `INDEX(company_id, code)` — for tenant-scoped lookups by code:
    - e.g. “find warehouse by code for this company”.

These are in addition to the unique constraints described above.

---

### 3. Relationships (Eloquent)

- `Company` has many `Branch` models.
- `Branch` belongs to `Company`.
- `Branch` has many `Warehouse` models.
- `Branch` has one optional `defaultWarehouse`:
  - `branches.default_warehouse_id` → `warehouses.id`.
- `Warehouse` belongs to `Branch`.
- `Warehouse` belongs to `Company`.

In code (`Branch` and `Warehouse` models):

- `Branch`:
  - `$fillable` includes `company_id`, `name`, `code`, `address`, `timezone`, `default_warehouse_id`, `is_active`, `created_by`, `updated_by`.
  - Uses `SoftDeletes`.
  - `company()` and `warehouses()` relationships.
  - `defaultWarehouse()` relationship for auto-selection in POS.

- `Warehouse`:
  - `$fillable` includes:
    - `company_id`, `branch_id`, `name`, `slug`, `code`, `type`, `location`,
    - `status`, `is_active`, `allow_sales`, `allow_purchases`,
    - `is_default`, `capacity_items`, `capacity_weight`, `latitude`, `longitude`,
    - `created_by`, `updated_by`.
  - `casts` includes:
    - `is_active`, `allow_sales`, `allow_purchases`, `is_default` as booleans.
    - `capacity_items` as integer; `capacity_weight`, `latitude`, `longitude` as floats.
  - Uses `SoftDeletes`.
  - `branch()` and `company()` relationships.
  - Deletion guard only blocks **force deletes** when inventory history exists.
  - `saving` hook enforces `warehouse.company_id == branch.company_id` at application level.

---

### 4. Tenant Isolation

- **Branch**
  - Global scope `company` filters by `company_id = auth()->user()->company_id` in HTTP context.
  - Scope is disabled in console (artisan, tinker, seeders), so you can manage cross-tenant data.

- **Warehouse**
  - Global scope `company` filters by the related branch’s `company_id`:
    - Only warehouses whose branch belongs to the authenticated user’s company are visible.
  - Scope is disabled in console (as above).

This matches the Step 1 company scope rule and ensures:

- Users only see **branches and warehouses of their company**.
- Inventory and POS logic can rely on tenant-safe defaults.

---

### 5. Warehouse Types & Transfer Readiness

The `warehouses.type`, `status`, `allow_sales`, and `allow_purchases` columns are designed for advanced POS flows:

- **Examples of `type` values**
  - `store` — front-of-house / retail store.
  - `distribution` — back-office or distribution center.
  - `transit` — in-transit stock (between branches).
  - `returns` — returned goods awaiting inspection.
  - `damaged` — damaged or scrap goods.
  - `production` — production or manufacturing buffer.

- **Examples of `status` values**
  - `active` — normal operation; POS and inventory operations allowed.
  - `inactive` — logically disabled; hidden from normal POS flows.
  - `maintenance` — temporarily unavailable (e.g., stock count, relocation).
  - `closed` — permanently closed warehouse, kept only for history.

- **Transfer-ready flags**
  - `allow_sales`:
    - `true` for sellable locations (e.g. `store`, sometimes `returns` after inspection).
    - `false` for pure storage or transit locations (e.g. `transit`).
  - `allow_purchases`:
    - `true` for locations that can directly receive purchased stock (e.g. main warehouse, some stores).
    - `false` for virtual/technical locations (e.g. `damaged` or some `returns` setups).

These attributes become critical in later steps (purchases, stock transfers, returns) to ensure that:

- POS screens only show **sale-eligible** warehouses.
- Purchase flows only target **allowed** receiving warehouses.

---

### 6. Branch Default Warehouse & Timezone

- **`branches.default_warehouse_id`**
  - POS can use this to auto-select the warehouse when a user logs into a branch.
  - Especially useful when a branch has multiple warehouses (e.g. `MAIN`, `BACKROOM`).
  - Combined with `warehouses.is_default`, the domain layer can maintain **exactly one default** per branch.

- **`branches.timezone`**
  - Optional override for company timezone.
  - Important for:
    - Daily stock snapshots.
    - End-of-day POS closing.
    - Localized reporting when a company has branches across multiple regions (e.g. Dubai, London, Pakistan).

If `branches.timezone` is null, the system should default to `company.timezone`.

---

### 7. Capacity & Geo Coordinates

- **Capacity**
  - `capacity_items` — approximate maximum item count the warehouse is designed to hold.
  - `capacity_weight` — approximate maximum total weight (e.g. kg) the warehouse can safely hold.
  - These are optional but become useful for:
    - Capacity planning.
    - Overload alerts.
    - Advanced reporting (e.g., utilization %).

- **Geo coordinates**
  - `latitude`, `longitude` — warehouse geographic position.
  - Optional but powerful for:
    - Delivery routing.
    - Mapping warehouses on a map.
    - Distance-based allocation of orders and transfers.

---

### 8. Seeding

`DatabaseSeeder` creates for the default company:

- **Branch**
  - Name: `Head Office`
  - Code: `HO`
  - `is_active`: `true`
  - Reasonable default `timezone` (e.g. `UTC` or company timezone).

- **Warehouse**
  - Name: `Main Warehouse`
  - Code: `MAIN`
  - Type: `store` (by default).
  - `status`: `active`
  - `allow_sales`: `true`
  - `allow_purchases`: `true`
  - Linked to `Head Office`.

The seeder also uses `withoutGlobalScope('company')` where needed to ensure cross-tenant bootstrap while preserving HTTP isolation.

---

### 9. Tinker Examples (Creating Branches & Warehouses)

From the project root:

```bash
php artisan tinker
```

**Create another branch for the default company**

```php
use App\Models\Company;
use App\Models\Branch;

$company = Company::where('email', 'default@company.test')->first();

$branch = Branch::withoutGlobalScope('company')->create([
    'company_id' => $company->id,
    'name' => 'Downtown Branch',
    'code' => 'DT',
    'address' => '123 Main St',
    'timezone' => 'Asia/Karachi',
    'is_active' => true,
]);
```

**Create a warehouse for that branch**

```php
use App\Models\Warehouse;

$warehouse = Warehouse::withoutGlobalScope('company')->create([
    'company_id' => $company->id,
    'branch_id' => $branch->id,
    'name' => 'Downtown Stock',
    'slug' => 'downtown-stock',
    'code' => 'STORE',
    'type' => 'store',
    'status' => 'active',
    'location' => 'Ground floor',
    'is_active' => true,
    'allow_sales' => true,
    'allow_purchases' => true,
    'is_default' => true,
    'capacity_items' => null,
    'capacity_weight' => null,
    'latitude' => null,
    'longitude' => null,
]);
```

**Set default warehouse for the branch**

```php
$branch->default_warehouse_id = $warehouse->id;
$branch->save();
```

**List branches and warehouses for a company (no global scope)**

```php
Branch::withoutGlobalScope('company')
    ->where('company_id', $company->id)
    ->get();

Warehouse::withoutGlobalScope('company')
    ->where('company_id', $company->id)
    ->get();
```

---

### 10. Verification: Tenant Isolation

1. **Two companies**
   - In tinker, create a second company and a branch for it (use `withoutGlobalScope('company')` when creating the branch and its warehouses).
2. **Login via API**
   - POST `/api/auth/login` with a user from **Company A** (e.g. `admin@company.test`). Get the token.
3. **API that lists branches**
   - When you add `GET /api/branches` that returns `Branch::all()` or `Branch::query()->get()`, call it with the token.
   - You should see only branches of Company A, not of the second company.
4. **API that lists warehouses**
   - `GET /api/warehouses` that returns `Warehouse::all()` should return only warehouses whose branch belongs to the logged-in user’s company.

So: **users only see branches and warehouses of their company**; global scopes enforce this in HTTP.

---

### 11. Testing with Postman (Optional)

All branch/warehouse endpoints require a Bearer token (login first).

1. **Login**  
   - **POST** `http://127.0.0.1:8000/api/auth/login`  
   - Body (raw JSON): `{"email":"admin@company.test","password":"password"}`  
   - Copy the `token` from the response.

2. **Get current user**  
   - **GET** `http://127.0.0.1:8000/api/user`  
   - **Authorization** tab → Type: **Bearer Token** → paste the token.  
   - You should see the authenticated user (and their company).

3. **List branches (tenant-scoped)**  
   - **GET** `http://127.00.0.1:8000/api/branches`
   - Same **Authorization** → Bearer Token.
   - You should see only branches of your company (e.g. `Head Office` with code `HO`, and any additional branches you created).

4. **List warehouses (tenant-scoped)**  
   - **GET** `http://127.0.0.1:8000/api/warehouses`  
   - Same **Authorization** → Bearer Token.
   - You should see only warehouses of your company (e.g. `Main Warehouse` with code `MAIN` and its branch).

If you create a second company and branch in tinker (with `withoutGlobalScope('company')`), then log in as a user of the first company, these endpoints still return only the first company’s branches and warehouses.

---

### 12. Summary

- **Migrations**
  - `branches`: tenant-aware, soft deletable, per-company codes, optional default warehouse and timezone, audit fields, and proper indexes.
  - `warehouses`: tenant-aware, soft deletable, per-company+branch codes; type + status + transfer flags (`allow_sales`, `allow_purchases`); default flags, capacity, geo coordinates, slug, audit fields, and proper indexes.
- **Models**
  - `Branch`, `Warehouse` with relationships, soft deletes, audit fields, per-tenant global scopes, and validation of `company_id` hierarchy.
- **Seeder**
  - Default **Head Office** branch and **Main Warehouse** for the default company (with sane defaults for type, status, and flags).
- **API**
  - **GET /api/branches**, **GET /api/warehouses`** (require `auth:sanctum`; responses are tenant-scoped).
- **Isolation**
  - Branch by `company_id`; Warehouse by `company_id` + `branch_id`; users only see their own company’s branches and warehouses.

