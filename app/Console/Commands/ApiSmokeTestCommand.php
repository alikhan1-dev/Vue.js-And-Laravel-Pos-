<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarrantyRegistration;
use App\Enums\SaleStatus;
use App\Enums\SaleType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Smoke-test all API endpoints against a running server (e.g. php artisan serve).
 * Usage: php artisan api:smoke-test [--base-url=http://127.0.0.1:8000]
 * Run from project root; server must be running. Uses seeded admin user to get token.
 */
class ApiSmokeTestCommand extends Command
{
    protected $signature = 'api:smoke-test
                            {--base-url=http://127.0.0.1:8000 : Base URL of the API server}
                            {--show-errors : Show response body on failure}';

    protected $description = 'Smoke-test all API endpoints (server must be running)';

    private string $baseUrl;
    private ?string $token = null;
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;

    public function handle(): int
    {
        $this->baseUrl = rtrim($this->option('base-url'), '/');

        $this->info('API Smoke Test — base URL: ' . $this->baseUrl);
        $this->newLine();

        if (! $this->login()) {
            $this->error('Login failed. Run: php artisan migrate --seed');
            return 1;
        }

        $tests = [
            'GET /api/user' => fn () => $this->get('/api/user'),
            'GET /api/branches' => fn () => $this->get('/api/branches'),
            'GET /api/warehouses' => fn () => $this->get('/api/warehouses'),
            'GET /api/products' => fn () => $this->get('/api/products'),
            'GET /api/categories' => fn () => $this->get('/api/categories'),
            'GET /api/brands' => fn () => $this->get('/api/brands'),
            'GET /api/units' => fn () => $this->get('/api/units'),
            'GET /api/stock-movements' => fn () => $this->get('/api/stock-movements?per_page=5'),
            'GET /api/warehouse-stock' => fn () => $this->get('/api/warehouse-stock'),
            'GET /api/sales' => fn () => $this->get('/api/sales'),
            'GET /api/sale-adjustments' => fn () => $this->get('/api/sale-adjustments'),
            'GET /api/customers' => fn () => $this->get('/api/customers'),
            'GET /api/warranty/lookup' => function () {
                $reg = WarrantyRegistration::withoutGlobalScope('company')->where('company_id', $this->getCompanyId())->first();
                if (! $reg) {
                    return null;
                }
                return $this->get("/api/warranty/lookup?sale_id={$reg->sale_id}");
            },
            'GET /api/warranty-claims' => fn () => $this->get('/api/warranty-claims'),
            'GET /api/accounts' => fn () => $this->get('/api/accounts'),
            'GET /api/payment-methods' => fn () => $this->get('/api/payment-methods'),
            'GET /api/payments' => fn () => $this->get('/api/payments'),
            'GET /api/suppliers' => fn () => $this->get('/api/suppliers'),
            'GET /api/purchases' => fn () => $this->get('/api/purchases'),
            'GET /api/supplier-invoices' => fn () => $this->get('/api/supplier-invoices'),
        ];

        foreach ($tests as $name => $callable) {
            $this->runTest($name, $callable);
        }

        // POST /api/products
        $catId = \App\Models\Category::where('company_id', $this->getCompanyId())->value('id');
        $brandId = \App\Models\Brand::where('company_id', $this->getCompanyId())->value('id');
        $unitId = \App\Models\Unit::where('company_id', $this->getCompanyId())->value('id');
        if ($catId && $brandId && $unitId) {
            $this->runTest('POST /api/products', fn () => $this->post('/api/products', [
                'name' => 'Smoke Test Product',
                'sku' => 'SMOKE-' . time(),
                'unit_price' => 10,
                'category_id' => $catId,
                'brand_id' => $brandId,
                'unit_id' => $unitId,
                'type' => 'simple',
                'track_stock' => true,
            ]));
        } else {
            $this->skip('POST /api/products', 'Missing category/brand/unit');
        }

        $branchId = $this->getBranchId();
        $warehouseId = $this->getWarehouseId();
        $productId = Product::withoutGlobalScope('company')->where('company_id', $this->getCompanyId())->value('id');

        if ($productId) {
            $this->runTest('GET /api/products/{id}/stock', fn () => $this->get("/api/products/{$productId}/stock"));
        }

        $saleId = Sale::withoutGlobalScope('company')->where('company_id', $this->getCompanyId())->value('id');
        if ($saleId) {
            $this->runTest('GET /api/sales/{id}', fn () => $this->get("/api/sales/{$saleId}"));
            $this->runTest('GET /api/sales/{id}/stock-check', fn () => $this->get("/api/sales/{$saleId}/stock-check"));
        }

        if ($branchId && $warehouseId && $productId) {
            $this->runTest('POST /api/sales', fn () => $this->post('/api/sales', [
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'type' => 'sale',
                'status' => 'draft',
                'lines' => [['product_id' => $productId, 'quantity' => 1, 'unit_price' => 10]],
            ]));
        }

        $completedSaleId = Sale::withoutGlobalScope('company')->where('company_id', $this->getCompanyId())->where('status', SaleStatus::Completed)->value('id');
        if ($completedSaleId) {
            $this->runTest('POST /api/sales/{id}/adjustments', function () use ($completedSaleId) {
                $r = $this->post("/api/sales/{$completedSaleId}/adjustments", [
                    'type' => 'price_correction',
                    'amount' => 5,
                    'reason' => 'Smoke test',
                ]);
                return $r;
            });
        }

        $customerId = Customer::withoutGlobalScope('company')->where('company_id', $this->getCompanyId())->value('id');
        if (! $customerId) {
            $res = $this->post('/api/customers', ['name' => 'Smoke Customer', 'email' => 'smoke@test.com', 'status' => 'active']);
            if ($res->successful()) {
                $customerId = $res->json('id');
            }
        }
        if ($customerId) {
            $this->runTest('GET /api/customers/{id}', fn () => $this->get("/api/customers/{$customerId}"));
            $this->runTest('GET /api/customers/{id}/warranties', fn () => $this->get("/api/customers/{$customerId}/warranties"));
        }

        $accountId = Account::withoutGlobalScope('company')->where('company_id', $this->getCompanyId())->where('code', '1000')->value('id');
        $paymentMethodId = PaymentMethod::withoutGlobalScope('company')->where('company_id', $this->getCompanyId())->value('id');
        $unpaidSale = Sale::withoutGlobalScope('company')->where('company_id', $this->getCompanyId())
            ->where('status', SaleStatus::Completed)
            ->where(function ($q) {
                $q->whereColumn('paid_amount', '<', 'grand_total')->orWhereNull('paid_amount');
            })->first();
        if ($unpaidSale && $accountId && $paymentMethodId) {
            $due = (float) ($unpaidSale->due_amount ?? $unpaidSale->grand_total - $unpaidSale->paid_amount);
            if ($due >= 0.01) {
                $this->runTest('POST /api/payments', fn () => $this->post('/api/payments', [
                    'sale_id' => $unpaidSale->id,
                    'branch_id' => $branchId,
                    'lines' => [
                        ['payment_method_id' => $paymentMethodId, 'account_id' => $accountId, 'amount' => min(5, $due)],
                    ],
                ]));
            }
        }

        $paymentId = Payment::withoutGlobalScope('company')->where('company_id', $this->getCompanyId())->value('id');
        if ($paymentId && $accountId) {
            $this->runTest('GET /api/payments/{id}', fn () => $this->get("/api/payments/{$paymentId}"));
        }

        $supplierId = Supplier::withoutGlobalScope('company')->where('company_id', $this->getCompanyId())->value('id');
        if (! $supplierId) {
            $res = $this->post('/api/suppliers', ['name' => 'Smoke Supplier', 'email' => 'sup@test.com']);
            if ($res->successful()) {
                $supplierId = $res->json('id');
            }
        }
        if ($supplierId && $branchId && $warehouseId && $productId) {
            $this->runTest('POST /api/purchases', fn () => $this->post('/api/purchases', [
                'supplier_id' => $supplierId,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'lines' => [['product_id' => $productId, 'quantity' => 1, 'unit_cost' => 5]],
            ]));
        }

        $purchaseId = Purchase::withoutGlobalScope('company')->where('company_id', $this->getCompanyId())->value('id');
        if ($purchaseId) {
            $this->runTest('GET /api/purchases/{id}', fn () => $this->get("/api/purchases/{$purchaseId}"));
        }

        if ($supplierId && $accountId) {
            $this->runTest('POST /api/supplier-payments', fn () => $this->post('/api/supplier-payments', [
                'supplier_id' => $supplierId,
                'amount' => 10,
                'account_id' => $accountId,
            ]));
        }

        $regId = WarrantyRegistration::withoutGlobalScope('company')->where('company_id', $this->getCompanyId())->value('id');
        if ($regId) {
            $this->runTest('POST /api/warranty-claims', fn () => $this->post('/api/warranty-claims', [
                'warranty_registration_id' => $regId,
                'claim_type' => 'repair',
                'description' => 'Smoke test claim',
            ]));
        }

        $this->newLine();
        $this->info("Done. Passed: {$this->passed}, Failed: {$this->failed}, Skipped: {$this->skipped}");
        return $this->failed > 0 ? 1 : 0;
    }

    private function getCompanyId(): int
    {
        $user = User::withoutGlobalScope('company')->where('email', 'admin@company.test')->first();

        return $user ? (int) $user->company_id : 0;
    }

    private function getBranchId(): ?int
    {
        $user = User::withoutGlobalScope('company')->where('email', 'admin@company.test')->first();
        if ($user && $user->branch_id) {
            return (int) $user->branch_id;
        }

        return Branch::where('company_id', $this->getCompanyId())->value('id');
    }

    private function getWarehouseId(): ?int
    {
        $branchId = $this->getBranchId();
        if (! $branchId) {
            return null;
        }

        return Warehouse::where('branch_id', $branchId)->value('id');
    }

    private function login(): bool
    {
        $r = Http::post($this->baseUrl . '/api/auth/login', [
            'email' => 'admin@company.test',
            'password' => 'password',
        ]);
        if ($r->successful() && $r->json('token')) {
            $this->token = $r->json('token');
            return true;
        }
        return false;
    }

    private function get(string $path): \Illuminate\Http\Client\Response
    {
        return Http::withToken($this->token)->get($this->baseUrl . $path);
    }

    private function post(string $path, array $data): \Illuminate\Http\Client\Response
    {
        return Http::withToken($this->token)->post($this->baseUrl . $path, $data);
    }

    private function runTest(string $name, callable $callable): void
    {
        try {
            $response = $callable();
            if ($response === null) {
                $this->skip($name, 'no data');
                return;
            }
            if ($response->successful()) {
                $this->passed++;
                $this->line("  <info>✓</info> {$name}");
            } else {
                $this->failed++;
                $this->line("  <error>✗</error> {$name} (HTTP {$response->status()})");
                if ($this->option('show-errors')) {
                    $this->line('<comment>' . substr($response->body(), 0, 500) . '</comment>');
                }
            }
        } catch (\Throwable $e) {
            $this->failed++;
            $this->line("  <error>✗</error> {$name} — " . $e->getMessage());
        }
    }

    private function skip(string $name, string $reason): void
    {
        $this->skipped++;
        $this->line("  <comment>-</comment> {$name} (skipped: {$reason})");
    }
}
