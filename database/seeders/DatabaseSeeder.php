<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StockMovement;
use App\Models\StockCache;
use App\Models\Warranty;
use App\Models\ProductWarranty;
use App\Models\Account;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PaymentMethod;
use App\Enums\SaleType;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Enums\PaymentStatus;
use App\Enums\AccountType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use App\Models\Role;

class DatabaseSeeder extends Seeder
{

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ensure permission cache is cleared before seeding.
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Default company
        $company = Company::firstOrCreate(
            ['email' => 'default@company.test'],
            [
                'name' => 'Default Company',
                'currency' => 'USD',
                'timezone' => 'UTC',
            ],
        );

        // Base permissions
        $permissions = collect([
            'view_sales',
            'create_sale',
            'manage_inventory',
            'manage_users',
            'view_reports',
            'manage_accounting',
        ])->map(function (string $name) {
            return Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        });

        // Roles are scoped per company (tenant)
        $adminRole = Role::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Admin', 'guard_name' => 'web'],
        );

        $cashierRole = Role::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Cashier', 'guard_name' => 'web'],
        );

        $managerRole = Role::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Manager', 'guard_name' => 'web'],
        );

        $accountantRole = Role::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Accountant', 'guard_name' => 'web'],
        );

        // Assign permissions to roles
        $adminRole->syncPermissions($permissions);

        $cashierRole->syncPermissions(
            $permissions->whereIn('name', ['view_sales', 'create_sale']),
        );

        $managerRole->syncPermissions(
            $permissions->whereIn('name', ['view_sales', 'manage_inventory', 'view_reports']),
        );

        $accountantRole->syncPermissions(
            $permissions->whereIn('name', ['view_reports', 'manage_accounting']),
        );

        // Super admin user (assigned to the default company; branch is attached after branch creation)
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@company.test'],
            [
                'company_id' => $company->id,
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'is_active' => true,
            ],
        );

        if (! $superAdmin->hasRole($adminRole->name)) {
            $superAdmin->assignRole($adminRole);
        }

        // Default branch (Head Office) and warehouse (Main Warehouse) for the default company
        $branch = Branch::firstOrCreate(
            [
                'company_id' => $company->id,
                'code' => 'HO',
            ],
            [
                'name' => 'Head Office',
                'address' => null,
                'is_active' => true,
            ],
        );

        if (! $superAdmin->branch_id) {
            $superAdmin->branch_id = $branch->id;
            $superAdmin->save();
        }

        $warehouse = Warehouse::firstOrCreate(
            [
                'branch_id' => $branch->id,
                'code' => 'MAIN',
            ],
            [
                'name' => 'Main Warehouse',
                'location' => null,
                'is_active' => true,
            ],
        );

        // Step 6: Master data — Categories, Brands, Units
        $electronicsCategory = Category::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Electronics'],
            ['parent_id' => null, 'is_active' => true],
        );
        $accessoriesCategory = Category::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Accessories'],
            ['parent_id' => $electronicsCategory->id, 'is_active' => true],
        );

        $appleBrand = Brand::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Apple'],
            ['is_active' => true],
        );
        $samsungBrand = Brand::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Samsung'],
            ['is_active' => true],
        );

        $pieceUnit = Unit::firstOrCreate(
            ['company_id' => $company->id, 'short_name' => 'pc'],
            ['name' => 'Piece', 'is_active' => true],
        );
        $kgUnit = Unit::firstOrCreate(
            ['company_id' => $company->id, 'short_name' => 'kg'],
            ['name' => 'Kilogram', 'is_active' => true],
        );

        // Sample product and initial stock movement (Step 3: Inventory)
        $product = Product::firstOrCreate(
            [
                'company_id' => $company->id,
                'sku' => 'SAMPLE-001',
            ],
            [
                'name' => 'Sample Product',
                'barcode' => '1234567890123',
                'description' => 'Sample product for testing inventory',
                'unit_price' => 99.99,
                'uom' => 'piece',
                'is_active' => true,
                'category_id' => $electronicsCategory->id,
                'brand_id' => $appleBrand->id,
                'unit_id' => $pieceUnit->id,
                'type' => 'simple',
                'cost_price' => 60.0000,
                'selling_price' => 99.9900,
                'track_stock' => true,
                'track_serial' => false,
                'track_batch' => false,
            ],
        );

        if (! $product->stockMovements()->where('warehouse_id', $warehouse->id)->exists()) {
            StockMovement::withoutGlobalScope('company')->create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 50,
                'type' => StockMovementType::PurchaseIn,
                'reference_type' => 'Seeder',
                'reference_id' => null,
                'created_by' => $superAdmin->id,
            ]);
        }

        // Step 6: Variable product with variants
        $variableProduct = Product::firstOrCreate(
            ['company_id' => $company->id, 'sku' => 'PHONE-001'],
            [
                'name' => 'Smartphone',
                'barcode' => '9876543210987',
                'description' => 'Sample variable product with color variants',
                'unit_price' => 799.00,
                'uom' => 'piece',
                'is_active' => true,
                'category_id' => $electronicsCategory->id,
                'brand_id' => $samsungBrand->id,
                'unit_id' => $pieceUnit->id,
                'type' => 'variable',
                'cost_price' => 500.0000,
                'selling_price' => 799.0000,
                'track_stock' => true,
                'track_serial' => true,
                'track_batch' => false,
            ],
        );

        $variantBlack = ProductVariant::firstOrCreate(
            ['product_id' => $variableProduct->id, 'sku' => 'PHONE-001-BLK'],
            ['name' => 'Black', 'cost_price' => 500.0000, 'selling_price' => 799.0000, 'is_active' => true],
        );
        $variantWhite = ProductVariant::firstOrCreate(
            ['product_id' => $variableProduct->id, 'sku' => 'PHONE-001-WHT'],
            ['name' => 'White', 'cost_price' => 500.0000, 'selling_price' => 819.0000, 'is_active' => true],
        );

        if (! $variableProduct->stockMovements()->where('warehouse_id', $warehouse->id)->exists()) {
            StockMovement::withoutGlobalScope('company')->create([
                'product_id' => $variableProduct->id,
                'variant_id' => $variantBlack->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 20,
                'unit_cost' => 500.0000,
                'type' => StockMovementType::PurchaseIn,
                'reference_type' => 'Seeder',
                'reference_id' => null,
                'created_by' => $superAdmin->id,
            ]);
            StockMovement::withoutGlobalScope('company')->create([
                'product_id' => $variableProduct->id,
                'variant_id' => $variantWhite->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 15,
                'unit_cost' => 500.0000,
                'type' => StockMovementType::PurchaseIn,
                'reference_type' => 'Seeder',
                'reference_id' => null,
                'created_by' => $superAdmin->id,
            ]);
        }

        // Step 6: Second warehouse + inter-warehouse transfer demo
        $warehouse2 = Warehouse::firstOrCreate(
            ['branch_id' => $branch->id, 'code' => 'SEC'],
            ['name' => 'Secondary Warehouse', 'location' => null, 'is_active' => true],
        );

        $transferExists = StockMovement::withoutGlobalScope('company')
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse2->id)
            ->where('type', StockMovementType::TransferIn)
            ->exists();

        if (! $transferExists) {
            $outMovement = StockMovement::withoutGlobalScope('company')->create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 10,
                'type' => StockMovementType::TransferOut,
                'reference_type' => 'SeederTransfer',
                'reference_id' => null,
                'created_by' => $superAdmin->id,
            ]);
            StockMovement::withoutGlobalScope('company')->create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse2->id,
                'quantity' => 10,
                'type' => StockMovementType::TransferIn,
                'reference_type' => 'SeederTransfer',
                'reference_id' => $outMovement->id,
                'created_by' => $superAdmin->id,
            ]);
        }

        // Step 6.1: Warranty master data
        $oneYearManufacturer = Warranty::firstOrCreate(
            [
                'company_id' => $company->id,
                'name' => '1 Year Manufacturer Warranty',
            ],
            [
                'duration_months' => 12,
                'type' => \App\Enums\WarrantyType::Manufacturer,
                'description' => 'Standard 1 year manufacturer hardware warranty.',
                'is_active' => true,
            ],
        );

        $sixMonthSeller = Warranty::firstOrCreate(
            [
                'company_id' => $company->id,
                'name' => '6 Month Seller Warranty',
            ],
            [
                'duration_months' => 6,
                'type' => \App\Enums\WarrantyType::Seller,
                'description' => '6-month store warranty.',
                'is_active' => true,
            ],
        );

        $twoYearExtended = Warranty::firstOrCreate(
            [
                'company_id' => $company->id,
                'name' => '2 Year Extended Warranty',
            ],
            [
                'duration_months' => 24,
                'type' => \App\Enums\WarrantyType::Extended,
                'description' => 'Additional 2 years extended coverage.',
                'is_active' => true,
            ],
        );

        // Map warranties to products (demo)
        ProductWarranty::firstOrCreate(
            ['product_id' => $variableProduct->id, 'warranty_id' => $oneYearManufacturer->id],
            ['is_default' => true],
        );
        ProductWarranty::firstOrCreate(
            ['product_id' => $product->id, 'warranty_id' => $sixMonthSeller->id],
            ['is_default' => true],
        );

        // Step 4: Sample sale, quotation, and return
        $completedSale = Sale::withoutGlobalScope('company')->firstOrCreate(
            [
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'type' => SaleType::Sale,
                'status' => SaleStatus::Completed,
            ],
            [
                'total' => 499.95,
                'created_by' => $superAdmin->id,
            ]
        );

        if (! $completedSale->lines()->exists()) {
            $movement = StockMovement::withoutGlobalScope('company')->create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 5,
                'type' => StockMovementType::SaleOut,
                'reference_type' => 'Sale',
                'reference_id' => $completedSale->id,
                'created_by' => $superAdmin->id,
            ]);
            SaleLine::withoutGlobalScope('company')->create([
                'sale_id' => $completedSale->id,
                'product_id' => $product->id,
                'quantity' => 5,
                'unit_price' => 99.99,
                'discount' => 0,
                'subtotal' => 499.95,
                'stock_movement_id' => $movement->id,
            ]);
        }

        $quotation = Sale::withoutGlobalScope('company')->firstOrCreate(
            [
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'type' => SaleType::Quotation,
                'status' => SaleStatus::Pending,
            ],
            [
                'total' => 299.97,
                'created_by' => $superAdmin->id,
            ]
        );

        if (! $quotation->lines()->exists()) {
            SaleLine::withoutGlobalScope('company')->create([
                'sale_id' => $quotation->id,
                'product_id' => $product->id,
                'quantity' => 3,
                'unit_price' => 99.99,
                'discount' => 0,
                'subtotal' => 299.97,
                'stock_movement_id' => null,
            ]);
        }

        // Sale return (dedicated sale_returns table)
        $saleReturn = \App\Models\SaleReturn::withoutGlobalScope('company')->firstOrCreate(
            [
                'sale_id' => $completedSale->id,
                'company_id' => $company->id,
            ],
            [
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'customer_id' => null,
                'refund_amount' => 199.98,
                'status' => \App\Enums\SaleReturnStatus::Completed,
                'created_by' => $superAdmin->id,
            ]
        );

        if (! $saleReturn->items()->exists()) {
            $returnMovement = StockMovement::withoutGlobalScope('company')->create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 2,
                'type' => StockMovementType::ReturnIn,
                'reference_type' => 'SaleReturn',
                'reference_id' => $saleReturn->id,
                'created_by' => $superAdmin->id,
            ]);
            \App\Models\SaleReturnItem::withoutGlobalScope('company')->create([
                'sale_return_id' => $saleReturn->id,
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 99.99,
                'total' => 199.98,
                'stock_movement_id' => $returnMovement->id,
            ]);
        }

        // Step 5: Default chart of accounts, payment methods, and sample accrual + payment
        $cashAccount = Account::firstOrCreate(
            ['company_id' => $company->id, 'code' => '1000'],
            ['name' => 'Cash', 'type' => AccountType::Asset, 'parent_id' => null, 'is_active' => true],
        );
        $bankAccount = Account::firstOrCreate(
            ['company_id' => $company->id, 'code' => '1010'],
            ['name' => 'Bank', 'type' => AccountType::Asset, 'parent_id' => null, 'is_active' => true],
        );
        $receivableAccount = Account::firstOrCreate(
            ['company_id' => $company->id, 'code' => '1100'],
            ['name' => 'Accounts Receivable', 'type' => AccountType::Asset, 'parent_id' => null, 'is_active' => true],
        );
        $salesAccount = Account::firstOrCreate(
            ['company_id' => $company->id, 'code' => '4000'],
            ['name' => 'Sales Revenue', 'type' => AccountType::Income, 'parent_id' => null, 'is_active' => true],
        );
        $returnsAccount = Account::firstOrCreate(
            ['company_id' => $company->id, 'code' => '5000'],
            ['name' => 'Sales Returns', 'type' => AccountType::ContraIncome, 'parent_id' => null, 'is_active' => true],
        );
        Account::firstOrCreate(
            ['company_id' => $company->id, 'code' => '1200'],
            ['name' => 'Inventory', 'type' => AccountType::Asset, 'parent_id' => null, 'is_active' => true],
        );
        Account::firstOrCreate(
            ['company_id' => $company->id, 'code' => '2000'],
            ['name' => 'Accounts Payable', 'type' => AccountType::Liability, 'parent_id' => null, 'is_active' => true],
        );
        Account::firstOrCreate(
            ['company_id' => $company->id, 'code' => '2100'],
            ['name' => 'Goods Received Not Invoiced (GRNI)', 'type' => AccountType::Liability, 'parent_id' => null, 'is_active' => true],
        );

        // Default payment methods
        $cashMethod = PaymentMethod::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Cash'],
            ['type' => 'cash', 'is_active' => true],
        );
        $cardMethod = PaymentMethod::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Card'],
            ['type' => 'card', 'is_active' => true],
        );
        $btMethod = PaymentMethod::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Bank Transfer'],
            ['type' => 'bank', 'is_active' => true],
        );

        if (! $completedSale->payments()->exists()) {
            // Accrual at sale completion: Dr Accounts Receivable, Cr Sales Revenue
            $saleEntry = JournalEntry::withoutGlobalScope('company')->create([
                'company_id' => $company->id,
                'reference_type' => JournalEntry::REFERENCE_TYPE_SALE,
                'reference_id' => $completedSale->id,
                'entry_type' => JournalEntry::ENTRY_TYPE_SALE_POSTING,
                'created_by' => $superAdmin->id,
                'posted_at' => now(),
                'is_locked' => true,
            ]);
            JournalEntryLine::insert([
                [
                    'journal_entry_id' => $saleEntry->id,
                    'account_id' => $receivableAccount->id,
                    'type' => 'debit',
                    'amount' => 499.95,
                    'description' => 'Sale posting for seeded sale',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'journal_entry_id' => $saleEntry->id,
                    'account_id' => $salesAccount->id,
                    'type' => 'credit',
                    'amount' => 499.95,
                    'description' => 'Sale posting for seeded sale',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            // Payment received: Dr Cash, Cr Accounts Receivable
            $payment = Payment::withoutGlobalScope('company')->create([
                'sale_id' => $completedSale->id,
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'amount' => 499.95,
                'status' => PaymentStatus::Completed,
                'created_by' => $superAdmin->id,
            ]);
            PaymentLine::create([
                'payment_id' => $payment->id,
                'payment_method_id' => $cashMethod->id,
                'account_id' => $cashAccount->id,
                'amount' => 499.95,
                'reference' => null,
                'description' => 'Full payment for sale #' . $completedSale->id,
            ]);
            $paymentEntry = JournalEntry::withoutGlobalScope('company')->create([
                'company_id' => $company->id,
                'reference_type' => JournalEntry::REFERENCE_TYPE_PAYMENT,
                'reference_id' => $payment->id,
                'entry_type' => JournalEntry::ENTRY_TYPE_PAYMENT_RECEIPT,
                'created_by' => $superAdmin->id,
                'posted_at' => now(),
                'is_locked' => true,
            ]);
            JournalEntryLine::insert([
                [
                    'journal_entry_id' => $paymentEntry->id,
                    'account_id' => $cashAccount->id,
                    'type' => 'debit',
                    'amount' => 499.95,
                    'description' => 'Seeded cash payment',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'journal_entry_id' => $paymentEntry->id,
                    'account_id' => $receivableAccount->id,
                    'type' => 'credit',
                    'amount' => 499.95,
                    'description' => 'Seeded cash payment',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }
}
