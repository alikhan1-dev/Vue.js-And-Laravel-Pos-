<?php

namespace App\Services;

use App\Services\BatchAllocationService;
use App\Services\PaymentService;
use App\Services\SerialSaleGuard;
use App\Services\WarrantyService;
use App\Enums\SaleStatus;
use App\Enums\SaleType;
use App\Enums\StockMovementType;
use App\DataTransferObjects\SaleAuditMetadata;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\Sale;
use App\Models\SaleAuditLog;
use App\Models\SaleLine;
use App\Models\StockCache;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SaleService
{
    public function __construct(
        private PaymentService $paymentService,
        private WarrantyService $warrantyService,
        private SerialSaleGuard $serialSaleGuard,
        private BatchAllocationService $batchAllocationService,
    ) {
    }

    /**
     * Create sale or quotation. For type=sale, validates stock inside transaction with row lock (FOR UPDATE).
     * Atomic: all-or-nothing; high-volume warehouses use pessimistic lock on stock_cache rows.
     */
    public function create(array $data, User $creator): Sale
    {
        $type = isset($data['type']) ? SaleType::from($data['type']) : SaleType::Sale;
        $branchId = (int) $data['branch_id'];
        $warehouseId = (int) $data['warehouse_id'];
        $lines = $data['lines'] ?? [];

        $this->ensureBranchAndWarehouseBelongToCompany($creator->company_id, $branchId, $warehouseId);

        return DB::transaction(function () use ($data, $creator, $type, $branchId, $warehouseId, $lines) {
            $productIds = array_unique(array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $lines));

            if ($type === SaleType::Sale && ! empty($productIds)) {
                StockCache::where('warehouse_id', $warehouseId)->whereIn('product_id', $productIds)->lockForUpdate()->get();
                $this->validateStockForLines($lines, $warehouseId);
            }

            $total = 0;
            foreach ($lines as $line) {
                $qty = (float) ($line['quantity'] ?? 0);
                $unitPrice = (float) ($line['unit_price'] ?? 0);
                $discount = (float) ($line['discount'] ?? 0);
                $subtotal = max(0, $qty * $unitPrice - $discount);
                $total += $subtotal;
            }

            $sale = Sale::withoutGlobalScope('company')->create([
                'company_id' => $creator->company_id,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'customer_id' => $data['customer_id'] ?? null,
                'type' => $type,
                'status' => $type === SaleType::Sale ? SaleStatus::Completed : SaleStatus::Pending,
                'total' => $total,
                'created_by' => $creator->id,
            ]);

            foreach ($lines as $line) {
                $this->createLine($sale, $line, $warehouseId, $creator, $type);
            }

            if ($type === SaleType::Quotation && ! empty($lines)) {
                $this->createReservationsForQuotation($sale, $lines, $warehouseId);
            }

            // Auto-create warranty registrations for completed sales
            if ($type === SaleType::Sale && $sale->status === SaleStatus::Completed) {
                $this->warrantyService->registerForSale($sale, $lines);
            }

            $sale->load(['lines']);
            $metadata = SaleAuditMetadata::forCreated(count($lines), $sale->lines->map(fn ($l) => [
                'product_id' => $l->product_id,
                'variant_id' => $l->variant_id,
                'quantity' => (float) $l->quantity,
                'stock_movement_id' => $l->stock_movement_id,
                'lot_number' => $l->lot_number,
                'imei_id' => $l->imei_id,
            ])->toArray());
            $this->logAudit(
                $sale->id,
                SaleAuditLog::EVENT_CREATED,
                null,
                $sale->status->value,
                null,
                $type->value,
                $metadata,
                $creator->id
            );

            if ($type === SaleType::Sale && $sale->status === SaleStatus::Completed) {
                // Accrual posting: Dr Accounts Receivable, Cr Sales Revenue
                $this->paymentService->postSalePosting($sale, $creator);
            }

            return $sale->load(['lines.product', 'lines.stockMovement', 'branch', 'warehouse']);
        });
    }

    /**
     * Convert quotation to sale: validate stock inside transaction with row lock, create sale_out movements, update type/status.
     */
    public function convertToSale(Sale $sale, User $creator): Sale
    {
        if ($sale->type !== SaleType::Quotation) {
            throw new InvalidArgumentException('Only quotations can be converted to sale.');
        }

        $warehouseId = $sale->warehouse_id;
        $lines = $sale->lines;
        $linesData = $lines->map(fn ($l) => [
            'product_id' => $l->product_id,
            'quantity' => (float) $l->quantity,
            'unit_price' => (float) $l->unit_price,
            'discount' => (float) $l->discount,
        ])->toArray();

        return DB::transaction(function () use ($sale, $creator, $warehouseId, $linesData) {
            $productIds = $sale->lines->pluck('product_id')->unique()->values()->all();
            if (! empty($productIds)) {
                StockCache::where('warehouse_id', $warehouseId)->whereIn('product_id', $productIds)->lockForUpdate()->get();
                $this->validateStockForLines($linesData, $warehouseId);
            }

            $movementIds = [];
            foreach ($sale->lines as $line) {
                $movement = StockMovement::withoutGlobalScope('company')->create([
                    'product_id' => $line->product_id,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $line->quantity,
                    'type' => StockMovementType::SaleOut,
                    'reference_type' => 'Sale',
                    'reference_id' => $sale->id,
                    'created_by' => $creator->id,
                ]);
                $movementIds[] = $movement->id;
                $line->update(['stock_movement_id' => $movement->id]);
            }

            $sale->update(['type' => SaleType::Sale, 'status' => SaleStatus::Completed]);

            // Release any active reservations tied to this quotation.
            StockReservation::where('company_id', $sale->company_id)
                ->where('warehouse_id', $warehouseId)
                ->where('reference_type', 'Quotation')
                ->where('reference_id', $sale->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'released',
                    'expires_at' => now(),
                ]);

            $sale->load(['lines']);
            $linesWithMovement = $sale->lines->map(fn ($l) => [
                'product_id' => $l->product_id,
                'quantity' => (float) $l->quantity,
                'stock_movement_id' => $l->stock_movement_id,
                'variant_id' => $l->variant_id,
                'lot_number' => $l->lot_number,
                'imei_id' => $l->imei_id,
            ])->toArray();
            $metadata = SaleAuditMetadata::forConvertedToSale($movementIds, $linesWithMovement);
            $this->logAudit($sale->id, SaleAuditLog::EVENT_CONVERTED_TO_SALE, SaleStatus::Pending->value, SaleStatus::Completed->value, SaleType::Quotation->value, SaleType::Sale->value, $metadata, $creator->id);

            // Accrual posting when quotation becomes completed sale
            $this->paymentService->postSalePosting($sale, $creator);

            return $sale->fresh(['lines.product', 'lines.stockMovement', 'branch', 'warehouse']);
        });
    }

    /**
     * Create return sale for a completed sale; creates purchase_in movements to restore stock.
     * Line-level validation: return quantity per product cannot exceed quantity sold in original sale.
     */
    public function createReturn(Sale $originalSale, ?array $linesOverride, User $creator): Sale
    {
        if ($originalSale->type !== SaleType::Sale) {
            throw new InvalidArgumentException('Returns can only be created for completed sales.');
        }

        $warehouseId = $originalSale->warehouse_id;
        $linesToReturn = $linesOverride ?? $originalSale->lines->map(fn ($l) => [
            'product_id' => $l->product_id,
            'quantity' => $l->quantity,
            'unit_price' => $l->unit_price,
            'discount' => $l->discount,
        ])->toArray();

        $this->validateReturnQuantitiesAgainstOriginalSale($originalSale, $linesToReturn);

        return DB::transaction(function () use ($originalSale, $linesToReturn, $warehouseId, $creator) {
            $total = 0;
            foreach ($linesToReturn as $line) {
                $qty = (float) ($line['quantity'] ?? 0);
                $unitPrice = (float) ($line['unit_price'] ?? 0);
                $discount = (float) ($line['discount'] ?? 0);
                $total += max(0, $qty * $unitPrice - $discount);
            }

            $returnSale = Sale::withoutGlobalScope('company')->create([
                'company_id' => $creator->company_id,
                'branch_id' => $originalSale->branch_id,
                'warehouse_id' => $warehouseId,
                'customer_id' => $originalSale->customer_id,
                'type' => SaleType::Return,
                'status' => SaleStatus::Completed,
                'total' => $total,
                'created_by' => $creator->id,
                'return_for_sale_id' => $originalSale->id,
            ]);

            $movementIds = [];
            $linesWithMovement = [];
            foreach ($linesToReturn as $line) {
                $productId = (int) ($line['product_id'] ?? 0);
                $quantity = (float) ($line['quantity'] ?? 0);
                $variantId = isset($line['variant_id']) ? (int) $line['variant_id'] : null;
                $unitPrice = (float) ($line['unit_price'] ?? 0);
                $discount = (float) ($line['discount'] ?? 0);
                $subtotal = max(0, $quantity * $unitPrice - $discount);

                $movement = StockMovement::withoutGlobalScope('company')->create([
                    'product_id' => $productId,
                    'variant_id' => $variantId ?: null,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $quantity,
                    'type' => StockMovementType::ReturnIn,
                    'reference_type' => 'SaleReturn',
                    'reference_id' => $returnSale->id,
                    'created_by' => $creator->id,
                ]);
                $movementIds[] = $movement->id;
                $linesWithMovement[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'stock_movement_id' => $movement->id,
                    'variant_id' => $variantId,
                ];

                SaleLine::withoutGlobalScope('company')->create([
                    'sale_id' => $returnSale->id,
                    'product_id' => $productId,
                    'variant_id' => $variantId ?: null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'subtotal' => $subtotal,
                    'stock_movement_id' => $movement->id,
                ]);
            }

            // Mark serials from the original sale as returned so they can be sold again or restocked.
            \App\Models\ProductSerial::withoutGlobalScope('company')
                ->where('sale_id', $originalSale->id)
                ->update([
                    'status' => 'returned',
                    'sale_id' => null,
                    'reference_type' => null,
                    'reference_id' => null,
                ]);

            $metadata = SaleAuditMetadata::forReturnCreated($originalSale->id, $movementIds, $linesWithMovement);
            $this->logAudit($returnSale->id, SaleAuditLog::EVENT_RETURN_CREATED, null, SaleStatus::Completed->value, null, SaleType::Return->value, $metadata, $creator->id);

            // Accrual for goods returned but not yet refunded: Dr Sales Returns, Cr Accounts Receivable
            $this->paymentService->postReturnPosting($originalSale, $returnSale, $creator);

            return $returnSale->load(['lines.product', 'lines.stockMovement', 'branch', 'warehouse']);
        });
    }

    private function ensureBranchAndWarehouseBelongToCompany(int $companyId, int $branchId, int $warehouseId): void
    {
        $warehouse = Warehouse::with('branch')->find($warehouseId);
        if (! $warehouse || $warehouse->branch->company_id !== $companyId || $warehouse->branch_id !== $branchId) {
            throw new InvalidArgumentException('Branch or warehouse not found or does not belong to your company.');
        }
    }

    private function validateStockForLines(array $lines, int $warehouseId): void
    {
        $requirements = $this->buildStockRequirementsForLines($lines);

        if (empty($requirements)) {
            return;
        }

        $productIds = array_keys($requirements);

        $products = Product::withoutGlobalScope('company')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        // Fetch existing stock_cache rows in batch
        $stockRows = StockCache::whereIn('product_id', $productIds)
            ->where('warehouse_id', $warehouseId)
            ->get()
            ->keyBy('product_id');

        // Fetch active reservations in batch
        $reservationRows = StockReservation::whereIn('product_id', $productIds)
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'active')
            ->selectRaw('product_id, SUM(quantity) as total_reserved')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        foreach ($requirements as $productId => $requiredQty) {
            /** @var Product|null $product */
            $product = $products->get($productId);
            if (! $product) {
                throw new InvalidArgumentException("Product id {$productId} not found.");
            }

            $cacheRow = $stockRows->get($productId);
            $onHand = $cacheRow ? (float) $cacheRow->quantity : 0.0;

            $reserved = $reservationRows->has($productId)
                ? (float) $reservationRows->get($productId)->total_reserved
                : 0.0;

            $available = $onHand - $reserved;
            if ($available < $requiredQty && ! $product->allow_negative_stock) {
                throw new InvalidArgumentException("Insufficient stock for product {$product->name} (SKU: {$product->sku}). Available: {$available}, required for sale: {$requiredQty}.");
            }
        }
    }

    /**
     * Aggregate required quantities per physical product, expanding bundle lines into their components.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, float> product_id => required_quantity
     */
    private function buildStockRequirementsForLines(array $lines): array
    {
        $requirements = [];
        $productsById = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $lineQty = (float) ($line['quantity'] ?? 0);

            if ($productId <= 0 || $lineQty <= 0) {
                continue;
            }

            $product = $productsById[$productId] ?? Product::withoutGlobalScope('company')
                ->with('bundleComponents')
                ->find($productId);

            if (! $product) {
                throw new InvalidArgumentException("Product id {$productId} not found.");
            }

            $productsById[$productId] = $product;

            if ($product->bundleComponents->isNotEmpty()) {
                // For now, do not support serial-tracked bundle parents.
                if ($product->track_serial) {
                    throw new InvalidArgumentException("Serialized products cannot be sold as bundle parents yet (product id {$productId}).");
                }

                foreach ($product->bundleComponents as $bundleLine) {
                    if (! $bundleLine->is_active) {
                        continue;
                    }

                    $componentId = (int) $bundleLine->component_product_id;
                    $componentQty = $lineQty * (float) $bundleLine->quantity;

                    $requirements[$componentId] = ($requirements[$componentId] ?? 0.0) + $componentQty;
                }
            } else {
                $requirements[$productId] = ($requirements[$productId] ?? 0.0) + $lineQty;
            }
        }

        return $requirements;
    }

    /**
     * Return quantity per product cannot exceed total sold in the original sale (line-level).
     */
    private function validateReturnQuantitiesAgainstOriginalSale(Sale $originalSale, array $linesToReturn): void
    {
        $soldByProduct = $originalSale->lines->groupBy('product_id')->map(fn ($lines) => $lines->sum('quantity'));

        foreach ($linesToReturn as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $returnQty = (float) ($line['quantity'] ?? 0);
            $soldQty = (float) $soldByProduct->get($productId, 0);

            if ($soldQty < $returnQty) {
                $product = Product::withoutGlobalScope('company')->find($productId);
                $name = $product ? $product->name : "Product #{$productId}";
                throw new InvalidArgumentException("Return quantity for {$name} ({$returnQty}) cannot exceed quantity sold in original sale ({$soldQty}).");
            }
            if ($soldQty <= 0) {
                throw new InvalidArgumentException("Product id {$productId} was not part of the original sale.");
            }
        }
    }

    private function logAudit(int $saleId, string $event, ?string $fromStatus, ?string $toStatus, ?string $fromType, ?string $toType, array $metadata, ?int $createdBy): void
    {
        SaleAuditLog::create([
            'sale_id' => $saleId,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'from_type' => $fromType,
            'to_type' => $toType,
            'metadata' => $metadata,
            'created_by' => $createdBy,
        ]);
    }

    private function createLine(Sale $sale, array $line, int $warehouseId, User $creator, SaleType $saleType): void
    {
        $productId = (int) ($line['product_id'] ?? 0);
        $quantity = (float) ($line['quantity'] ?? 0);
        $unitPrice = (float) ($line['unit_price'] ?? 0);
        $discount = (float) ($line['discount'] ?? 0);
        $serialId = isset($line['serial_id']) ? (int) $line['serial_id'] : null;
        $variantId = isset($line['variant_id']) ? (int) $line['variant_id'] : null;
        $batchId = isset($line['batch_id']) ? (int) $line['batch_id'] : null;
        $unitCost = isset($line['unit_cost']) ? (float) $line['unit_cost'] : null;
        $lineTotal = $quantity * $unitPrice;
        if ($discount > $lineTotal) {
            $discount = $lineTotal;
        }
        $subtotal = max(0, $lineTotal - $discount);

        $stockMovementId = null;
        if ($saleType === SaleType::Sale) {
            $product = Product::withoutGlobalScope('company')
                ->with('bundleComponents')
                ->find($productId);

            if (! $product) {
                throw new InvalidArgumentException("Product id {$productId} not found.");
            }

            $components = $product->bundleComponents;

            if ($components->isNotEmpty()) {
                // Bundle / kit parent: consume stock from component products only.
                if ($product->track_serial) {
                    throw new InvalidArgumentException("Serialized products cannot be sold as bundle parents yet (product id {$productId}).");
                }

                foreach ($components as $bundleLine) {
                    if (! $bundleLine->is_active) {
                        continue;
                    }

                    $componentId = (int) $bundleLine->component_product_id;
                    $componentQty = $quantity * (float) $bundleLine->quantity;

                    StockMovement::withoutGlobalScope('company')->create([
                        'product_id' => $componentId,
                        'warehouse_id' => $warehouseId,
                        'quantity' => $componentQty,
                        'type' => StockMovementType::SaleOut,
                        'reference_type' => 'Sale',
                        'reference_id' => $sale->id,
                        'created_by' => $creator->id,
                    ]);
                }
            } else {
                // Serialized products: require serial_id and validate before creating movement.
                if ($product->track_serial) {
                    if (! $serialId) {
                        throw new InvalidArgumentException("Serialized product (id {$productId}) requires a serial_id for sale.");
                    }
                    $this->serialSaleGuard->validateSerialForSale($serialId, $productId, $warehouseId);
                }

                // Batch-tracked products: validate batch or allocate FEFO.
                $resolvedBatchId = $batchId;
                if ($product->track_batch) {
                    if ($batchId) {
                        if (! $this->batchAllocationService->validateBatchForMovement($batchId, $productId, $warehouseId)) {
                            throw new InvalidArgumentException("Invalid or expired batch id {$batchId} for product id {$productId}.");
                        }
                        $resolvedBatchId = $batchId;
                    } else {
                        $batch = $this->batchAllocationService->getEarliestValidBatch($productId, $warehouseId);
                        if (! $batch) {
                            throw new InvalidArgumentException("No valid (non-expired) batch available for product id {$productId} in warehouse {$warehouseId}.");
                        }
                        $resolvedBatchId = $batch->id;
                    }
                }

                $movement = StockMovement::withoutGlobalScope('company')->create([
                    'product_id' => $productId,
                    'variant_id' => $variantId ?: null,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'type' => StockMovementType::SaleOut,
                    'reference_type' => 'Sale',
                    'reference_id' => $sale->id,
                    'batch_id' => $resolvedBatchId ?? null,
                    'serial_id' => $serialId,
                    'created_by' => $creator->id,
                ]);
                $stockMovementId = $movement->id;

                if ($serialId && $product->track_serial) {
                    $serial = \App\Models\ProductSerial::find($serialId);
                    if ($serial) {
                        $this->serialSaleGuard->markSerialSold($serial, $sale->id);
                    }
                }
            }
        }

        SaleLine::withoutGlobalScope('company')->create([
            'sale_id' => $sale->id,
            'product_id' => $productId,
            'variant_id' => $variantId ?: null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount' => $discount,
            'subtotal' => $subtotal,
            'stock_movement_id' => $stockMovementId,
            'lot_number' => $line['lot_number'] ?? null,
            'imei_id' => $serialId,
        ]);
    }

    /**
     * Create stock_reservations entries for a quotation, aggregating required quantities per product.
     *
     * Reservations are per-warehouse and per-product (not per-line), and are marked as active until
     * the quotation is converted or otherwise released.
     */
    private function createReservationsForQuotation(Sale $sale, array $lines, int $warehouseId): void
    {
        $requirements = $this->buildStockRequirementsForLines($lines);

        if (empty($requirements)) {
            return;
        }

        foreach ($requirements as $productId => $requiredQty) {
            if ($requiredQty <= 0) {
                continue;
            }

            StockReservation::create([
                'company_id' => $sale->company_id,
                'product_id' => $productId,
                'variant_id' => null,
                'warehouse_id' => $warehouseId,
                'quantity' => $requiredQty,
                'reference_type' => 'Quotation',
                'reference_id' => $sale->id,
                'status' => 'active',
            ]);
        }
    }
}
