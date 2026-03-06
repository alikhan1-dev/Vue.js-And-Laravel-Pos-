## POS Tenant-Ready Authentication Overview

This document describes the authentication and authorization foundation for the POS + Inventory + Accounting platform.

It encodes the **company / branch / warehouse** model, role scoping, soft deletion, index strategy, and API auth rules that must hold across all later steps (inventory, POS, accounting, reporting).

### Core Tables

- **companies**
  - Fields: `id`, `name`, `email`, `currency`, `timezone`, `created_at`, `updated_at`.
  - A company represents a tenant. All business data (users, branches, warehouses, inventory, accounting) is tied to a company.

- **branches**
  - Fields: `id`, `company_id`, `name`, `code`, `address`, `is_active`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
    - `created_by` → `users.id` (nullable)
    - `updated_by` → `users.id` (nullable)
  - Uniqueness:
    - `company_id`, `code` are unique together.
  - Soft deletion:
    - Uses `deleted_at` (Laravel `SoftDeletes`) so historical references remain valid.
  - A branch is a physical or logical location under a company (e.g. `Karachi`, `Lahore`, `Islamabad` for a retail chain).

- **warehouses**
  - Fields: `id`, `company_id`, `branch_id`, `name`, `code`, `location`, `is_active`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`.
  - Foreign keys:
    - `company_id` → `companies.id` (for fast tenant filtering and joins)
    - `branch_id` → `branches.id`
    - `created_by` → `users.id` (nullable)
    - `updated_by` → `users.id` (nullable)
  - Uniqueness:
    - `company_id`, `branch_id`, `code` are unique together.
  - Soft deletion:
    - Uses `deleted_at` (Laravel `SoftDeletes`) so historical stock movements and sales still point to a valid warehouse row.
  - A warehouse is where stock is physically held for a specific branch and company.

- **roles** (company-scoped, via Spatie Laravel Permission)
  - Fields: `id`, `company_id`, `name`, `guard_name`, `created_at`, `updated_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
  - Uniqueness:
    - `company_id`, `name`, `guard_name` are unique together.
  - Examples per company: `Admin`, `Cashier`, `Manager`, `Accountant`.
  - The same role name (e.g. `Admin`) in different companies is a different record.

- **permissions** (from Spatie Laravel Permission)
  - Examples: `view_sales`, `create_sale`, `manage_inventory`, `manage_users`, `view_reports`, `manage_accounting`.
  - Permissions are global by default and are attached to roles; roles themselves are tenant-scoped by `company_id`.

- **users**
  - Fields: `id`, `company_id`, `branch_id`, `name`, `email`, `password`, `is_active`, `last_login_at`, `last_login_ip`, `created_at`, `updated_at`, `deleted_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
    - `branch_id` → `branches.id` (nullable for system users if needed)
  - Uniqueness / login:
    - `company_id`, `email` are unique together (**true multi-tenant**: same email can exist in different companies).
  - Soft deletion:
    - Uses `deleted_at` (Laravel `SoftDeletes`) so audit logs and transactions can still reference deactivated users.

> **Company Scope Rule (Step 1)**  
> All business tables must include `company_id`.  
> Operational tables should also include `branch_id` and `warehouse_id` where applicable (e.g. `stock_movements`, `stock_cache`, `stock_snapshots`, `inventory_journal`) to guarantee strict tenant isolation and fast reporting.  
> **Inventory quantities must always be associated with `warehouse_id`. Branch is derived through the warehouse → branch relationship.**

### Relationships

- `Company` has many `Branch` models.
- `Branch` belongs to `Company` and has many `Warehouse` models.
- `Warehouse` belongs to `Company` and `Branch`.
- `Company` has many `User` models.
- `Branch` has many `User` models.
- `User` belongs to `Company`.
- `User` belongs to a single `Branch` (`users.branch_id`) for branch-level isolation (cashier cannot act in another branch).
- `User` uses Spatie's `HasRoles` trait for many-to-many role/permission assignments via `model_has_roles` and `role_has_permissions`. There is no `users.role_id` any more; roles are only linked through the pivot.
- **Future-ready warehouse access control**:
  - Introduce `user_warehouses` for fine-grained POS access:
    - `user_warehouses(user_id, warehouse_id)`
  - This allows restricting a branch cashier to specific warehouses within that branch (common in supermarkets, pharmacy chains, electronics retail).

### Indexes (Performance-Critical)

The following indexes are required to keep queries fast at scale (e.g. 500k users, 5M stock movements):

- **users**
  - `INDEX(company_id, branch_id)` (typical POS query filters by both).
  - `UNIQUE(company_id, email)` (true multi-tenant: same email allowed in different companies).

- **branches**
  - `INDEX(company_id)`

- **warehouses**
  - `INDEX(company_id, branch_id)` (tenant + branch filters).
  - `INDEX(branch_id)` (fast branch-only lookups like “list warehouses for this branch”).

- **roles**
  - `INDEX(company_id)`

Additional inventory indexes (defined in later migrations) ensure:

- `stock_movements`:
  - `INDEX(company_id, product_id)` (tenant + product lookups),
  - `INDEX(product_id, warehouse_id)` (stock by warehouse),
  - `INDEX(reference_type, reference_id)` (sales, transfers, stock counts, etc.).
- `stock_cache`:
  - `INDEX(company_id, warehouse_id, product_id)` (fast stock-by-warehouse queries).

### Tenant Isolation Logic & Company Scope Rule

- `App\Models\User` defines a **global scope** named `company`:
  - For HTTP requests, whenever a user is authenticated, all `User` queries automatically include `where company_id = auth()->user()->company_id`.
  - For console commands (migrations, seeders, tinker), the scope is disabled to allow cross-tenant operations.
- `App\Models\Branch` and `App\Models\Warehouse` also apply company-aware global scopes so that branches and warehouses are automatically filtered to the authenticated user's company.
- This ensures user-, branch-, and warehouse-related queries are tenant-aware without needing to manually add `company_id` filters.
- The same company scope pattern **must** be applied to all business models such as products, inventory, sales, accounting, and reports to guarantee strict tenant isolation.
- Inventory and stock tables (e.g. `stock_movements`, `stock_cache`, `stock_snapshots`, `inventory_journal`) must always reference `warehouse_id` (not just `branch_id`); branch is derived via `warehouse.branch`.

### Roles & Permissions Extension

- Roles and permissions are managed using **Spatie Laravel Permission** with a custom `App\Models\Role`:
  - Add new permissions (global):
    - `Permission::create(['name' => 'manage_pos', 'guard_name' => 'web']);`
  - Create company-scoped roles:
    - `$role = \App\Models\Role::create(['company_id' => $company->id, 'name' => 'Manager', 'guard_name' => 'web']);`
  - Attach permissions to roles:
    - `$role->givePermissionTo('manage_pos');`
  - Assign roles to users (pivot only) using a role instance **already filtered by company**:
    - `$user->assignRole($role);`
- Tenant safety rules:
  - Roles are always created with a `company_id`.
  - UI / APIs should only show roles where `roles.company_id = auth()->user()->company_id`.
  - The same role name in two companies produces two separate role rows.

### Seeding Defaults

- `DatabaseSeeder` seeds:
  - A default company: `Default Company` (email: `default@company.test`).
  - Base permissions: `view_sales`, `create_sale`, `manage_inventory`, `manage_users`, `view_reports`, `manage_accounting`.
  - Roles per company: `Admin`, `Cashier`, `Manager`, `Accountant` with appropriate permission sets, all tied to the default company via `roles.company_id`.
  - Super admin user:
    - Email: `admin@company.test`
    - Password: `password`
    - Belongs to the default company and its head-office branch.
    - Has `Admin` role and all permissions.

### API Authentication (for Vue.js Frontend)

- Uses **Laravel Sanctum** personal access tokens and Laravel Breeze backend scaffolding.
- Token expiration:
  - `config/sanctum.php` sets `expiration` to `env('SANCTUM_EXPIRATION', 1440)` minutes (24 hours by default).
  - For POS terminals you should set `SANCTUM_EXPIRATION` to `480` (8 hours), `720` (12 hours), or `1440` (24 hours) based on session policy.
- Token rotation per device (POS terminal):
  - On each login, previous tokens with the same `device_name` are revoked:
    - `$user->tokens()->where('name', $device)->delete();`
  - This prevents token leaks and POS session duplication.

- Endpoints (`routes/api.php`):
  - `POST /api/auth/register`
    - Body: `name`, `email`, `password`, `password_confirmation`, optional `role`, optional `branch_code`.
    - Behavior:
      - Ensures a default company exists (for now, all demo users are created in this company).
      - Resolves the branch by `branch_code` for that company; if not provided, falls back to the first branch (e.g. `Head Office`).
      - Resolves a role for that company (default `Cashier` if none provided) and assigns it via Spatie's pivot, not a `role_id` column.
      - Returns a personal access token and the user (with `company`, `branch`, and `roles`).
  - `POST /api/auth/login`
    - Body: `email`, `password`, optional `device_name` (defaults to `web`).
    - On successful login:
      - Updates `users.last_login_at` and `users.last_login_ip`.
      - Revokes any existing tokens for the same device name before issuing a new one.
      - Issues a new Sanctum token respecting the configured expiration.
    - Returns: `{ token, user }` where `user` includes `company`, `branch`, and `roles`.
    - Requires `is_active = true`.
  - `POST /api/auth/logout`
    - Requires `Authorization: Bearer {token}`.
    - Revokes the current access token.

### Example Tinker Commands

Run from the project root:

```php
php artisan tinker
```

Create an additional company:

```php
use App\Models\Company;

$company = Company::create([
    'name' => 'Branch Company',
    'email' => 'branch@company.test',
    'currency' => 'USD',
    'timezone' => 'UTC',
]);
```

Create a new role and permission:

```php
use App\Models\Role;
use Spatie\Permission\Models\Permission;

$perm = Permission::create(['name' => 'view_inventory_valuation', 'guard_name' => 'web']);
$role = Role::create([
    'company_id' => $company->id,
    'name' => 'InventoryAuditor',
    'guard_name' => 'web',
]);
$role->givePermissionTo($perm);
```

Create a branch and user in a specific company:

```php
use App\Models\User;
use App\Models\Branch;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

$branch = Branch::firstOrCreate([
    'company_id' => $company->id,
    'code' => 'HO',
], [
    'name' => 'Head Office',
]);

$role = Role::where('company_id', $company->id)->firstWhere('name', 'Cashier');

$user = User::create([
    'company_id' => $company->id,
    'branch_id' => $branch->id,
    'name' => 'Branch Cashier',
    'email' => 'cashier@branch.test',
    'password' => Hash::make('secret'),
    'is_active' => true,
]);

$user->assignRole($role);
```

### Verifying Tenant Isolation

1. Log in via `POST /api/auth/login` as `admin@company.test` and get a token.
2. Use the token to call any future user-list endpoint (e.g., `/api/users` when implemented).
3. Create users and branches in a second company via tinker.
4. Confirm that queries made through Eloquent in HTTP context only return users, branches, warehouses, and inventory data for the authenticated user's company because of the global `company` scopes and `company_id` fields.

---

## How to Test the System

### 1. One-time setup (database)

From the project root (`D:\pos_system`):

```bash
php artisan migrate --seed
```

This creates all tables and seeds the default company, roles, permissions, super admin user, default branch, and default warehouses.

### 2. Start the API server

```bash
php artisan serve
```

The API will be at **http://127.0.0.1:8000**. All examples below use this base URL.

### 3. Test the API endpoints

You can use **Postman**, **Insomnia**, or **curl**. Examples below are for **Command Prompt** (use `^` to continue lines). In **PowerShell**, use backtick `` ` `` instead of `^`, or run the command as one line.

**Login (get a token)**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/login ^
  -H "Content-Type: application/json" ^
  -d "{\"email\":\"admin@company.test\",\"password\":\"password\"}"
```

- **Success:** You get JSON with `token` and `user` (company, branch, roles).
- **Copy the `token`** — you need it for the next requests.

**Get current user (protected route)**

Replace `YOUR_TOKEN` with the token from login:

```bash
curl -X GET http://127.0.0.1:8000/api/user ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -H "Accept: application/json"
```

You should see the logged-in user, with tenant and branch scope applied to any underlying `User` / `Branch` / `Warehouse` queries.

**Logout (revoke token)**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/logout ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -H "Accept: application/json"
```

After this, the same token will no longer work for `/api/user`.

**Register a new user**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/register ^
  -H "Content-Type: application/json" ^
  -d "{\"name\":\"Test Cashier\",\"email\":\"cashier@test.com\",\"password\":\"password\",\"password_confirmation\":\"password\",\"role\":\"Cashier\"}"
```

Optional: add `"branch_code":"HO"` (or another branch code for the same company).  
Response includes a new `token` and `user` (with `company`, `branch`, `roles`).

### 4. Test with Postman (optional)

1. Create a new request: **POST** `http://127.0.0.1:8000/api/auth/login`.
2. Body → **raw** → **JSON**: `{"email":"admin@company.test","password":"password"}`.
3. Send; copy the `token` from the response.
4. For **GET** `http://127.0.0.1:8000/api/user`, go to **Headers** and add:  
   `Authorization` = `Bearer YOUR_TOKEN`.
5. For **POST** `http://127.0.0.1:8000/api/auth/logout`, use the same header.

### 5. Quick check in Tinker

```bash
php artisan tinker
```

```php
// Super admin exists, is scoped to the default company and branch, and has Admin role
$u = \App\Models\User::where('email', 'admin@company.test')->first();
$u->company->name;   // "Default Company"
$u->branch->code;    // "HO" (Head Office)
$u->getRoleNames(); // ["Admin"]
$u->getAllPermissions()->pluck('name'); // all 6 permissions
```

Exit tinker with `exit`.

## POS Tenant-Ready Authentication Overview

This document describes the authentication and authorization foundation for the POS + Inventory + Accounting platform.

It encodes the **company / branch / warehouse** model, role scoping, soft deletion, index strategy, and API auth rules that must hold across all later steps (inventory, POS, accounting, reporting).

### Core Tables

- **companies**
  - Fields: `id`, `name`, `email`, `currency`, `timezone`, `created_at`, `updated_at`.
  - A company represents a tenant. All business data (users, branches, warehouses, inventory, accounting) is tied to a company.

- **branches**
  - Fields: `id`, `company_id`, `name`, `code`, `address`, `is_active`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
    - `created_by` → `users.id` (nullable)
    - `updated_by` → `users.id` (nullable)
  - Uniqueness:
    - `company_id`, `code` are unique together.
  - Soft deletion:
    - Uses `deleted_at` (Laravel `SoftDeletes`) so historical references remain valid.
  - A branch is a physical or logical location under a company (e.g. `Karachi`, `Lahore`, `Islamabad` for a retail chain).

- **warehouses**
  - Fields: `id`, `company_id`, `branch_id`, `name`, `code`, `location`, `is_active`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`.
  - Foreign keys:
    - `company_id` → `companies.id` (for fast tenant filtering and joins)
    - `branch_id` → `branches.id`
    - `created_by` → `users.id` (nullable)
    - `updated_by` → `users.id` (nullable)
  - Uniqueness:
    - `company_id`, `branch_id`, `code` are unique together.
  - Soft deletion:
    - Uses `deleted_at` (Laravel `SoftDeletes`) so historical stock movements and sales still point to a valid warehouse row.
  - A warehouse is where stock is physically held for a specific branch and company.

- **roles** (company-scoped, via Spatie Laravel Permission)
  - Fields: `id`, `company_id`, `name`, `guard_name`, `created_at`, `updated_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
  - Uniqueness:
    - `company_id`, `name`, `guard_name` are unique together.
  - Examples per company: `Admin`, `Cashier`, `Manager`, `Accountant`.
  - The same role name (e.g. `Admin`) in different companies is a different record.

- **permissions** (from Spatie Laravel Permission)
  - Examples: `view_sales`, `create_sale`, `manage_inventory`, `manage_users`, `view_reports`, `manage_accounting`.
  - Permissions are global by default and are attached to roles; roles themselves are tenant-scoped by `company_id`.

- **users**
  - Fields: `id`, `company_id`, `branch_id`, `name`, `email`, `password`, `is_active`, `last_login_at`, `last_login_ip`, `created_at`, `updated_at`, `deleted_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
    - `branch_id` → `branches.id` (nullable for system users if needed)
  - Uniqueness / login:
    - `email` is globally unique for simple login.
    - `company_id`, `email` are also unique together to support future multi-tenant login patterns.
  - Soft deletion:
    - Uses `deleted_at` (Laravel `SoftDeletes`) so audit logs and transactions can still reference deactivated users.

> **Company Scope Rule (Step 1)**  
> All business tables must include `company_id`.  
> Operational tables should also include `branch_id` and `warehouse_id` where applicable (e.g. `stock_movements`, `stock_cache`, `stock_snapshots`, `inventory_journal`) to guarantee strict tenant isolation and fast reporting.  
> **Inventory quantities must always be associated with `warehouse_id`. Branch is derived through the warehouse → branch relationship.**

### Relationships

- `Company` has many `Branch` models.
- `Branch` belongs to `Company` and has many `Warehouse` models.
- `Warehouse` belongs to `Company` and `Branch`.
- `Company` has many `User` models.
- `Branch` has many `User` models.
- `User` belongs to `Company`.
- `User` belongs to a single `Branch` (`users.branch_id`) for branch-level isolation (cashier cannot act in another branch).
- `User` uses Spatie's `HasRoles` trait for many-to-many role/permission assignments via `model_has_roles` and `role_has_permissions`. There is no `users.role_id` any more; roles are only linked through the pivot.
- **Future-ready warehouse access control**:
  - Introduce `user_warehouses` for fine-grained POS access:
    - `user_warehouses(user_id, warehouse_id)`
  - This allows restricting a branch cashier to specific warehouses within that branch (common in supermarkets, pharmacy chains, electronics retail).

### Indexes (Performance-Critical)

The following indexes are required to keep queries fast at scale (e.g. 500k users, 5M stock movements):

- **users**
  - `INDEX(company_id, branch_id)` (typical POS query filters by both).
  - `UNIQUE(email)` (fast login by email).
  - `UNIQUE(company_id, email)` (ready for multi-tenant login where the same email can exist in different companies in the future).

- **branches**
  - `INDEX(company_id)`

- **warehouses**
  - `INDEX(company_id, branch_id)` (tenant + branch filters).
  - `INDEX(branch_id)` (fast branch-only lookups like “list warehouses for this branch”).

- **roles**
  - `INDEX(company_id)`

Additional inventory indexes (defined in later migrations) ensure:

- `stock_movements`:
  - `INDEX(company_id, product_id)` (tenant + product lookups),
  - `INDEX(product_id, warehouse_id)` (stock by warehouse),
  - `INDEX(reference_type, reference_id)` (sales, transfers, stock counts, etc.).
- `stock_cache`:
  - `INDEX(company_id, warehouse_id, product_id)` (fast stock-by-warehouse queries).

### Tenant Isolation Logic & Company Scope Rule

- `App\Models\User` defines a **global scope** named `company`:
  - For HTTP requests, whenever a user is authenticated, all `User` queries automatically include `where company_id = auth()->user()->company_id`.
  - For console commands (migrations, seeders, tinker), the scope is disabled to allow cross-tenant operations.
- `App\Models\Branch` and `App\Models\Warehouse` also apply company-aware global scopes so that branches and warehouses are automatically filtered to the authenticated user's company.
- This ensures user-, branch-, and warehouse-related queries are tenant-aware without needing to manually add `company_id` filters.
- The same company scope pattern **must** be applied to all business models such as products, inventory, sales, accounting, and reports to guarantee strict tenant isolation.
- Inventory and stock tables (e.g. `stock_movements`, `stock_cache`, `stock_snapshots`, `inventory_journal`) must always reference `warehouse_id` (not just `branch_id`); branch is derived via `warehouse.branch`.

### Roles & Permissions Extension

- Roles and permissions are managed using **Spatie Laravel Permission** with a custom `App\Models\Role`:
  - Add new permissions (global):
    - `Permission::create(['name' => 'manage_pos', 'guard_name' => 'web']);`
  - Create company-scoped roles:
    - `$role = \App\Models\Role::create(['company_id' => $company->id, 'name' => 'Manager', 'guard_name' => 'web']);`
  - Attach permissions to roles:
    - `$role->givePermissionTo('manage_pos');`
  - Assign roles to users (pivot only) using a role instance **already filtered by company**:
    - `$user->assignRole($role);`
- Tenant safety rules:
  - Roles are always created with a `company_id`.
  - UI / APIs should only show roles where `roles.company_id = auth()->user()->company_id`.
  - The same role name in two companies produces two separate role rows.

### Seeding Defaults

- `DatabaseSeeder` seeds:
  - A default company: `Default Company` (email: `default@company.test`).
  - Base permissions: `view_sales`, `create_sale`, `manage_inventory`, `manage_users`, `view_reports`, `manage_accounting`.
  - Roles per company: `Admin`, `Cashier`, `Manager`, `Accountant` with appropriate permission sets, all tied to the default company via `roles.company_id`.
  - Super admin user:
    - Email: `admin@company.test`
    - Password: `password`
    - Belongs to the default company and its head-office branch.
    - Has `Admin` role and all permissions.

### API Authentication (for Vue.js Frontend)

- Uses **Laravel Sanctum** personal access tokens and Laravel Breeze backend scaffolding.
- Token expiration:
  - `config/sanctum.php` sets `expiration` to `env('SANCTUM_EXPIRATION', 1440)` minutes (24 hours by default).
  - For POS terminals you should set `SANCTUM_EXPIRATION` to `480` (8 hours), `720` (12 hours), or `1440` (24 hours) based on session policy.
- Token rotation per device (POS terminal):
  - On each login, previous tokens with the same `device_name` are revoked:
    - `$user->tokens()->where('name', $device)->delete();`
  - This prevents token leaks and POS session duplication.

- Endpoints (`routes/api.php`):
  - `POST /api/auth/register`
    - Body: `name`, `email`, `password`, `password_confirmation`, optional `role`, optional `branch_code`.
    - Behavior:
      - Ensures a default company exists (for now, all demo users are created in this company).
      - Resolves the branch by `branch_code` for that company; if not provided, falls back to the first branch (e.g. `Head Office`).
      - Resolves a role for that company (default `Cashier` if none provided) and assigns it via Spatie's pivot, not a `role_id` column.
      - Returns a personal access token and the user (with `company`, `branch`, and `roles`).
  - `POST /api/auth/login`
    - Body: `email`, `password`, optional `device_name` (defaults to `web`).
    - On successful login:
      - Updates `users.last_login_at` and `users.last_login_ip`.
      - Revokes any existing tokens for the same device name before issuing a new one.
      - Issues a new Sanctum token respecting the configured expiration.
    - Returns: `{ token, user }` where `user` includes `company`, `branch`, and `roles`.
    - Requires `is_active = true`.
  - `POST /api/auth/logout`
    - Requires `Authorization: Bearer {token}`.
    - Revokes the current access token.

### Example Tinker Commands

Run from the project root:

```php
php artisan tinker
```

Create an additional company:

```php
use App\Models\Company;

$company = Company::create([
    'name' => 'Branch Company',
    'email' => 'branch@company.test',
    'currency' => 'USD',
    'timezone' => 'UTC',
]);
```

Create a new role and permission:

```php
use App\Models\Role;
use Spatie\Permission\Models\Permission;

$perm = Permission::create(['name' => 'view_inventory_valuation', 'guard_name' => 'web']);
$role = Role::create([
    'company_id' => $company->id,
    'name' => 'InventoryAuditor',
    'guard_name' => 'web',
]);
$role->givePermissionTo($perm);
```

Create a branch and user in a specific company:

```php
use App\Models\User;
use App\Models\Branch;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

$branch = Branch::firstOrCreate([
    'company_id' => $company->id,
    'code' => 'HO',
], [
    'name' => 'Head Office',
]);

$role = Role::where('company_id', $company->id)->firstWhere('name', 'Cashier');

$user = User::create([
    'company_id' => $company->id,
    'branch_id' => $branch->id,
    'name' => 'Branch Cashier',
    'email' => 'cashier@branch.test',
    'password' => Hash::make('secret'),
    'is_active' => true,
]);

$user->assignRole($role);
```

### Verifying Tenant Isolation

1. Log in via `POST /api/auth/login` as `admin@company.test` and get a token.
2. Use the token to call any future user-list endpoint (e.g., `/api/users` when implemented).
3. Create users and branches in a second company via tinker.
4. Confirm that queries made through Eloquent in HTTP context only return users, branches, warehouses, and inventory data for the authenticated user's company because of the global `company` scopes and `company_id` fields.

---

## How to Test the System

### 1. One-time setup (database)

From the project root (`D:\pos_system`):

```bash
php artisan migrate --seed
```

This creates all tables and seeds the default company, roles, permissions, super admin user, default branch, and default warehouses.

### 2. Start the API server

```bash
php artisan serve
```

The API will be at **http://127.0.0.1:8000**. All examples below use this base URL.

### 3. Test the API endpoints

You can use **Postman**, **Insomnia**, or **curl**. Examples below are for **Command Prompt** (use `^` to continue lines). In **PowerShell**, use backtick `` ` `` instead of `^`, or run the command as one line.

**Login (get a token)**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/login ^
  -H "Content-Type: application/json" ^
  -d "{\"email\":\"admin@company.test\",\"password\":\"password\"}"
```

- **Success:** You get JSON with `token` and `user` (company, branch, roles).
- **Copy the `token`** — you need it for the next requests.

**Get current user (protected route)**

Replace `YOUR_TOKEN` with the token from login:

```bash
curl -X GET http://127.0.0.1:8000/api/user ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -H "Accept: application/json"
```

You should see the logged-in user, with tenant and branch scope applied to any underlying `User` / `Branch` / `Warehouse` queries.

**Logout (revoke token)**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/logout ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -H "Accept: application/json"
```

After this, the same token will no longer work for `/api/user`.

**Register a new user**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/register ^
  -H "Content-Type: application/json" ^
  -d "{\"name\":\"Test Cashier\",\"email\":\"cashier@test.com\",\"password\":\"password\",\"password_confirmation\":\"password\",\"role\":\"Cashier\"}"
```

Optional: add `"branch_code":"HO"` (or another branch code for the same company).  
Response includes a new `token` and `user` (with `company`, `branch`, `roles`).

### 4. Test with Postman (optional)

1. Create a new request: **POST** `http://127.0.0.1:8000/api/auth/login`.
2. Body → **raw** → **JSON**: `{"email":"admin@company.test","password":"password"}`.
3. Send; copy the `token` from the response.
4. For **GET** `http://127.0.0.1:8000/api/user`, go to **Headers** and add:  
   `Authorization` = `Bearer YOUR_TOKEN`.
5. For **POST** `http://127.0.0.1:8000/api/auth/logout`, use the same header.

### 5. Quick check in Tinker

```bash
php artisan tinker
```

```php
// Super admin exists, is scoped to the default company and branch, and has Admin role
$u = \App\Models\User::where('email', 'admin@company.test')->first();
$u->company->name;   // "Default Company"
$u->branch->code;    // "HO" (Head Office)
$u->getRoleNames(); // ["Admin"]
$u->getAllPermissions()->pluck('name'); // all 6 permissions
```

Exit tinker with `exit`.

## POS Tenant-Ready Authentication Overview

This document describes the authentication and authorization foundation for the POS + Inventory + Accounting platform.

It encodes the **company / branch / warehouse** model, role scoping, soft deletion, index strategy, and API auth rules that must hold across all later steps (inventory, POS, accounting, reporting).

### Core Tables

- **companies**
  - Fields: `id`, `name`, `email`, `currency`, `timezone`, `created_at`, `updated_at`.
  - A company represents a tenant. All business data (users, branches, warehouses, inventory, accounting) is tied to a company.

- **branches**
  - Fields: `id`, `company_id`, `name`, `code`, `address`, `is_active`, `created_at`, `updated_at`, `deleted_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
  - Uniqueness:
    - `company_id`, `code` are unique together.
  - Soft deletion:
    - Uses `deleted_at` (Laravel `SoftDeletes`) so historical references remain valid.
  - A branch is a physical or logical location under a company (e.g. `Karachi`, `Lahore`, `Islamabad` for a retail chain).

- **warehouses**
  - Fields: `id`, `company_id`, `branch_id`, `name`, `code`, `location`, `is_active`, `created_at`, `updated_at`, `deleted_at`.
  - Foreign keys:
    - `company_id` → `companies.id` (for fast tenant filtering and joins)
    - `branch_id` → `branches.id`
  - Uniqueness:
    - `company_id`, `code` are unique together.
  - Soft deletion:
    - Uses `deleted_at` (Laravel `SoftDeletes`) so historical stock movements and sales still point to a valid warehouse row.
  - A warehouse is where stock is physically held for a specific branch and company.

- **roles** (company-scoped, via Spatie Laravel Permission)
  - Fields: `id`, `company_id`, `name`, `guard_name`, `created_at`, `updated_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
  - Uniqueness:
    - `company_id`, `name`, `guard_name` are unique together.
  - Examples per company: `Admin`, `Cashier`, `Manager`, `Accountant`.
  - The same role name (e.g. `Admin`) in different companies is a different record.

- **permissions** (from Spatie Laravel Permission)
  - Examples: `view_sales`, `create_sale`, `manage_inventory`, `manage_users`, `view_reports`, `manage_accounting`.
  - Permissions are global by default and are attached to roles; roles themselves are tenant-scoped by `company_id`.

- **users**
  - Fields: `id`, `company_id`, `branch_id`, `name`, `email`, `password`, `is_active`, `last_login_at`, `last_login_ip`, `created_at`, `updated_at`, `deleted_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
    - `branch_id` → `branches.id` (nullable for system users if needed)
  - Soft deletion:
    - Uses `deleted_at` (Laravel `SoftDeletes`) so audit logs and transactions can still reference deactivated users.

> **Company Scope Rule (Step 1)**  
> All business tables must include `company_id`.  
> Operational tables should also include `branch_id` and `warehouse_id` where applicable (e.g. `stock_movements`, `stock_cache`, `stock_snapshots`, `inventory_journal`) to guarantee strict tenant isolation and fast reporting.  
> **Inventory quantities must always be associated with `warehouse_id`. Branch is derived through the warehouse → branch relationship.**

### Relationships

- `Company` has many `Branch` models.
- `Branch` belongs to `Company` and has many `Warehouse` models.
- `Warehouse` belongs to `Company` and `Branch`.
- `Company` has many `User` models.
- `Branch` has many `User` models.
- `User` belongs to `Company`.
- `User` belongs to a single `Branch` (`users.branch_id`) for branch-level isolation (cashier cannot act in another branch).
- `User` uses Spatie's `HasRoles` trait for many-to-many role/permission assignments via `model_has_roles` and `role_has_permissions`. There is no `users.role_id` any more; roles are only linked through the pivot.
- **Future-ready warehouse access control**:
  - Introduce `user_warehouses` for fine-grained POS access:
    - `user_warehouses(user_id, warehouse_id)`
  - This allows restricting a branch cashier to specific warehouses within that branch (common in supermarkets, pharmacy chains, electronics retail).

### Indexes (Performance-Critical)

The following indexes are required to keep queries fast at scale (e.g. 500k users, 5M stock movements):

- **users**
  - `INDEX(company_id, branch_id)` (typical POS query filters by both).

- **branches**
  - `INDEX(company_id)`

- **warehouses**
  - `INDEX(company_id, branch_id)`

- **roles**
  - `INDEX(company_id)`

Additional inventory indexes (defined in later migrations) ensure:

- `stock_movements`:
  - `INDEX(company_id, product_id)` (tenant + product lookups),
  - `INDEX(product_id, warehouse_id)` (stock by warehouse),
  - `INDEX(reference_type, reference_id)` (sales, transfers, stock counts, etc.).
- `stock_cache`:
  - `INDEX(company_id, warehouse_id, product_id)` (fast stock-by-warehouse queries).

### Tenant Isolation Logic & Company Scope Rule

- `App\Models\User` defines a **global scope** named `company`:
  - For HTTP requests, whenever a user is authenticated, all `User` queries automatically include `where company_id = auth()->user()->company_id`.
  - For console commands (migrations, seeders, tinker), the scope is disabled to allow cross-tenant operations.
- `App\Models\Branch` and `App\Models\Warehouse` also apply company-aware global scopes so that branches and warehouses are automatically filtered to the authenticated user's company.
- This ensures user-, branch-, and warehouse-related queries are tenant-aware without needing to manually add `company_id` filters.
- The same company scope pattern **must** be applied to all business models such as products, inventory, sales, accounting, and reports to guarantee strict tenant isolation.
- Inventory and stock tables (e.g. `stock_movements`, `stock_cache`, `stock_snapshots`, `inventory_journal`) must always reference `warehouse_id` (not just `branch_id`); branch is derived via `warehouse.branch`.

### Roles & Permissions Extension

- Roles and permissions are managed using **Spatie Laravel Permission** with a custom `App\Models\Role`:
  - Add new permissions (global):
    - `Permission::create(['name' => 'manage_pos', 'guard_name' => 'web']);`
  - Create company-scoped roles:
    - `$role = \App\Models\Role::create(['company_id' => $company->id, 'name' => 'Manager', 'guard_name' => 'web']);`
  - Attach permissions to roles:
    - `$role->givePermissionTo('manage_pos');`
  - Assign roles to users (pivot only) using a role instance **already filtered by company**:
    - `$user->assignRole($role);`
- Tenant safety rules:
  - Roles are always created with a `company_id`.
  - UI / APIs should only show roles where `roles.company_id = auth()->user()->company_id`.
  - The same role name in two companies produces two separate role rows.

### Seeding Defaults

- `DatabaseSeeder` seeds:
  - A default company: `Default Company` (email: `default@company.test`).
  - Base permissions: `view_sales`, `create_sale`, `manage_inventory`, `manage_users`, `view_reports`, `manage_accounting`.
  - Roles per company: `Admin`, `Cashier`, `Manager`, `Accountant` with appropriate permission sets, all tied to the default company via `roles.company_id`.
  - Super admin user:
    - Email: `admin@company.test`
    - Password: `password`
    - Belongs to the default company and its head-office branch.
    - Has `Admin` role and all permissions.

### API Authentication (for Vue.js Frontend)

- Uses **Laravel Sanctum** personal access tokens and Laravel Breeze backend scaffolding.
- Token expiration:
  - `config/sanctum.php` sets `expiration` to `env('SANCTUM_EXPIRATION', 1440)` minutes (24 hours by default).
  - For POS terminals you should set `SANCTUM_EXPIRATION` to `480` (8 hours), `720` (12 hours), or `1440` (24 hours) based on session policy.
- Token rotation per device (POS terminal):
  - On each login, previous tokens with the same `device_name` are revoked:
    - `$user->tokens()->where('name', $device)->delete();`
  - This prevents token leaks and POS session duplication.

- Endpoints (`routes/api.php`):
  - `POST /api/auth/register`
    - Body: `name`, `email`, `password`, `password_confirmation`, optional `role`, optional `branch_code`.
    - Behavior:
      - Ensures a default company exists (for now, all demo users are created in this company).
      - Resolves the branch by `branch_code` for that company; if not provided, falls back to the first branch (e.g. `Head Office`).
      - Resolves a role for that company (default `Cashier` if none provided) and assigns it via Spatie's pivot, not a `role_id` column.
      - Returns a personal access token and the user (with `company`, `branch`, and `roles`).
  - `POST /api/auth/login`
    - Body: `email`, `password`, optional `device_name` (defaults to `web`).
    - On successful login:
      - Updates `users.last_login_at` and `users.last_login_ip`.
      - Revokes any existing tokens for the same device name before issuing a new one.
      - Issues a new Sanctum token respecting the configured expiration.
    - Returns: `{ token, user }` where `user` includes `company`, `branch`, and `roles`.
    - Requires `is_active = true`.
  - `POST /api/auth/logout`
    - Requires `Authorization: Bearer {token}`.
    - Revokes the current access token.

### Example Tinker Commands

Run from the project root:

```php
php artisan tinker
```

Create an additional company:

```php
use App\Models\Company;

$company = Company::create([
    'name' => 'Branch Company',
    'email' => 'branch@company.test',
    'currency' => 'USD',
    'timezone' => 'UTC',
]);
```

Create a new role and permission:

```php
use App\Models\Role;
use Spatie\Permission\Models\Permission;

$perm = Permission::create(['name' => 'view_inventory_valuation', 'guard_name' => 'web']);
$role = Role::create([
    'company_id' => $company->id,
    'name' => 'InventoryAuditor',
    'guard_name' => 'web',
]);
$role->givePermissionTo($perm);
```

Create a branch and user in a specific company:

```php
use App\Models\User;
use App\Models\Branch;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

$branch = Branch::firstOrCreate([
    'company_id' => $company->id,
    'code' => 'HO',
], [
    'name' => 'Head Office',
]);

$role = Role::where('company_id', $company->id)->firstWhere('name', 'Cashier');

$user = User::create([
    'company_id' => $company->id,
    'branch_id' => $branch->id,
    'name' => 'Branch Cashier',
    'email' => 'cashier@branch.test',
    'password' => Hash::make('secret'),
    'is_active' => true,
]);

$user->assignRole($role);
```

### Verifying Tenant Isolation

1. Log in via `POST /api/auth/login` as `admin@company.test` and get a token.
2. Use the token to call any future user-list endpoint (e.g., `/api/users` when implemented).
3. Create users and branches in a second company via tinker.
4. Confirm that queries made through Eloquent in HTTP context only return users, branches, warehouses, and inventory data for the authenticated user's company because of the global `company` scopes and `company_id` fields.

---

## How to Test the System

### 1. One-time setup (database)

From the project root (`D:\pos_system`):

```bash
php artisan migrate --seed
```

This creates all tables and seeds the default company, roles, permissions, super admin user, default branch, and default warehouses.

### 2. Start the API server

```bash
php artisan serve
```

The API will be at **http://127.0.0.1:8000**. All examples below use this base URL.

### 3. Test the API endpoints

You can use **Postman**, **Insomnia**, or **curl**. Examples below are for **Command Prompt** (use `^` to continue lines). In **PowerShell**, use backtick `` ` `` instead of `^`, or run the command as one line.

**Login (get a token)**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/login ^
  -H "Content-Type: application/json" ^
  -d "{\"email\":\"admin@company.test\",\"password\":\"password\"}"
```

- **Success:** You get JSON with `token` and `user` (company, branch, roles).
- **Copy the `token`** — you need it for the next requests.

**Get current user (protected route)**

Replace `YOUR_TOKEN` with the token from login:

```bash
curl -X GET http://127.0.0.1:8000/api/user ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -H "Accept: application/json"
```

You should see the logged-in user, with tenant and branch scope applied to any underlying `User` / `Branch` / `Warehouse` queries.

**Logout (revoke token)**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/logout ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -H "Accept: application/json"
```

After this, the same token will no longer work for `/api/user`.

**Register a new user**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/register ^
  -H "Content-Type: application/json" ^
  -d "{\"name\":\"Test Cashier\",\"email\":\"cashier@test.com\",\"password\":\"password\",\"password_confirmation\":\"password\",\"role\":\"Cashier\"}"
```

Optional: add `"branch_code":"HO"` (or another branch code for the same company).  
Response includes a new `token` and `user` (with `company`, `branch`, `roles`).

### 4. Test with Postman (optional)

1. Create a new request: **POST** `http://127.0.0.1:8000/api/auth/login`.
2. Body → **raw** → **JSON**: `{"email":"admin@company.test","password":"password"}`.
3. Send; copy the `token` from the response.
4. For **GET** `http://127.0.0.1:8000/api/user`, go to **Headers** and add:  
   `Authorization` = `Bearer YOUR_TOKEN`.
5. For **POST** `http://127.0.0.1:8000/api/auth/logout`, use the same header.

### 5. Quick check in Tinker

```bash
php artisan tinker
```

```php
// Super admin exists, is scoped to the default company and branch, and has Admin role
$u = \App\Models\User::where('email', 'admin@company.test')->first();
$u->company->name;   // "Default Company"
$u->branch->code;    // "HO" (Head Office)
$u->getRoleNames(); // ["Admin"]
$u->getAllPermissions()->pluck('name'); // all 6 permissions
```

Exit tinker with `exit`.

## POS Tenant-Ready Authentication Overview

This document describes the authentication and authorization foundation for the POS + Inventory + Accounting platform.

It encodes the **company / branch / warehouse** model, role scoping, index strategy, and API auth rules that must hold across all later steps (inventory, POS, accounting, reporting).

### Core Tables

- **companies**
  - Fields: `id`, `name`, `email`, `currency`, `timezone`, `created_at`, `updated_at`.
  - A company represents a tenant. All business data (users, branches, warehouses, inventory, accounting) is tied to a company.

- **branches**
  - Fields: `id`, `company_id`, `name`, `code`, `address`, `is_active`, `created_at`, `updated_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
  - Uniqueness:
    - `company_id`, `code` are unique together.
  - A branch is a physical or logical location under a company (e.g. `Karachi`, `Lahore`, `Islamabad` for a retail chain).

- **warehouses**
  - Fields: `id`, `company_id`, `branch_id`, `name`, `code`, `location`, `is_active`, `created_at`, `updated_at`.
  - Foreign keys:
    - `company_id` → `companies.id` (for fast tenant filtering and joins)
    - `branch_id` → `branches.id`
  - Uniqueness:
    - `company_id`, `code` are unique together.
  - A warehouse is where stock is held for a specific branch and company.

- **roles** (company-scoped, via Spatie Laravel Permission)
  - Fields: `id`, `company_id`, `name`, `guard_name`, `created_at`, `updated_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
  - Uniqueness:
    - `company_id`, `name`, `guard_name` are unique together.
  - Examples per company: `Admin`, `Cashier`, `Manager`, `Accountant`.
  - The same role name (e.g. `Admin`) in different companies is a different record.

- **permissions** (from Spatie Laravel Permission)
  - Examples: `view_sales`, `create_sale`, `manage_inventory`, `manage_users`, `view_reports`, `manage_accounting`.
  - Permissions are global by default and are attached to roles; roles themselves are tenant-scoped by `company_id`.

- **users**
  - Fields: `id`, `company_id`, `branch_id`, `name`, `email`, `password`, `is_active`, `last_login_at`, `last_login_ip`, `created_at`, `updated_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
    - `branch_id` → `branches.id` (nullable for system users if needed)

> **Company Scope Rule (Step 1)**  
> All business tables must include `company_id`.  
> Operational tables should also include `branch_id` and `warehouse_id` where applicable (e.g. `stock_movements`, `stock_cache`, `stock_snapshots`, `inventory_journal`) to guarantee strict tenant isolation and fast reporting.

### Relationships

- `Company` has many `Branch` models.
- `Branch` belongs to `Company` and has many `Warehouse` models.
- `Warehouse` belongs to `Company` and `Branch`.
- `Company` has many `User` models.
- `Branch` has many `User` models.
- `User` belongs to `Company`.
- `User` belongs to a single `Branch` (`users.branch_id`) for branch-level isolation (cashier cannot act in another branch).
- `User` uses Spatie's `HasRoles` trait for many-to-many role/permission assignments via `model_has_roles` and `role_has_permissions`. There is no `users.role_id` any more; roles are only linked through the pivot.
- **Future-ready warehouse access control**:
  - Introduce `user_warehouses` for fine-grained POS access:
    - `user_warehouses(user_id, warehouse_id)`
  - This allows restricting a branch cashier to specific warehouses within that branch (common in supermarkets, pharmacy chains, electronics retail).

### Indexes (Performance-Critical)

The following indexes are required to keep queries fast at scale (e.g. 500k users, 5M stock movements):

- **users**
  - `INDEX(company_id)`
  - `INDEX(branch_id)`

- **branches**
  - `INDEX(company_id)`

- **warehouses**
  - `INDEX(company_id)`
  - `INDEX(branch_id)`

- **roles**
  - `INDEX(company_id)`

Additional inventory indexes (defined in later migrations) ensure:

- `stock_movements`: `INDEX(company_id, product_id)`, `INDEX(product_id, warehouse_id)`, `INDEX(reference_type, reference_id)`.
- `stock_cache`: `INDEX(company_id, warehouse_id, product_id)`.

### Tenant Isolation Logic & Company Scope Rule

- `App\Models\User` defines a **global scope** named `company`:
  - For HTTP requests, whenever a user is authenticated, all `User` queries automatically include `where company_id = auth()->user()->company_id`.
  - For console commands (migrations, seeders, tinker), the scope is disabled to allow cross-tenant operations.
- `App\Models\Branch` and `App\Models\Warehouse` also apply company-aware global scopes so that branches and warehouses are automatically filtered to the authenticated user's company.
- This ensures user-, branch-, and warehouse-related queries are tenant-aware without needing to manually add `company_id` filters.
- The same company scope pattern **must** be applied to all business models such as products, inventory, sales, accounting, and reports to guarantee strict tenant isolation.

### Roles & Permissions Extension

- Roles and permissions are managed using **Spatie Laravel Permission** with a custom `App\Models\Role`:
  - Add new permissions (global):
    - `Permission::create(['name' => 'manage_pos', 'guard_name' => 'web']);`
  - Create company-scoped roles:
    - `$role = \App\Models\Role::create(['company_id' => $company->id, 'name' => 'Manager', 'guard_name' => 'web']);`
  - Attach permissions to roles:
    - `$role->givePermissionTo('manage_pos');`
  - Assign roles to users (pivot only) using a role instance **already filtered by company**:
    - `$user->assignRole($role);`
- Tenant safety rules:
  - Roles are always created with a `company_id`.
  - UI / APIs should only show roles where `roles.company_id = auth()->user()->company_id`.
  - The same role name in two companies produces two separate role rows.

### Seeding Defaults

- `DatabaseSeeder` seeds:
  - A default company: `Default Company` (email: `default@company.test`).
  - Base permissions: `view_sales`, `create_sale`, `manage_inventory`, `manage_users`, `view_reports`, `manage_accounting`.
  - Roles per company: `Admin`, `Cashier`, `Manager`, `Accountant` with appropriate permission sets, all tied to the default company via `roles.company_id`.
  - Super admin user:
    - Email: `admin@company.test`
    - Password: `password`
    - Belongs to the default company and its head-office branch.
    - Has `Admin` role and all permissions.

### API Authentication (for Vue.js Frontend)

- Uses **Laravel Sanctum** personal access tokens and Laravel Breeze backend scaffolding.
- Token expiration:
  - `config/sanctum.php` sets `expiration` to `env('SANCTUM_EXPIRATION', 1440)` minutes (24 hours by default).
  - For POS terminals you should set `SANCTUM_EXPIRATION` to `480` (8 hours), `720` (12 hours), or `1440` (24 hours) based on session policy.
- Token rotation per device (POS terminal):
  - On each login, previous tokens with the same `device_name` are revoked:
    - `$user->tokens()->where('name', $device)->delete();`
  - This prevents token leaks and POS session duplication.

- Endpoints (`routes/api.php`):
  - `POST /api/auth/register`
    - Body: `name`, `email`, `password`, `password_confirmation`, optional `role`, optional `branch_code`.
    - Behavior:
      - Ensures a default company exists (for now, all demo users are created in this company).
      - Resolves the branch by `branch_code` for that company; if not provided, falls back to the first branch (e.g. `Head Office`).
      - Resolves a role for that company (default `Cashier` if none provided) and assigns it via Spatie's pivot, not a `role_id` column.
      - Returns a personal access token and the user (with `company`, `branch`, and `roles`).
  - `POST /api/auth/login`
    - Body: `email`, `password`, optional `device_name` (defaults to `web`).
    - On successful login:
      - Updates `users.last_login_at` and `users.last_login_ip`.
      - Revokes any existing tokens for the same device name before issuing a new one.
      - Issues a new Sanctum token respecting the configured expiration.
    - Returns: `{ token, user }` where `user` includes `company`, `branch`, and `roles`.
    - Requires `is_active = true`.
  - `POST /api/auth/logout`
    - Requires `Authorization: Bearer {token}`.
    - Revokes the current access token.

### Example Tinker Commands

Run from the project root:

```php
php artisan tinker
```

Create an additional company:

```php
use App\Models\Company;

$company = Company::create([
    'name' => 'Branch Company',
    'email' => 'branch@company.test',
    'currency' => 'USD',
    'timezone' => 'UTC',
]);
```

Create a new role and permission:

```php
use App\Models\Role;
use Spatie\Permission\Models\Permission;

$perm = Permission::create(['name' => 'view_inventory_valuation', 'guard_name' => 'web']);
$role = Role::create([
    'company_id' => $company->id,
    'name' => 'InventoryAuditor',
    'guard_name' => 'web',
]);
$role->givePermissionTo($perm);
```

Create a branch and user in a specific company:

```php
use App\Models\User;
use App\Models\Branch;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

$branch = Branch::firstOrCreate([
    'company_id' => $company->id,
    'code' => 'HO',
], [
    'name' => 'Head Office',
]);

$role = Role::where('company_id', $company->id)->firstWhere('name', 'Cashier');

$user = User::create([
    'company_id' => $company->id,
    'branch_id' => $branch->id,
    'name' => 'Branch Cashier',
    'email' => 'cashier@branch.test',
    'password' => Hash::make('secret'),
    'is_active' => true,
]);

$user->assignRole($role);
```

### Verifying Tenant Isolation

1. Log in via `POST /api/auth/login` as `admin@company.test` and get a token.
2. Use the token to call any future user-list endpoint (e.g., `/api/users` when implemented).
3. Create users and branches in a second company via tinker.
4. Confirm that queries made through Eloquent in HTTP context only return users, branches, warehouses, and inventory data for the authenticated user's company because of the global `company` scopes and `company_id` fields.

---

## How to Test the System

### 1. One-time setup (database)

From the project root (`D:\pos_system`):

```bash
php artisan migrate --seed
```

This creates all tables and seeds the default company, roles, permissions, super admin user, default branch, and default warehouses.

### 2. Start the API server

```bash
php artisan serve
```

The API will be at **http://127.0.0.1:8000**. All examples below use this base URL.

### 3. Test the API endpoints

You can use **Postman**, **Insomnia**, or **curl**. Examples below are for **Command Prompt** (use `^` to continue lines). In **PowerShell**, use backtick `` ` `` instead of `^`, or run the command as one line.

**Login (get a token)**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/login ^
  -H "Content-Type: application/json" ^
  -d "{\"email\":\"admin@company.test\",\"password\":\"password\"}"
```

- **Success:** You get JSON with `token` and `user` (company, branch, roles).
- **Copy the `token`** — you need it for the next requests.

**Get current user (protected route)**

Replace `YOUR_TOKEN` with the token from login:

```bash
curl -X GET http://127.0.0.1:8000/api/user ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -H "Accept: application/json"
```

You should see the logged-in user, with tenant and branch scope applied to any underlying `User` / `Branch` / `Warehouse` queries.

**Logout (revoke token)**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/logout ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -H "Accept: application/json"
```

After this, the same token will no longer work for `/api/user`.

**Register a new user**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/register ^
  -H "Content-Type: application/json" ^
  -d "{\"name\":\"Test Cashier\",\"email\":\"cashier@test.com\",\"password\":\"password\",\"password_confirmation\":\"password\",\"role\":\"Cashier\"}"
```

Optional: add `"branch_code":"HO"` (or another branch code for the same company).  
Response includes a new `token` and `user` (with `company`, `branch`, `roles`).

### 4. Test with Postman (optional)

1. Create a new request: **POST** `http://127.0.0.1:8000/api/auth/login`.
2. Body → **raw** → **JSON**: `{"email":"admin@company.test","password":"password"}`.
3. Send; copy the `token` from the response.
4. For **GET** `http://127.0.0.1:8000/api/user`, go to **Headers** and add:  
   `Authorization` = `Bearer YOUR_TOKEN`.
5. For **POST** `http://127.0.0.1:8000/api/auth/logout`, use the same header.

### 5. Quick check in Tinker

```bash
php artisan tinker
```

```php
// Super admin exists, is scoped to the default company and branch, and has Admin role
$u = \App\Models\User::where('email', 'admin@company.test')->first();
$u->company->name;   // "Default Company"
$u->branch->code;    // "HO" (Head Office)
$u->getRoleNames(); // ["Admin"]
$u->getAllPermissions()->pluck('name'); // all 6 permissions
```

Exit tinker with `exit`.

## POS Tenant-Ready Authentication Overview

This document describes the authentication and authorization foundation for the POS + Inventory + Accounting platform.

It encodes the **company / branch / warehouse** model, role scoping, index strategy, and API auth rules that must hold across all later steps (inventory, POS, accounting, reporting).

### Core Tables

- **companies**
  - Fields: `id`, `name`, `email`, `currency`, `timezone`, `created_at`, `updated_at`.
  - A company represents a tenant. All business data (users, branches, warehouses, inventory, accounting) is tied to a company.

- **branches**
  - Fields: `id`, `company_id`, `name`, `code`, `address`, `is_active`, `created_at`, `updated_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
  - Uniqueness:
    - `company_id`, `code` are unique together.
  - A branch is a physical or logical location under a company (e.g. `Karachi`, `Lahore`, `Islamabad` for a retail chain).

- **warehouses**
  - Fields: `id`, `company_id`, `branch_id`, `name`, `code`, `location`, `is_active`, `created_at`, `updated_at`.
  - Foreign keys:
    - `company_id` → `companies.id` (for fast tenant filtering and joins)
    - `branch_id` → `branches.id`
  - Uniqueness:
    - `company_id`, `code` are unique together.
  - A warehouse is where stock is held for a specific branch and company.

- **roles** (company-scoped, via Spatie Laravel Permission)
  - Fields: `id`, `company_id`, `name`, `guard_name`, `created_at`, `updated_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
  - Uniqueness:
    - `company_id`, `name`, `guard_name` are unique together.
  - Examples per company: `Admin`, `Cashier`, `Manager`, `Accountant`.
  - The same role name (e.g. `Admin`) in different companies is a different record.

- **permissions** (from Spatie Laravel Permission)
  - Examples: `view_sales`, `create_sale`, `manage_inventory`, `manage_users`, `view_reports`, `manage_accounting`.
  - Permissions are global by default and are attached to roles; roles themselves are tenant-scoped by `company_id`.

- **users**
  - Fields: `id`, `company_id`, `branch_id`, `name`, `email`, `password`, `is_active`, `last_login_at`, `last_login_ip`, `created_at`, `updated_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
    - `branch_id` → `branches.id` (nullable for system users if needed)

> **Company Scope Rule (Step 1)**  
> All business tables must include `company_id`.  
> Operational tables should also include `branch_id` and `warehouse_id` where applicable (e.g. `stock_movements`, `stock_cache`, `stock_snapshots`, `inventory_journal`) to guarantee strict tenant isolation and fast reporting.

### Relationships

- `Company` has many `Branch` models.
- `Branch` belongs to `Company` and has many `Warehouse` models.
- `Warehouse` belongs to `Company` and `Branch`.
- `Company` has many `User` models.
- `Branch` has many `User` models.
- `User` belongs to `Company`.
- `User` belongs to a single `Branch` (`users.branch_id`) for branch-level isolation (cashier cannot act in another branch).
- `User` uses Spatie's `HasRoles` trait for many-to-many role/permission assignments via `model_has_roles` and `role_has_permissions`. There is no `users.role_id` any more; roles are only linked through the pivot.
- **Future-ready warehouse access control**:
  - Introduce `user_warehouses` for fine-grained POS access:
    - `user_warehouses(user_id, warehouse_id)`
  - This allows restricting a branch cashier to specific warehouses within that branch (common in supermarkets, pharmacy chains, electronics retail).

### Indexes (Performance-Critical)

The following indexes are required to keep queries fast at scale (e.g. 500k users, 5M stock movements):

- **users**
  - `INDEX(company_id)`
  - `INDEX(branch_id)`

- **branches**
  - `INDEX(company_id)`

- **warehouses**
  - `INDEX(company_id)`
  - `INDEX(branch_id)`

- **roles**
  - `INDEX(company_id)`

Additional inventory indexes (defined in later migrations) ensure:

- `stock_movements`: `INDEX(company_id, product_id)`, `INDEX(product_id, warehouse_id)`, `INDEX(reference_type, reference_id)`.
- `stock_cache`: `INDEX(company_id, warehouse_id, product_id)`.

### Tenant Isolation Logic & Company Scope Rule

- `App\Models\User` defines a **global scope** named `company`:
  - For HTTP requests, whenever a user is authenticated, all `User` queries automatically include `where company_id = auth()->user()->company_id`.
  - For console commands (migrations, seeders, tinker), the scope is disabled to allow cross-tenant operations.
- `App\Models\Branch` and `App\Models\Warehouse` also apply company-aware global scopes so that branches and warehouses are automatically filtered to the authenticated user's company.
- This ensures user-, branch-, and warehouse-related queries are tenant-aware without needing to manually add `company_id` filters.
- The same company scope pattern **must** be applied to all business models such as products, inventory, sales, accounting, and reports to guarantee strict tenant isolation.

### Roles & Permissions Extension

- Roles and permissions are managed using **Spatie Laravel Permission** with a custom `App\Models\Role`:
  - Add new permissions (global):
    - `Permission::create(['name' => 'manage_pos', 'guard_name' => 'web']);`
  - Create company-scoped roles:
    - `$role = \App\Models\Role::create(['company_id' => $company->id, 'name' => 'Manager', 'guard_name' => 'web']);`
  - Attach permissions to roles:
    - `$role->givePermissionTo('manage_pos');`
  - Assign roles to users (pivot only) using a role instance **already filtered by company**:
    - `$user->assignRole($role);`
- Tenant safety rules:
  - Roles are always created with a `company_id`.
  - UI / APIs should only show roles where `roles.company_id = auth()->user()->company_id`.
  - The same role name in two companies produces two separate role rows.

### Seeding Defaults

- `DatabaseSeeder` seeds:
  - A default company: `Default Company` (email: `default@company.test`).
  - Base permissions: `view_sales`, `create_sale`, `manage_inventory`, `manage_users`, `view_reports`, `manage_accounting`.
  - Roles per company: `Admin`, `Cashier`, `Manager`, `Accountant` with appropriate permission sets, all tied to the default company via `roles.company_id`.
  - Super admin user:
    - Email: `admin@company.test`
    - Password: `password`
    - Belongs to the default company and its head-office branch.
    - Has `Admin` role and all permissions.

### API Authentication (for Vue.js Frontend)

- Uses **Laravel Sanctum** personal access tokens and Laravel Breeze backend scaffolding.
- Token expiration:
  - `config/sanctum.php` sets `expiration` to `env('SANCTUM_EXPIRATION', 1440)` minutes (24 hours by default).
  - For POS terminals you should set `SANCTUM_EXPIRATION` to `480` (8 hours), `720` (12 hours), or `1440` (24 hours) based on session policy.
- Token rotation per device (POS terminal):
  - On each login, previous tokens with the same `device_name` are revoked:
    - `$user->tokens()->where('name', $device)->delete();`
  - This prevents token leaks and POS session duplication.

- Endpoints (`routes/api.php`):
  - `POST /api/auth/register`
    - Body: `name`, `email`, `password`, `password_confirmation`, optional `role`, optional `branch_code`.
    - Behavior:
      - Ensures a default company exists (for now, all demo users are created in this company).
      - Resolves the branch by `branch_code` for that company; if not provided, falls back to the first branch (e.g. `Head Office`).
      - Resolves a role for that company (default `Cashier` if none provided) and assigns it via Spatie's pivot, not a `role_id` column.
      - Returns a personal access token and the user (with `company`, `branch`, and `roles`).
  - `POST /api/auth/login`
    - Body: `email`, `password`, optional `device_name` (defaults to `web`).
    - On successful login:
      - Updates `users.last_login_at` and `users.last_login_ip`.
      - Revokes any existing tokens for the same device name before issuing a new one.
      - Issues a new Sanctum token respecting the configured expiration.
    - Returns: `{ token, user }` where `user` includes `company`, `branch`, and `roles`.
    - Requires `is_active = true`.
  - `POST /api/auth/logout`
    - Requires `Authorization: Bearer {token}`.
    - Revokes the current access token.

### Example Tinker Commands

Run from the project root:

```php
php artisan tinker
```

Create an additional company:

```php
use App\Models\Company;

$company = Company::create([
    'name' => 'Branch Company',
    'email' => 'branch@company.test',
    'currency' => 'USD',
    'timezone' => 'UTC',
]);
```

Create a new role and permission:

```php
use App\Models\Role;
use Spatie\Permission\Models\Permission;

$perm = Permission::create(['name' => 'view_inventory_valuation', 'guard_name' => 'web']);
$role = Role::create([
    'company_id' => $company->id,
    'name' => 'InventoryAuditor',
    'guard_name' => 'web',
]);
$role->givePermissionTo($perm);
```

Create a branch and user in a specific company:

```php
use App\Models\User;
use App\Models\Branch;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

$branch = Branch::firstOrCreate([
    'company_id' => $company->id,
    'code' => 'HO',
], [
    'name' => 'Head Office',
]);

$role = Role::where('company_id', $company->id)->firstWhere('name', 'Cashier');

$user = User::create([
    'company_id' => $company->id,
    'branch_id' => $branch->id,
    'name' => 'Branch Cashier',
    'email' => 'cashier@branch.test',
    'password' => Hash::make('secret'),
    'is_active' => true,
]);

$user->assignRole($role);
```

### Verifying Tenant Isolation

1. Log in via `POST /api/auth/login` as `admin@company.test` and get a token.
2. Use the token to call any future user-list endpoint (e.g., `/api/users` when implemented).
3. Create users and branches in a second company via tinker.
4. Confirm that queries made through Eloquent in HTTP context only return users, branches, warehouses, and inventory data for the authenticated user's company because of the global `company` scopes and `company_id` fields.

---

## How to Test the System

### 1. One-time setup (database)

From the project root (`D:\pos_system`):

```bash
php artisan migrate --seed
```

This creates all tables and seeds the default company, roles, permissions, super admin user, default branch, and default warehouses.

### 2. Start the API server

```bash
php artisan serve
```

The API will be at **http://127.0.0.1:8000**. All examples below use this base URL.

### 3. Test the API endpoints

You can use **Postman**, **Insomnia**, or **curl**. Examples below are for **Command Prompt** (use `^` to continue lines). In **PowerShell**, use backtick `` ` `` instead of `^`, or run the command as one line.

**Login (get a token)**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/login ^
  -H "Content-Type: application/json" ^
  -d "{\"email\":\"admin@company.test\",\"password\":\"password\"}"
```

- **Success:** You get JSON with `token` and `user` (company, branch, roles).
- **Copy the `token`** — you need it for the next requests.

**Get current user (protected route)**

Replace `YOUR_TOKEN` with the token from login:

```bash
curl -X GET http://127.0.0.1:8000/api/user ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -H "Accept: application/json"
```

You should see the logged-in user, with tenant and branch scope applied to any underlying `User` / `Branch` / `Warehouse` queries.

**Logout (revoke token)**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/logout ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -H "Accept: application/json"
```

After this, the same token will no longer work for `/api/user`.

**Register a new user**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/register ^
  -H "Content-Type: application/json" ^
  -d "{\"name\":\"Test Cashier\",\"email\":\"cashier@test.com\",\"password\":\"password\",\"password_confirmation\":\"password\",\"role\":\"Cashier\"}"
```

Optional: add `"branch_code":"HO"` (or another branch code for the same company).  
Response includes a new `token` and `user` (with `company`, `branch`, `roles`).

### 4. Test with Postman (optional)

1. Create a new request: **POST** `http://127.0.0.1:8000/api/auth/login`.
2. Body → **raw** → **JSON**: `{"email":"admin@company.test","password":"password"}`.
3. Send; copy the `token` from the response.
4. For **GET** `http://127.0.0.1:8000/api/user`, go to **Headers** and add:  
   `Authorization` = `Bearer YOUR_TOKEN`.
5. For **POST** `http://127.0.0.1:8000/api/auth/logout`, use the same header.

### 5. Quick check in Tinker

```bash
php artisan tinker
```

```php
// Super admin exists, is scoped to the default company and branch, and has Admin role
$u = \App\Models\User::where('email', 'admin@company.test')->first();
$u->company->name;   // "Default Company"
$u->branch->code;    // "HO" (Head Office)
$u->getRoleNames(); // ["Admin"]
$u->getAllPermissions()->pluck('name'); // all 6 permissions
```

Exit tinker with `exit`.

## POS Tenant-Ready Authentication Overview

This document describes the authentication and authorization foundation for the POS + Inventory + Accounting platform.

### Core Tables

- **companies**
  - Fields: `id`, `name`, `email`, `currency`, `timezone`, `created_at`, `updated_at`.
  - A company represents a tenant. All business data (users, branches, warehouses, inventory, accounting) is tied to a company.

- **branches**
  - Fields: `id`, `company_id`, `name`, `code`, `address`, `is_active`, `created_at`, `updated_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
  - A branch is a physical or logical location under a company (e.g. `Karachi`, `Lahore`, `Islamabad` for a retail chain).

- **warehouses**
  - Fields: `id`, `branch_id`, `name`, `code`, `location`, `is_active`, `created_at`, `updated_at`.
  - Foreign keys:
    - `branch_id` → `branches.id`
  - A warehouse is where stock is held for a specific branch.

- **roles** (company-scoped, via Spatie Laravel Permission)
  - Fields: `id`, `company_id`, `name`, `guard_name`, `created_at`, `updated_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
  - Uniqueness:
    - `company_id`, `name`, `guard_name` are unique together.
  - Examples per company: `Admin`, `Cashier`, `Manager`, `Accountant`.
  - The same role name (e.g. `Admin`) in different companies is a different record.

- **permissions** (from Spatie Laravel Permission)
  - Examples: `view_sales`, `create_sale`, `manage_inventory`, `manage_users`, `view_reports`, `manage_accounting`.
  - Permissions are global by default and are attached to roles; roles themselves are tenant-scoped by `company_id`.

- **users**
  - Fields: `id`, `company_id`, `branch_id`, `name`, `email`, `password`, `is_active`, `last_login_at`, `last_login_ip`, `created_at`, `updated_at`.
  - Foreign keys:
    - `company_id` → `companies.id`
    - `branch_id` → `branches.id` (nullable for system users if needed)

### Relationships

- `Company` has many `Branch` models.
- `Branch` belongs to `Company` and has many `Warehouse` models.
- `Warehouse` belongs to `Branch`.
- `Company` has many `User` models.
- `Branch` has many `User` models.
- `User` belongs to `Company`.
- `User` belongs to a single `Branch` (`users.branch_id`) for branch-level isolation (cashier cannot act in another branch).
- `User` uses Spatie's `HasRoles` trait for many-to-many role/permission assignments via `model_has_roles` and `role_has_permissions`. There is no `users.role_id` any more; roles are only linked through the pivot.

### Tenant Isolation Logic

- `App\Models\User` defines a **global scope** named `company`:
  - For HTTP requests, whenever a user is authenticated, all `User` queries automatically include `where company_id = auth()->user()->company_id`.
  - For console commands (migrations, seeders, tinker), the scope is disabled to allow cross-tenant operations.
- `App\Models\Branch` and `App\Models\Warehouse` also apply company-aware global scopes so that branches and warehouses are automatically filtered to the authenticated user's company.
- This ensures user-, branch-, and warehouse-related queries are tenant-aware without needing to manually add `company_id` filters.
- The same pattern can be applied to future business models (e.g., products, orders) by adding a similar global scope.

### Roles & Permissions Extension

- Roles and permissions are managed using **Spatie Laravel Permission** with a custom `App\Models\Role`:
  - Add new permissions (global):
    - `Permission::create(['name' => 'manage_pos', 'guard_name' => 'web']);`
  - Create company-scoped roles:
    - `$role = \App\Models\Role::create(['company_id' => $company->id, 'name' => 'Manager', 'guard_name' => 'web']);`
  - Attach permissions to roles:
    - `$role->givePermissionTo('manage_pos');`
  - Assign roles to users (pivot only):
    - `$user->assignRole('Manager');`
- Tenant safety rules:
  - Roles are always created with a `company_id`.
  - UI / APIs should only show roles where `roles.company_id = auth()->user()->company_id`.
  - The same role name in two companies produces two separate role rows.

### Seeding Defaults

- `DatabaseSeeder` seeds:
  - A default company: `Default Company` (email: `default@company.test`).
  - Base permissions: `view_sales`, `create_sale`, `manage_inventory`, `manage_users`, `view_reports`, `manage_accounting`.
  - Roles per company: `Admin`, `Cashier`, `Manager`, `Accountant` with appropriate permission sets, all tied to the default company via `roles.company_id`.
  - Super admin user:
    - Email: `admin@company.test`
    - Password: `password`
    - Belongs to the default company and its head-office branch.
    - Has `Admin` role and all permissions.

### API Authentication (for Vue.js Frontend)

- Uses **Laravel Sanctum** personal access tokens and Laravel Breeze backend scaffolding.
- Token expiration:
  - `config/sanctum.php` sets `expiration` to `env('SANCTUM_EXPIRATION', 1440)` minutes (24 hours by default).
  - For POS terminals you should set `SANCTUM_EXPIRATION` to `480` (8 hours), `720` (12 hours), or `1440` (24 hours) based on session policy.

- Endpoints (`routes/api.php`):
  - `POST /api/auth/register`
    - Body: `name`, `email`, `password`, `password_confirmation`, optional `role`, optional `branch_code`.
    - Behavior:
      - Ensures a default company exists (for now, all demo users are created in this company).
      - Resolves the branch by `branch_code` for that company; if not provided, falls back to the first branch (e.g. `Head Office`).
      - Assigns the requested role within that company (or `Cashier` by default) using Spatie's pivot, not a `role_id` column.
      - Returns a personal access token and the user (with `company`, `branch`, and `roles`).
  - `POST /api/auth/login`
    - Body: `email`, `password`, optional `device_name` (defaults to `web`).
    - On successful login:
      - Updates `users.last_login_at` and `users.last_login_ip`.
      - Issues a new Sanctum token respecting the configured expiration.
    - Returns: `{ token, user }` where `user` includes `company`, `branch`, and `roles`.
    - Requires `is_active = true`.
  - `POST /api/auth/logout`
    - Requires `Authorization: Bearer {token}`.
    - Revokes the current access token.

### Example Tinker Commands

Run from the project root:

```php
php artisan tinker
```

Create an additional company:

```php
use App\Models\Company;

$company = Company::create([
    'name' => 'Branch Company',
    'email' => 'branch@company.test',
    'currency' => 'USD',
    'timezone' => 'UTC',
]);
```

Create a new role and permission:

```php
use App\Models\Role;
use Spatie\Permission\Models\Permission;

$perm = Permission::create(['name' => 'view_inventory_valuation', 'guard_name' => 'web']);
$role = Role::create([
    'company_id' => $company->id,
    'name' => 'InventoryAuditor',
    'guard_name' => 'web',
]);
$role->givePermissionTo($perm);
```

Create a user in a specific company and branch:

```php
use App\Models\User;
use App\Models\Branch;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

$branch = Branch::firstOrCreate([
    'company_id' => $company->id,
    'code' => 'HO',
], [
    'name' => 'Head Office',
]);

$role = Role::where('company_id', $company->id)->firstWhere('name', 'Cashier');

$user = User::create([
    'company_id' => $company->id,
    'branch_id' => $branch->id,
    'name' => 'Branch Cashier',
    'email' => 'cashier@branch.test',
    'password' => Hash::make('secret'),
    'is_active' => true,
]);

$user->assignRole($role);
```

### Verifying Tenant Isolation

1. Log in via `POST /api/auth/login` as `admin@company.test` and get a token.
2. Use the token to call any future user-list endpoint (e.g., `/api/users` when implemented).
3. Create users in a second company via tinker.
4. Confirm that queries made through Eloquent in HTTP context only return users, branches, and warehouses for the authenticated user's company because of the global `company` scopes.

---

## How to Test the System

### 1. One-time setup (database)

From the project root (`D:\pos_system`):

```bash
php artisan migrate --seed
```

This creates all tables and seeds the default company, roles, permissions, super admin user, default branch, and default warehouses.

### 2. Start the API server

```bash
php artisan serve
```

The API will be at **http://127.0.0.1:8000**. All examples below use this base URL.

### 3. Test the API endpoints

You can use **Postman**, **Insomnia**, or **curl**. Examples below are for **Command Prompt** (use `^` to continue lines). In **PowerShell**, use backtick `` ` `` instead of `^`, or run the command as one line.

**Login (get a token)**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/login ^
  -H "Content-Type: application/json" ^
  -d "{\"email\":\"admin@company.test\",\"password\":\"password\"}"
```

- **Success:** You get JSON with `token` and `user` (company, branch, roles).
- **Copy the `token`** — you need it for the next requests.

**Get current user (protected route)**

Replace `YOUR_TOKEN` with the token from login:

```bash
curl -X GET http://127.0.0.1:8000/api/user ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -H "Accept: application/json"
```

You should see the logged-in user (and tenant scope applies to any `User` / `Branch` / `Warehouse` queries behind this).

**Logout (revoke token)**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/logout ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -H "Accept: application/json"
```

After this, the same token will no longer work for `/api/user`.

**Register a new user**

```bash
curl -X POST http://127.0.0.1:8000/api/auth/register ^
  -H "Content-Type: application/json" ^
  -d "{\"name\":\"Test Cashier\",\"email\":\"cashier@test.com\",\"password\":\"password\",\"password_confirmation\":\"password\",\"role\":\"Cashier\"}"
```

Optional: add `"branch_code":"HO"` (or another branch code for the same company). Response includes a new `token` and `user` (with `company`, `branch`, `roles`).

### 4. Test with Postman (optional)

1. Create a new request: **POST** `http://127.0.0.1:8000/api/auth/login`.
2. Body → **raw** → **JSON**: `{"email":"admin@company.test","password":"password"}`.
3. Send; copy the `token` from the response.
4. For **GET** `http://127.0.0.1:8000/api/user`, go to **Headers** and add:  
   `Authorization` = `Bearer YOUR_TOKEN`.
5. For **POST** `http://127.0.0.1:8000/api/auth/logout`, use the same header.

### 5. Quick check in Tinker

```bash
php artisan tinker
```

```php
// Super admin exists, is scoped to the default company and branch, and has Admin role
$u = \App\Models\User::where('email', 'admin@company.test')->first();
$u->company->name;   // "Default Company"
$u->branch->code;    // "HO" (Head Office)
$u->getRoleNames(); // ["Admin"]
$u->getAllPermissions()->pluck('name'); // all 6 permissions
```

Exit tinker with `exit`.

