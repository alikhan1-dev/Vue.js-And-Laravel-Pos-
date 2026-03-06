# Postman Testing Guide – POS API

Test every API endpoint in order. Base URL: **http://127.0.0.1:8000** (run `php artisan serve` first).

---

## 1. Setup once

1. **Start the server**
   ```bash
   php artisan serve
   ```
2. In Postman, create an **Environment** (optional):
   - Variable: `base_url` = `http://127.0.1:8000`
   - Variable: `token` = (leave empty; set after login)
3. For protected requests: **Authorization** tab → Type: **Bearer Token** → Token: `{{token}}` (or paste the token manually after login).

---

## 2. Auth (no token needed for login/register)

### 2.1 Login – get your token

| Field   | Value |
|--------|--------|
| Method | **POST** |
| URL    | `http://127.0.0.1:8000/api/auth/login` |
| Auth   | No auth |

**Body** → **raw** → **JSON:**

```json
{
  "email": "admin@company.test",
  "password": "password"
}
```

**Send.**  
- **200:** Copy the `token` from the response. Use it in **Authorization → Bearer Token** for all requests below.  
- **422:** Wrong email/password or user inactive.

---

### 2.2 Get current user (protected)

| Field   | Value |
|--------|--------|
| Method | **GET** |
| URL    | `http://127.0.0.1:8000/api/user` |
| Auth   | **Bearer Token** → paste the token from 2.1 |

**Send.** You should see the logged-in user (id, name, email, company, roles).

---

### 2.3 Register a new user (optional)

| Field   | Value |
|--------|--------|
| Method | **POST** |
| URL    | `http://127.0.0.1:8000/api/auth/register` |
| Auth   | No auth |

**Body** → **raw** → **JSON:**

```json
{
  "name": "New Cashier",
  "email": "cashier@test.com",
  "password": "password",
  "password_confirmation": "password",
  "role": "Cashier"
}
```

**Send.** **201** returns a new `token` and `user`. You can use this token to test as another user.

---

### 2.4 Logout (protected)

| Field   | Value |
|--------|--------|
| Method | **POST** |
| URL    | `http://127.0.0.1:8000/api/auth/logout` |
| Auth   | **Bearer Token** |

**Send.** **200** with a message. After this, the same token will no longer work for `/api/user`.

---

## 3. Branches & Warehouses (all protected)

Use the **same Bearer token** from login for every request in this section.

### 3.1 List branches

| Field   | Value |
|--------|--------|
| Method | **GET** |
| URL    | `http://127.0.0.1:8000/api/branches` |
| Auth   | Bearer Token |

**Send.** You should see branches for your company (e.g. **Head Office**, code **HO**).

---

### 3.2 List warehouses

| Field   | Value |
|--------|--------|
| Method | **GET** |
| URL    | `http://127.0.0.1:8000/api/warehouses` |
| Auth   | Bearer Token |

**Send.** You should see warehouses (e.g. **Main Warehouse**, code **MAIN**). Note a **warehouse `id`** (e.g. `1`) for stock and movements later.

---

## 4. Products (all protected)

### 4.1 List products

| Field   | Value |
|--------|--------|
| Method | **GET** |
| URL    | `http://127.0.0.1:8000/api/products` |
| Auth   | Bearer Token |

Optional query: `?active_only=1` to only list active products.

**Send.** You should see at least the seeded **Sample Product** (SKU `SAMPLE-001`). Note a **product `id`** (e.g. `1`).

---

### 4.2 Create a product

| Field   | Value |
|--------|--------|
| Method | **POST** |
| URL    | `http://127.0.0.1:8000/api/products` |
| Auth   | Bearer Token |

**Body** → **raw** → **JSON:**

```json
{
  "name": "Wireless Mouse",
  "sku": "MOUSE-001",
  "barcode": "9876543210987",
  "description": "USB wireless mouse",
  "unit_price": 25.50,
  "uom": "piece",
  "is_active": true
}
```

**Send.** **201** returns the created product (with `id`). Use this `id` for stock and movements.  
- **422:** Validation error (e.g. SKU already exists for your company, or `uom` not in `config/pos.allowed_uom`).

---

### 4.3 Get stock per warehouse for a product

| Field   | Value |
|--------|--------|
| Method | **GET** |
| URL    | `http://127.0.0.1:8000/api/products/1/stock` |
| Auth   | Bearer Token |

Replace `1` with a real product id from 4.1 or 4.2.

**Send.** Response includes `product_id`, `product_name`, `sku`, `uom`, and `stock_by_warehouse` (array of `warehouse_id`, `warehouse_name`, `warehouse_code`, `quantity`). Quantities come from the **stock cache**.

---

## 5. Stock movements (all protected)

### 5.1 List stock movements

| Field   | Value |
|--------|--------|
| Method | **GET** |
| URL    | `http://127.0.0.1:8000/api/stock-movements` |
| Auth   | Bearer Token |

Optional query params (combine as needed):

- `product_id=1` – movements for product 1  
- `warehouse_id=1` – movements in warehouse 1  
- `type=purchase_in` – only that type  
- `per_page=10` – pagination (max 100)

Example: `http://127.0.0.1:8000/api/stock-movements?product_id=1&per_page=5`

**Send.** You should see at least the seeded `purchase_in` of 50 units. Response is paginated (e.g. `data`, `current_page`, `per_page`).

---

### 5.2 Record a stock movement

| Field   | Value |
|--------|--------|
| Method | **POST** |
| URL    | `http://127.0.0.1:8000/api/stock-movements` |
| Auth   | Bearer Token |

**Body** → **raw** → **JSON:**

```json
{
  "product_id": 1,
  "warehouse_id": 1,
  "quantity": 10,
  "type": "sale_out",
  "reference_type": "SaleInvoice",
  "reference_id": 101
}
```

- **product_id** – from GET /api/products (must belong to your company).  
- **warehouse_id** – from GET /api/warehouses (must belong to your company).  
- **type** – one of: `purchase_in`, `sale_out`, `transfer_in`, `transfer_out`, `adjustment_in`, `adjustment_out`.  
- **reference_type** and **reference_id** – optional.

**Send.** **201** returns the created movement. The **stock cache** is updated automatically. Call **GET /api/products/1/stock** again to see the new quantity (e.g. 50 − 10 = 40 if you used product 1 and warehouse 1).  
- **403:** Product or warehouse not found or not in your company.  
- **422:** Validation error (e.g. invalid `type`, quantity &lt; 0.01).

---

## 6. Sales & quotations (Step 4)

Use the same Bearer token. Ensure you have at least one product and one warehouse (from sections 3–4).

### 6.1 List sales

| Field | Value |
|--------|--------|
| Method | **GET** |
| URL | `http://127.0.0.1:8000/api/sales` |
| Auth | Bearer Token |

Optional: `?type=sale`, `?type=quotation`, `?type=return`, `?branch_id=1`, `?status=completed`, `?date_from=2026-01-01`, `?date_to=2026-12-31`, `?per_page=10`.

**Send.** You should see seeded sale, quotation, and return. Note a **sale id** (e.g. `1`) and a **quotation id** (e.g. `2`).

---

### 6.2 Create a sale

| Field | Value |
|--------|--------|
| Method | **POST** |
| URL | `http://127.0.0.1:8000/api/sales` |
| Auth | Bearer Token |

**Body** → **raw** → **JSON:**

```json
{
  "branch_id": 1,
  "warehouse_id": 1,
  "type": "sale",
  "lines": [
    {
      "product_id": 1,
      "quantity": 2,
      "unit_price": 99.99,
      "discount": 0
    }
  ]
}
```

**Send.** **201** returns the sale with lines and linked stock movements. Stock is deducted. **422** if insufficient stock or invalid branch/warehouse.

---

### 6.3 Create a quotation

Same as 6.2 but `"type": "quotation"`. No stock is deducted; lines have no `stock_movement_id`.

---

### 6.4 Get sale detail

| Field | Value |
|--------|--------|
| Method | **GET** |
| URL | `http://127.0.0.1:8000/api/sales/1` |
| Auth | Bearer Token |

Replace `1` with a sale id. Response includes lines, product, and stock movement info.

---

### 6.5 Convert quotation to sale

| Field | Value |
|--------|--------|
| Method | **POST** |
| URL | `http://127.0.0.1:8000/api/sales/2/convert` |
| Auth | Bearer Token |

Replace `2` with a **quotation** id. **200** creates stock movements and sets type to `sale`, status to `completed`. **422** if insufficient stock.

---

### 6.6 Stock check for a sale

| Field | Value |
|--------|--------|
| Method | **GET** |
| URL | `http://127.0.0.1:8000/api/sales/1/stock-check` |
| Auth | Bearer Token |

Returns for each line: `quantity_in_sale`, `current_stock`, `sufficient`.

---

### 6.7 Create return for a sale

| Field | Value |
|--------|--------|
| Method | **POST** |
| URL | `http://127.0.0.1:8000/api/sales/1/return` |
| Auth | Bearer Token |

Replace `1` with a **completed sale** id. Body can be empty (returns all lines) or send `lines` to return only specific products/quantities. **201** creates a new sale with `type=return` and `purchase_in` movements. Stock increases.

---

## 7. Payments & accounting (Step 5)

### 7.1 List accounts

| Field | Value |
|--------|--------|
| Method | **GET** |
| URL | `http://127.0.0.1:8000/api/accounts` |
| Auth | Bearer Token |

Returns active accounts (id, code, name, type, balance) for payment dropdowns.

### 7.2 List payments

| Field | Value |
|--------|--------|
| Method | **GET** |
| URL | `http://127.0.0.1:8000/api/payments` |
| Auth | Bearer Token |

Query params: `sale_id`, `status`, `branch_id`, `date_from`, `date_to`, `method`, `per_page`.

### 7.3 Create payment (single or multiple methods)

| Field | Value |
|--------|--------|
| Method | **POST** |
| URL | `http://127.0.0.1:8000/api/payments` |
| Auth | Bearer Token |

**Body** → **raw** → **JSON:**

```json
{
  "sale_id": 1,
  "branch_id": 1,
  "lines": [
    { "method": "cash", "amount": 499.95, "account_id": 1 }
  ]
}
```

For multiple methods: add more items to `lines` (e.g. `"method": "card", "amount": 60, "account_id": 2, "reference": "CARD-123"`). **201** returns the payment with lines and journal entries. **422** if overpay or invalid account.

### 7.4 Get payment detail

| Field | Value |
|--------|--------|
| Method | **GET** |
| URL | `http://127.0.0.1:8000/api/payments/1` |
| Auth | Bearer Token |

Returns payment with lines, accounts, and journal entries.

### 7.5 Refund a payment

| Field | Value |
|--------|--------|
| Method | **POST** |
| URL | `http://127.0.0.1:8000/api/payments/1/refund` |
| Auth | Bearer Token |

**Body** → **raw** → **JSON:** `{ "amount": 50, "account_id": 1 }` (account = Cash/Bank to refund from). **201** creates a refund payment and reverses ledger entries.

---

## 8. Quick checklist

| # | Method | Endpoint | Auth | Purpose |
|---|--------|----------|------|--------|
| 1 | POST | `/api/auth/login` | No | Get token |
| 2 | GET | `/api/user` | Bearer | Current user |
| 3 | GET | `/api/branches` | Bearer | List branches |
| 4 | GET | `/api/warehouses` | Bearer | List warehouses |
| 5 | GET | `/api/products` | Bearer | List products |
| 6 | POST | `/api/products` | Bearer | Create product |
| 7 | GET | `/api/products/{id}/stock` | Bearer | Stock by warehouse (cached) |
| 8 | GET | `/api/stock-movements` | Bearer | List movements (with optional filters) |
| 9 | POST | `/api/stock-movements` | Bearer | Record movement |
| 10 | GET | `/api/sales` | Bearer | List sales (filter by type, branch, status, dates) |
| 11 | POST | `/api/sales` | Bearer | Create sale or quotation |
| 12 | GET | `/api/sales/{id}` | Bearer | Sale detail with lines & movements |
| 13 | POST | `/api/sales/{id}/convert` | Bearer | Convert quotation → sale |
| 14 | GET | `/api/sales/{id}/stock-check` | Bearer | Stock check per line |
| 15 | POST | `/api/sales/{id}/return` | Bearer | Create return |
| 16 | GET | `/api/accounts` | Bearer | List accounts |
| 17 | GET | `/api/payments` | Bearer | List payments |
| 18 | POST | `/api/payments` | Bearer | Create payment (with lines) |
| 19 | GET | `/api/payments/{id}` | Bearer | Payment detail + journal entries |
| 20 | POST | `/api/payments/{id}/refund` | Bearer | Refund payment |
| 21 | POST | `/api/auth/logout` | Bearer | Invalidate token |

---

## 9. Common issues

- **401 Unauthenticated** – Missing or wrong token. Login again and set **Authorization → Bearer Token**.
- **403** – Resource (product/warehouse) not in your company. Use ids from your own GET products/warehouses.
- **422 Validation** – Check required fields and allowed values (e.g. `type`, `uom` from config).
- **404** – Wrong URL or id (e.g. `/api/products/999/stock` with no product 999).

Using this order (login → user → branches → warehouses → products → stock → movements) you can test each part of the system in Postman.
