<?php

namespace App\Services;

use App\Services\BatchAllocationService;
use App\Services\PaymentService;
use App\Services\SerialSaleGuard;
use App\Services\WarrantyService;
use App\Enums\SalePaymentStatus;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Enums\SaleType;
use App\Enums\DiscountType;
use App\Enums\StockMovementType;
use App\DataTransferObjects\SaleAuditMetadata;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\Sale;
use App\Models\SaleAuditLog;
use App\Models\SaleLine;
use App\Models\SaleLineHistory;
use App\Models\SaleDiscount;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockCache;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\RetryOnDeadlock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SaleService
{
    use RetryOnDeadlock;
    public function __construct(
        private PaymentService $paymentService,
        private CustomerLedgerService $customerLedgerService,
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
        $isDraft = isset($data['status']) && $data['status'] === 'draft' && $type === SaleType::Sale;

        $this->ensureBranchAndWarehouseBelongToCompany($creator->company_id, $branchId, $warehouseId);
        $this->ensureLineWarehousesBelongToBranch($branchId, $lines, $warehouseId);
        $this->enforcePosRequirements($data);

        return $this->transactionWithRetry(function () use ($data, $creator, $type, $branchId, $warehouseId, $lines, $isDraft) {
            $productIds = array_unique(array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $lines));

            if ($type === SaleType::Sale && ! $isDraft && ! empty($productIds)) {
                StockCache::where('warehouse_id', $warehouseId)->whereIn('product_id', $productIds)->lockForUpdate()->get();
                $this->validateStockForLines($lines, $warehouseId);
            }

            $subtotal = 0;
            foreach ($lines as $line) {
                $qty = (float) ($line['quantity'] ?? 0);
                $unitPrice = (float) ($line['unit_price'] ?? 0);
                $discount = (float) ($line['discount'] ?? 0);
                $subtotal += max(0, $qty * $unitPrice - $discount);
            }

            $discountTotal = $this->computeDiscountTotalFromData($data['discounts'] ?? [], $subtotal);
            $taxTotal = (float) ($data['tax_total'] ?? 0);
            $grandTotal = max(0, $subtotal - $discountTotal + $taxTotal);

            $saleStatus = $isDraft ? SaleStatus::Draft : ($type === SaleType::Sale ? SaleStatus::Completed : SaleStatus::Pending);

            $sale = Sale::withoutGlobalScope('company')->create([
                'company_id' => $creator->company_id,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'customer_id' => $data['customer_id'] ?? null,
                'type' => $type,
                'status' => $saleStatus,
                'payment_status' => SalePaymentStatus::Unpaid,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'total' => $grandTotal,
                'grand_total' => $grandTotal,
                'paid_amount' => 0,
                'due_amount' => $grandTotal,
                'currency' => $data['currency'] ?? 'PKR',
                'exchange_rate' => $data['exchange_rate'] ?? 1.000000,
                'notes' => $data['notes'] ?? null,
                'created_by' => $creator->id,
                'device_id' => $data['device_id'] ?? null,
                'pos_session_id' => $data['pos_session_id'] ?? null,
            ]);
            $this->assignSaleNumber($sale);

            $this->createSaleDiscounts($sale, $data['discounts'] ?? []);

            // Merge lines by (product_id, variant_id) so one line per product+variant (POS-friendly, no duplicate lines).
            $mergedLines = $this->mergeLinesByProductVariant($lines);

            foreach ($mergedLines as $line) {
                $this->createLine($sale, $line, $warehouseId, $creator, $isDraft ? SaleType::Quotation : $type);
            }

            if ($type === SaleType::Quotation && ! $isDraft && ! empty($lines)) {
                $reservationsByProduct = $this->createReservationsForQuotation($sale, $lines, $warehouseId);
                foreach ($sale->lines as $saleLine) {
                    $reservationId = $reservationsByProduct[$saleLine->product_id] ?? null;
                    if ($reservationId) {
                        $saleLine->update(['reservation_id' => $reservationId]);
                    }
                }
            }

            // Auto-create warranty registrations for completed sales
            if ($type === SaleType::Sale && ! $isDraft && $sale->status === SaleStatus::Completed) {
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
                $this->paymentService->postSalePosting($sale, $creator);
                if ($sale->customer_id) {
                    $this->customerLedgerService->postInvoice($sale, $creator);
                }
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

        return $this->transactionWithRetry(function () use ($sale, $creator, $warehouseId, $linesData) {
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

            $this->paymentService->postSalePosting($sale, $creator);
            if ($sale->customer_id) {
                $this->customerLedgerService->postInvoice($sale, $creator);
            }

            return $sale->fresh(['lines.product', 'lines.stockMovement', 'branch', 'warehouse']);
        });
    }

    /**
     * Create a sale return (dedicated return system). Creates SaleReturn + SaleReturnItem + ReturnIn movements.
     * Line-level validation: return quantity per product cannot exceed quantity sold in original sale.
     */
    public function createReturn(Sale $originalSale, ?array $linesOverride, User $creator, ?string $returnReasonCode = null, ?string $reasonText = null): SaleReturn
    {
        if ($originalSale->type !== SaleType::Sale) {
            throw new InvalidArgumentException('Returns can only be created for completed sales.');
        }

        $warehouseId = $originalSale->warehouse_id;
        $linesToReturn = $linesOverride ?? $originalSale->lines->map(fn ($l) => [
            'product_id' => $l->product_id,
            'quantity' => $l->quantity,
            'unit_price' => $l->unit_price,
            'discount' => $l->discount ?? 0,
        ])->toArray();

        $this->validateReturnQuantitiesAgainstOriginalSale($originalSale, $linesToReturn);

        return $this->transactionWithRetry(function () use ($originalSale, $linesToReturn, $warehouseId, $creator, $returnReasonCode, $reasonText) {
            $refundAmount = 0.0;
            foreach ($linesToReturn as $line) {
                $qty = (float) ($line['quantity'] ?? 0);
                $unitPrice = (float) ($line['unit_price'] ?? 0);
                $discount = (float) ($line['discount'] ?? 0);
                $refundAmount += max(0, $qty * $unitPrice - $discount);
            }

            $returnReasonCode = isset($linesOverride['return_reason_code']) ? \App\Enums\ReturnReasonCode::tryFrom($linesOverride['return_reason_code']) : null;
            $reasonText = $linesOverride['reason'] ?? null;
            $saleReturn = SaleReturn::withoutGlobalScope('company')->create([
                'sale_id' => $originalSale->id,
                'company_id' => $creator->company_id,
                'branch_id' => $originalSale->branch_id,
                'warehouse_id' => $warehouseId,
                'customer_id' => $originalSale->customer_id,
                'refund_amount' => $refundAmount,
                'status' => SaleReturnStatus::Completed,
                'reason' => $reasonText,
                'return_reason_code' => $reasonCodeEnum,
                'created_by' => $creator->id,
            ]);
            $this->assignReturnNumber($saleReturn);

            $movementIds = [];
            $linesWithMovement = [];
            foreach ($linesToReturn as $line) {
                $productId = (int) ($line['product_id'] ?? 0);
                $quantity = (float) ($line['quantity'] ?? 0);
                $variantId = isset($line['variant_id']) ? (int) $line['variant_id'] : null;
                $unitPrice = (float) ($line['unit_price'] ?? 0);
                $discount = (float) ($line['discount'] ?? 0);
                $lineTotalReturn = $quantity * $unitPrice;
                $total = max(0, $lineTotalReturn - $discount);

                $movement = StockMovement::withoutGlobalScope('company')->create([
                    'product_id' => $productId,
                    'variant_id' => $variantId ?: null,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $quantity,
                    'type' => StockMovementType::ReturnIn,
                    'reference_type' => 'SaleReturn',
                    'reference_id' => $saleReturn->id,
                    'created_by' => $creator->id,
                ]);

                SaleReturnItem::withoutGlobalScope('company')->create([
                    'sale_return_id' => $saleReturn->id,
                    'company_id' => $saleReturn->company_id,
                    'product_id' => $productId,
                    'variant_id' => $variantId ?: null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total' => $lineTotalReturn,
                    'stock_movement_id' => $movement->id,
                ]);

                $movementIds[] = $movement->id;
                $linesWithMovement[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'stock_movement_id' => $movement->id,
                    'variant_id' => $variantId,
                ];
            }

            \App\Models\ProductSerial::withoutGlobalScope('company')
                ->where('sale_id', $originalSale->id)
                ->update([
                    'status' => 'returned',
                    'sale_id' => null,
                    'reference_type' => null,
                    'reference_id' => null,
                ]);

            $metadata = SaleAuditMetadata::forReturnCreated($originalSale->id, $movementIds, $linesWithMovement);
            $metadata['sale_return_id'] = $saleReturn->id;
            $this->logAudit($originalSale->id, SaleAuditLog::EVENT_RETURN_CREATED, null, SaleReturnStatus::Completed->value, null, null, $metadata, $creator->id);

            $this->paymentService->postReturnPosting($originalSale, $saleReturn, $creator);
            if ($saleReturn->customer_id) {
                $this->customerLedgerService->postReturnCredit($saleReturn, $creator);
            }

            return $saleReturn->load(['items.product', 'items.stockMovement', 'sale', 'branch', 'warehouse']);
        });
    }

    private function assignReturnNumber(SaleReturn $saleReturn): void
    {
        $year = $saleReturn->created_at->format('Y');
        $seq = SaleReturn::withoutGlobalScope('company')
            ->where('company_id', $saleReturn->company_id)
            ->whereYear('created_at', $year)
            ->count();
        $number = 'SR-' . $year . '-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
        $saleReturn->update(['return_number' => $number]);
    }

    /**
     * Cancel a sale. Allowed only for draft or pending. Completed sales are immutable; use returns/refunds instead.
     */
    public function cancel(Sale $sale, User $creator): Sale
    {
        if ($sale->status === SaleStatus::Completed) {
            throw new InvalidArgumentException('Completed sales cannot be cancelled. Use returns or refunds instead.');
        }
        if ($sale->status === SaleStatus::Cancelled) {
            throw new InvalidArgumentException('Sale is already cancelled.');
        }
        if (! in_array($sale->status, [SaleStatus::Draft, SaleStatus::Pending], true)) {
            throw new InvalidArgumentException('Only draft or pending sales can be cancelled.');
        }

        $sale->update(['status' => SaleStatus::Cancelled]);

        return $sale->fresh(['lines.product', 'branch', 'warehouse']);
    }

    /**
     * Complete a draft sale: validate stock, create sale_out movements, update status, post accounting.
     */
    public function complete(Sale $sale, User $creator): Sale
    {
        if ($sale->type !== SaleType::Sale) {
            throw new InvalidArgumentException('Only sales can be completed.');
        }
        if ($sale->status !== SaleStatus::Draft) {
            throw new InvalidArgumentException('Only draft sales can be completed.');
        }

        $warehouseId = $sale->warehouse_id;
        $lines = $sale->lines;
        $linesData = $lines->map(fn ($l) => [
            'product_id' => $l->product_id,
            'quantity' => (float) $l->quantity,
            'unit_price' => (float) $l->unit_price,
            'discount' => (float) $l->discount,
        ])->toArray();

        return $this->transactionWithRetry(function () use ($sale, $creator, $warehouseId, $linesData) {
            $productIds = $sale->lines->pluck('product_id')->unique()->values()->all();
            if (! empty($productIds)) {
                StockCache::where('warehouse_id', $warehouseId)->whereIn('product_id', $productIds)->lockForUpdate()->get();
                $this->validateStockForLines($linesData, $warehouseId);
            }

            foreach ($sale->lines as $line) {
                $movement = StockMovement::withoutGlobalScope('company')->create([
                    'product_id' => $line->product_id,
                    'variant_id' => $line->variant_id,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $line->quantity,
                    'type' => StockMovementType::SaleOut,
                    'reference_type' => 'Sale',
                    'reference_id' => $sale->id,
                    'created_by' => $creator->id,
                ]);
                $line->update(['stock_movement_id' => $movement->id]);
            }

            $sale->update([
                'status' => SaleStatus::Completed,
                'payment_status' => SalePaymentStatus::fromPaidAndTotal((float) $sale->paid_amount, (float) $sale->grand_total),
            ]);

            $sale->load(['lines']);
            $metadata = SaleAuditMetadata::forConvertedToSale(
                $sale->lines->pluck('stock_movement_id')->filter()->values()->all(),
                $sale->lines->map(fn ($l) => [
                    'product_id' => $l->product_id,
                    'quantity' => (float) $l->quantity,
                    'stock_movement_id' => $l->stock_movement_id,
                    'variant_id' => $l->variant_id,
                ])->toArray()
            );
            $this->logAudit($sale->id, SaleAuditLog::EVENT_CONVERTED_TO_SALE, SaleStatus::Draft->value, SaleStatus::Completed->value, SaleType::Sale->value, SaleType::Sale->value, $metadata, $creator->id);

            $this->paymentService->postSalePosting($sale->fresh(), $creator);
            if ($sale->customer_id) {
                $this->customerLedgerService->postInvoice($sale->fresh(), $creator);
            }

            return $sale->fresh(['lines.product', 'lines.stockMovement', 'branch', 'warehouse', 'discounts']);
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

    /** @param array<int, array{type?: string, value: float, description?: string}> $discounts */
    private function computeDiscountTotalFromData(array $discounts, float $subtotal): float
    {
        $total = 0.0;
        foreach ($discounts as $d) {
            $value = (float) ($d['value'] ?? 0);
            $type = $d['type'] ?? 'fixed';
            if ($type === 'percentage') {
                $total += $subtotal * ($value / 100);
            } else {
                $total += $value;
            }
        }

        return min($total, $subtotal);
    }

    /** @param array<int, array{type?: string, value: float, description?: string}> $discounts */
    private function createSaleDiscounts(Sale $sale, array $discounts): void
    {
        foreach ($discounts as $d) {
            $type = DiscountType::tryFrom($d['type'] ?? 'manual') ?? DiscountType::Manual;
            SaleDiscount::withoutGlobalScope('company')->create([
                'sale_id' => $sale->id,
                'company_id' => $sale->company_id,
                'type' => $type,
                'value' => (float) ($d['value'] ?? 0),
                'description' => $d['description'] ?? null,
            ]);
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

    /**
     * Merge request lines by (product_id, variant_id): one line per product+variant with summed quantity.
     */
    private function mergeLinesByProductVariant(array $lines): array
    {
        $keyed = [];
        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $variantId = isset($line['variant_id']) ? (int) $line['variant_id'] : null;
            $key = $productId . '_' . ($variantId ?? '');
            if (! isset($keyed[$key])) {
                $keyed[$key] = $line;
                $keyed[$key]['quantity'] = (float) ($line['quantity'] ?? 0);
                $keyed[$key]['discount'] = (float) ($line['discount'] ?? 0);
            } else {
                $keyed[$key]['quantity'] += (float) ($line['quantity'] ?? 0);
                $keyed[$key]['discount'] += (float) ($line['discount'] ?? 0);
            }
        }

        return array_values($keyed);
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
        $resolvedWarehouseId = (int) ($line['warehouse_id'] ?? $warehouseId);

        // Enforce line warehouse belongs to sale's branch (multi-tenant inventory).
        $this->ensureLineWarehouseBelongsToSaleBranch($sale->branch_id, $resolvedWarehouseId);

        $product = Product::withoutGlobalScope('company')
            ->with('bundleComponents')
            ->find($productId);

        if (! $product) {
            throw new InvalidArgumentException("Product id {$productId} not found.");
        }

        // Snapshot cost at sale for profit/margin reporting (product.cost_price or average_cost).
        $costPriceAtSale = (float) ($product->cost_price ?? $product->average_cost ?? 0);

        $stockMovementId = null;
        if ($saleType === SaleType::Sale) {

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
                        'warehouse_id' => $resolvedWarehouseId,
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
                    $this->serialSaleGuard->validateSerialForSale($serialId, $productId, $resolvedWarehouseId);
                }

                // Batch-tracked products: validate batch or allocate FEFO.
                $resolvedBatchId = $batchId;
                if ($product->track_batch) {
                    if ($batchId) {
                        if (! $this->batchAllocationService->validateBatchForMovement($batchId, $productId, $resolvedWarehouseId)) {
                            throw new InvalidArgumentException("Invalid or expired batch id {$batchId} for product id {$productId}.");
                        }
                        $resolvedBatchId = $batchId;
                    } else {
                        $batch = $this->batchAllocationService->getEarliestValidBatch($productId, $resolvedWarehouseId);
                        if (! $batch) {
                            throw new InvalidArgumentException("No valid (non-expired) batch available for product id {$productId} in warehouse {$resolvedWarehouseId}.");
                        }
                        $resolvedBatchId = $batch->id;
                    }
                }

                $movement = StockMovement::withoutGlobalScope('company')->create([
                    'product_id' => $productId,
                    'variant_id' => $variantId ?: null,
                    'warehouse_id' => $resolvedWarehouseId,
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

        $resolvedWarehouseId = (int) ($line['warehouse_id'] ?? $warehouseId);

        $saleLine = SaleLine::withoutGlobalScope('company')->create([
            'sale_id' => $sale->id,
            'company_id' => $sale->company_id,
            'warehouse_id' => $resolvedWarehouseId,
            'product_id' => $productId,
            'product_name_snapshot' => $product->name ?? null,
            'sku_snapshot' => $product->sku ?? null,
            'barcode_snapshot' => $product->barcode ?? null,
            'tax_class_id_snapshot' => $product->tax_class_id ?? null,
            'variant_id' => $variantId ?: null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'cost_price_at_sale' => $costPriceAtSale,
            'line_total' => $lineTotal,
            'discount' => $discount,
            'subtotal' => $subtotal,
            'stock_movement_id' => $stockMovementId,
            'lot_number' => $line['lot_number'] ?? null,
            'imei_id' => $serialId,
        ]);

        if ($saleType === SaleType::Quotation) {
            SaleLineHistory::withoutGlobalScope('company')->create([
                'sale_id' => $sale->id,
                'sale_line_id' => $saleLine->id,
                'company_id' => $sale->company_id,
                'action' => SaleLineHistory::ACTION_ADDED,
                'product_id' => $productId,
                'variant_id' => $variantId ?: null,
                'new_quantity' => $quantity,
                'new_unit_price' => $unitPrice,
                'new_discount' => $discount,
                'changed_by' => $creator->id,
                'changed_at' => now(),
            ]);
        }
    }

    private function ensureLineWarehousesBelongToBranch(int $branchId, array $lines, int $defaultWarehouseId): void
    {
        $warehouseIds = array_unique(array_filter(array_map(function ($line) use ($defaultWarehouseId) {
            $wid = isset($line['warehouse_id']) ? (int) $line['warehouse_id'] : null;
            return $wid > 0 ? $wid : null;
        }, $lines)));
        if (empty($warehouseIds)) {
            return;
        }
        $warehouses = Warehouse::withoutGlobalScope('company')
            ->whereIn('id', $warehouseIds)
            ->get();
        foreach ($warehouses as $warehouse) {
            if ((int) $warehouse->branch_id !== $branchId) {
                throw new InvalidArgumentException("Warehouse id {$warehouse->id} ({$warehouse->name}) does not belong to the sale's branch. Line-level warehouse_id must belong to the same branch as the sale.");
            }
        }
    }

    private function ensureLineWarehouseBelongsToSaleBranch(int $saleBranchId, int $lineWarehouseId): void
    {
        $warehouse = Warehouse::withoutGlobalScope('company')->find($lineWarehouseId);
        if (! $warehouse || (int) $warehouse->branch_id !== $saleBranchId) {
            throw new InvalidArgumentException("Warehouse id {$lineWarehouseId} does not belong to the sale's branch. Line warehouse must satisfy warehouse.branch_id = sale.branch_id.");
        }
    }

    private function assignSaleNumber(Sale $sale): void
    {
        $year = $sale->created_at->format('Y');
        $seq = Sale::withoutGlobalScope('company')
            ->where('company_id', $sale->company_id)
            ->whereYear('created_at', $year)
            ->count();
        $number = 'SAL-'.$year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
        $sale->update(['number' => $number]);
    }

    private function enforcePosRequirements(array $data): void
    {
        if (config('pos.require_session') && empty($data['pos_session_id'])) {
            throw new InvalidArgumentException('pos_session_id is required when POS session enforcement is enabled.');
        }

        if (config('pos.require_device_id') && empty($data['device_id'])) {
            throw new InvalidArgumentException('device_id is required when POS device tracking is enabled.');
        }

        if (! empty($data['pos_session_id'])) {
            $this->ensurePosSessionOpen((int) $data['pos_session_id']);
        }
    }

    private function ensurePosSessionOpen(int $posSessionId): void
    {
        $session = \App\Models\PosSession::withoutGlobalScope('company')->find($posSessionId);
        if (! $session) {
            throw new InvalidArgumentException("POS session id {$posSessionId} not found.");
        }
        if ($session->status !== 'open') {
            throw new InvalidArgumentException("POS session id {$posSessionId} is not open. Only open sessions can accept new transactions.");
        }
    }

    /**
     * Create stock_reservations entries for a quotation, aggregating required quantities per product.
     *
     * @return array<int, int> product_id => reservation.id
     */
    private function createReservationsForQuotation(Sale $sale, array $lines, int $warehouseId): array
    {
        $requirements = $this->buildStockRequirementsForLines($lines);
        $map = [];

        if (empty($requirements)) {
            return $map;
        }

        foreach ($requirements as $productId => $requiredQty) {
            if ($requiredQty <= 0) {
                continue;
            }

            $reservation = StockReservation::create([
                'company_id' => $sale->company_id,
                'product_id' => $productId,
                'variant_id' => null,
                'warehouse_id' => $warehouseId,
                'quantity' => $requiredQty,
                'reference_type' => 'Quotation',
                'reference_id' => $sale->id,
                'status' => 'active',
            ]);

            $map[$productId] = $reservation->id;
        }

        return $map;
    }
}
