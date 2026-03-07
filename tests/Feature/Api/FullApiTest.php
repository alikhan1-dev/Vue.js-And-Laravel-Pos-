<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Enums\SaleStatus;
use App\Enums\SaleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end API tests: every endpoint from auth through sales, payments, customers,
 * warranty, purchase and suppliers. Uses seeded data (DatabaseSeeder) for consistency.
 */
class FullApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private User $user;
    private int $companyId;
    private int $branchId;
    private int $warehouseId;
    private int $productId;
    private int $accountId;
    private int $paymentMethodId;
    private int $completedSaleId;
    private int $draftOrQuotationSaleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->user = User::withoutGlobalScope('company')->where('email', 'admin@company.test')->first();
        $this->assertNotNull($this->user, 'Seeded admin user must exist');

        $this->companyId = $this->user->company_id;
        $this->branchId = $this->user->branch_id ?? Branch::where('company_id', $this->companyId)->value('id');
        $this->warehouseId = Warehouse::where('branch_id', $this->branchId)->value('id');
        $this->productId = Product::withoutGlobalScope('company')->where('company_id', $this->companyId)->value('id');
        $this->accountId = Account::withoutGlobalScope('company')->where('company_id', $this->companyId)->where('code', '1000')->value('id');
        $this->paymentMethodId = PaymentMethod::withoutGlobalScope('company')->where('company_id', $this->companyId)->value('id');
        $this->completedSaleId = Sale::withoutGlobalScope('company')->where('company_id', $this->companyId)->where('status', SaleStatus::Completed)->value('id');
        $this->draftOrQuotationSaleId = Sale::withoutGlobalScope('company')->where('company_id', $this->companyId)->whereIn('status', [SaleStatus::Draft, SaleStatus::Pending])->value('id');

        $login = $this->postJson('/api/auth/login', [
            'email' => 'admin@company.test',
            'password' => 'password',
        ]);
        $login->assertOk();
        $this->token = $login->json('token');
        $this->assertNotEmpty($this->token);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    // ---- Auth ----
    public function test_auth_login_returns_token(): void
    {
        $r = $this->postJson('/api/auth/login', ['email' => 'admin@company.test', 'password' => 'password']);
        $r->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_auth_login_fails_with_wrong_password(): void
    {
        $r = $this->postJson('/api/auth/login', ['email' => 'admin@company.test', 'password' => 'wrong']);
        $r->assertStatus(422);
    }

    public function test_user_endpoint_returns_authenticated_user(): void
    {
        $r = $this->getJson('/api/user', $this->auth());
        $r->assertOk()->assertJsonPath('email', 'admin@company.test');
    }

    public function test_logout_succeeds(): void
    {
        $r = $this->postJson('/api/auth/logout', [], $this->auth());
        $r->assertOk();
    }

    // ---- Branches & Warehouses ----
    public function test_branches_list(): void
    {
        $r = $this->getJson('/api/branches', $this->auth());
        $r->assertOk();
        $this->assertIsArray($r->json());
    }

    public function test_warehouses_list(): void
    {
        $r = $this->getJson('/api/warehouses', $this->auth());
        $r->assertOk();
        $this->assertIsArray($r->json());
    }

    // ---- Products ----
    public function test_products_index(): void
    {
        $r = $this->getJson('/api/products', $this->auth());
        $r->assertOk();
    }

    public function test_products_store(): void
    {
        $r = $this->postJson('/api/products', [
            'name' => 'Test Product',
            'sku' => 'TEST-API-' . time(),
            'unit_price' => 10,
            'category_id' => \App\Models\Category::where('company_id', $this->companyId)->value('id'),
            'brand_id' => \App\Models\Brand::where('company_id', $this->companyId)->value('id'),
            'unit_id' => \App\Models\Unit::where('company_id', $this->companyId)->value('id'),
            'type' => 'simple',
            'track_stock' => true,
        ], $this->auth());
        $r->assertStatus(201);
    }

    public function test_products_stock(): void
    {
        if (! $this->productId) {
            $this->markTestSkipped('No product in DB');
        }
        $r = $this->getJson("/api/products/{$this->productId}/stock", $this->auth());
        $r->assertOk();
    }

    // ---- Categories, Brands, Units ----
    public function test_categories_index(): void
    {
        $r = $this->getJson('/api/categories', $this->auth());
        $r->assertOk();
    }

    public function test_categories_store(): void
    {
        $r = $this->postJson('/api/categories', ['name' => 'Test Category', 'is_active' => true], $this->auth());
        $r->assertStatus(201);
    }

    public function test_brands_index(): void
    {
        $r = $this->getJson('/api/brands', $this->auth());
        $r->assertOk();
    }

    public function test_brands_store(): void
    {
        $r = $this->postJson('/api/brands', ['name' => 'Test Brand', 'is_active' => true], $this->auth());
        $r->assertStatus(201);
    }

    public function test_units_index(): void
    {
        $r = $this->getJson('/api/units', $this->auth());
        $r->assertOk();
    }

    public function test_units_store(): void
    {
        $r = $this->postJson('/api/units', ['name' => 'Box', 'short_name' => 'box', 'is_active' => true], $this->auth());
        $r->assertStatus(201);
    }

    // ---- Stock movements & Transfers ----
    public function test_stock_movements_index(): void
    {
        $r = $this->getJson('/api/stock-movements', $this->auth());
        $r->assertOk();
    }

    public function test_stock_movements_store(): void
    {
        $r = $this->postJson('/api/stock-movements', [
            'product_id' => $this->productId,
            'warehouse_id' => $this->warehouseId,
            'quantity' => 1,
            'type' => 'adjustment_in',
            'reference_type' => 'Adjustment',
        ], $this->auth());
        $r->assertStatus(201);
    }

    public function test_warehouse_stock_index(): void
    {
        $r = $this->getJson('/api/warehouse-stock', $this->auth());
        $r->assertOk();
    }

    public function test_transfers_store(): void
    {
        $wh2 = Warehouse::where('branch_id', $this->branchId)->where('id', '!=', $this->warehouseId)->value('id');
        if (! $wh2 || ! $this->productId) {
            $this->markTestSkipped('Need second warehouse and product');
        }
        $r = $this->postJson('/api/transfers', [
            'from_warehouse_id' => $this->warehouseId,
            'to_warehouse_id' => $wh2,
            'product_id' => $this->productId,
            'quantity' => 1,
        ], $this->auth());
        $r->assertStatus(201);
    }

    // ---- Sales ----
    public function test_sales_index(): void
    {
        $r = $this->getJson('/api/sales', $this->auth());
        $r->assertOk();
    }

    public function test_sales_store(): void
    {
        $r = $this->postJson('/api/sales', [
            'branch_id' => $this->branchId,
            'warehouse_id' => $this->warehouseId,
            'type' => 'sale',
            'status' => 'draft',
            'lines' => [
                ['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 10],
            ],
        ], $this->auth());
        $r->assertStatus(201);
        $r->assertJsonPath('status', 'draft');
    }

    public function test_sales_show(): void
    {
        $saleId = Sale::withoutGlobalScope('company')->where('company_id', $this->companyId)->value('id');
        if (! $saleId) {
            $this->markTestSkipped('No sale in DB');
        }
        $r = $this->getJson("/api/sales/{$saleId}", $this->auth());
        $r->assertOk();
    }

    public function test_sales_complete(): void
    {
        $draftId = Sale::withoutGlobalScope('company')->where('company_id', $this->companyId)->where('status', SaleStatus::Draft)->value('id');
        if (! $draftId) {
            $this->markTestSkipped('No draft sale - create one first or use existing');
            return;
        }
        $r = $this->postJson("/api/sales/{$draftId}/complete", [], $this->auth());
        $r->assertOk();
    }

    public function test_sales_convert(): void
    {
        $quotationId = Sale::withoutGlobalScope('company')->where('company_id', $this->companyId)->where('type', SaleType::Quotation)->where('status', SaleStatus::Pending)->value('id');
        if (! $quotationId) {
            $this->markTestSkipped('No quotation in DB');
            return;
        }
        $r = $this->postJson("/api/sales/{$quotationId}/convert", [], $this->auth());
        $r->assertOk();
    }

    public function test_sales_cancel(): void
    {
        $draftId = Sale::withoutGlobalScope('company')->where('company_id', $this->companyId)->where('status', SaleStatus::Draft)->value('id');
        if (! $draftId) {
            $this->markTestSkipped('No draft sale');
            return;
        }
        $r = $this->postJson("/api/sales/{$draftId}/cancel", [], $this->auth());
        $r->assertOk();
    }

    public function test_sales_return(): void
    {
        if (! $this->completedSaleId) {
            $this->markTestSkipped('No completed sale for return');
            return;
        }
        $sale = Sale::withoutGlobalScope('company')->find($this->completedSaleId);
        $line = $sale->lines()->first();
        if (! $line) {
            $this->markTestSkipped('Completed sale has no lines');
            return;
        }
        $r = $this->postJson("/api/sales/{$this->completedSaleId}/return", [
            'lines' => [['product_id' => $line->product_id, 'quantity' => 1, 'unit_price' => $line->unit_price]],
            'return_reason_code' => 'customer_return',
        ], $this->auth());
        $r->assertStatus(201);
    }

    public function test_sales_stock_check(): void
    {
        if (! $this->completedSaleId) {
            $this->markTestSkipped('No sale in DB');
            return;
        }
        $r = $this->getJson("/api/sales/{$this->completedSaleId}/stock-check", $this->auth());
        $r->assertOk();
    }

    // ---- Sale adjustments ----
    public function test_sale_adjustments_index(): void
    {
        $r = $this->getJson('/api/sale-adjustments', $this->auth());
        $r->assertOk();
    }

    public function test_sale_adjustments_store(): void
    {
        if (! $this->completedSaleId) {
            $this->markTestSkipped('No completed sale for adjustment');
            return;
        }
        $r = $this->postJson("/api/sales/{$this->completedSaleId}/adjustments", [
            'type' => 'price_correction',
            'amount' => 10,
            'reason' => 'Test adjustment',
        ], $this->auth());
        $r->assertStatus(201);
    }

    // ---- Customers ----
    public function test_customers_index(): void
    {
        $r = $this->getJson('/api/customers', $this->auth());
        $r->assertOk();
    }

    public function test_customers_store(): void
    {
        $r = $this->postJson('/api/customers', [
            'name' => 'Test Customer',
            'email' => 'testcustomer' . time() . '@test.com',
            'status' => 'active',
        ], $this->auth());
        $r->assertStatus(201);
    }

    public function test_customers_show_and_update(): void
    {
        $custId = \App\Models\Customer::withoutGlobalScope('company')->where('company_id', $this->companyId)->value('id');
        if (! $custId) {
            $customer = \App\Models\Customer::withoutGlobalScope('company')->create([
                'company_id' => $this->companyId,
                'name' => 'Show Test Customer',
                'status' => 'active',
            ]);
            $custId = $customer->id;
        }
        $r = $this->getJson("/api/customers/{$custId}", $this->auth());
        $r->assertOk();
        $r2 = $this->putJson("/api/customers/{$custId}", ['name' => 'Updated Name', 'status' => 'active'], $this->auth());
        $r2->assertOk();
    }

    public function test_customers_warranties(): void
    {
        $custId = \App\Models\Customer::withoutGlobalScope('company')->where('company_id', $this->companyId)->value('id');
        if (! $custId) {
            $this->markTestSkipped('No customer');
            return;
        }
        $r = $this->getJson("/api/customers/{$custId}/warranties", $this->auth());
        $r->assertOk();
    }

    // ---- Warranty ----
    public function test_warranty_lookup(): void
    {
        $r = $this->getJson('/api/warranty/lookup', $this->auth());
        $r->assertOk();
    }

    public function test_warranty_claims_index(): void
    {
        $r = $this->getJson('/api/warranty-claims', $this->auth());
        $r->assertOk();
    }

    public function test_warranty_claims_store(): void
    {
        $regId = \App\Models\WarrantyRegistration::withoutGlobalScope('company')->where('company_id', $this->companyId)->value('id');
        if (! $regId) {
            $this->markTestSkipped('No warranty registration in DB');
            return;
        }
        $r = $this->postJson('/api/warranty-claims', [
            'warranty_registration_id' => $regId,
            'claim_type' => 'repair',
            'description' => 'Test claim',
        ], $this->auth());
        $r->assertStatus(201);
    }

    // ---- Accounts & Payment methods ----
    public function test_accounts_list(): void
    {
        $r = $this->getJson('/api/accounts', $this->auth());
        $r->assertOk();
    }

    public function test_payment_methods_list(): void
    {
        $r = $this->getJson('/api/payment-methods', $this->auth());
        $r->assertOk();
    }

    // ---- Payments ----
    public function test_payments_index(): void
    {
        $r = $this->getJson('/api/payments', $this->auth());
        $r->assertOk();
    }

    public function test_payments_store(): void
    {
        $saleId = Sale::withoutGlobalScope('company')->where('company_id', $this->companyId)->where('status', SaleStatus::Completed)->whereColumn('paid_amount', '<', 'grand_total')->orWhereNull('paid_amount')->value('id');
        if (! $saleId || ! $this->accountId || ! $this->paymentMethodId) {
            $this->markTestSkipped('Need unpaid sale, account and payment method');
            return;
        }
        $sale = Sale::withoutGlobalScope('company')->find($saleId);
        $due = (float) ($sale->due_amount ?? $sale->grand_total - $sale->paid_amount);
        if ($due < 0.01) {
            $this->markTestSkipped('No sale with remaining due amount');
            return;
        }
        $r = $this->postJson('/api/payments', [
            'sale_id' => $saleId,
            'branch_id' => $this->branchId,
            'lines' => [
                ['payment_method_id' => $this->paymentMethodId, 'account_id' => $this->accountId, 'amount' => min(10, $due)],
            ],
        ], $this->auth());
        $r->assertStatus(201);
    }

    public function test_payments_show(): void
    {
        $payId = \App\Models\Payment::withoutGlobalScope('company')->where('company_id', $this->companyId)->value('id');
        if (! $payId) {
            $this->markTestSkipped('No payment in DB');
            return;
        }
        $r = $this->getJson("/api/payments/{$payId}", $this->auth());
        $r->assertOk();
    }

    public function test_payments_refund(): void
    {
        $payment = \App\Models\Payment::withoutGlobalScope('company')->where('company_id', $this->companyId)->where('amount', '>', 0)->first();
        if (! $payment || ! $this->accountId) {
            $this->markTestSkipped('No positive payment to refund');
            return;
        }
        $r = $this->postJson("/api/payments/{$payment->id}/refund", [
            'amount' => 1,
            'account_id' => $this->accountId,
        ], $this->auth());
        $r->assertStatus(201);
    }

    // ---- Suppliers & Purchases ----
    public function test_suppliers_index(): void
    {
        $r = $this->getJson('/api/suppliers', $this->auth());
        $r->assertOk();
    }

    public function test_suppliers_store(): void
    {
        $r = $this->postJson('/api/suppliers', [
            'name' => 'Test Supplier',
            'email' => 'supplier' . time() . '@test.com',
        ], $this->auth());
        $r->assertStatus(201);
    }

    public function test_purchases_index(): void
    {
        $r = $this->getJson('/api/purchases', $this->auth());
        $r->assertOk();
    }

    public function test_purchases_store(): void
    {
        $supplierId = \App\Models\Supplier::withoutGlobalScope('company')->where('company_id', $this->companyId)->value('id');
        if (! $supplierId) {
            $supplier = \App\Models\Supplier::withoutGlobalScope('company')->create([
                'company_id' => $this->companyId,
                'name' => 'Purchase Test Supplier',
            ]);
            $supplierId = $supplier->id;
        }
        $r = $this->postJson('/api/purchases', [
            'supplier_id' => $supplierId,
            'branch_id' => $this->branchId,
            'warehouse_id' => $this->warehouseId,
            'lines' => [['product_id' => $this->productId, 'quantity' => 2, 'unit_cost' => 5]],
        ], $this->auth());
        $r->assertStatus(201);
    }

    public function test_purchases_show(): void
    {
        $purchaseId = \App\Models\Purchase::withoutGlobalScope('company')->where('company_id', $this->companyId)->value('id');
        if (! $purchaseId) {
            $this->markTestSkipped('No purchase in DB');
            return;
        }
        $r = $this->getJson("/api/purchases/{$purchaseId}", $this->auth());
        $r->assertOk();
    }

    public function test_supplier_invoices_index(): void
    {
        $r = $this->getJson('/api/supplier-invoices', $this->auth());
        $r->assertOk();
    }

    public function test_supplier_payments_store(): void
    {
        $supplierId = \App\Models\Supplier::withoutGlobalScope('company')->where('company_id', $this->companyId)->value('id');
        $r = $this->postJson('/api/supplier-payments', [
            'supplier_id' => $supplierId,
            'amount' => 50,
            'account_id' => $this->accountId,
        ], $this->auth());
        $r->assertStatus(201);
    }
}
