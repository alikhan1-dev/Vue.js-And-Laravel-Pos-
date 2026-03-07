<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Central inventory layer. All stock logic flows through here; controllers stay thin.
 * Use for: purchase(), sale(), transfer(), adjustment(), return(), damage(), production().
 */
class InventoryService
{
    public function __construct(
        private TransferService $transferService,
        private SaleService $saleService,
    ) {}

    /**
     * Record purchase receipt (stock received from supplier).
     * referenceType/referenceId optional (e.g. GoodsReceipt, receipt id) for traceability.
     */
    public function purchase(int $productId, int $warehouseId, float $quantity, ?float $unitCost = null, ?int $variantId = null, ?int $batchId = null, ?User $user = null, ?string $referenceType = 'Purchase', ?int $referenceId = null): StockMovement
    {
        return $this->recordInMovement(StockMovementType::PurchaseIn, $productId, $warehouseId, $quantity, $unitCost, $variantId, $batchId, null, $referenceType, $referenceId, $user);
    }

    /**
     * Sale is handled by SaleService (creates sale + lines + movements). Delegate.
     */
    public function sale(array $saleData, User $creator)
    {
        return $this->saleService->create($saleData, $creator);
    }

    /**
     * Inter-warehouse transfer. Uses DB::transaction() and row locking.
     */
    public function transfer(array $input, User $user): array
    {
        return $this->transferService->executeTransfer($input, $user);
    }

    /**
     * Correction increase (e.g. after stock count).
     */
    public function adjustmentIn(int $productId, int $warehouseId, float $quantity, ?float $unitCost = null, ?int $variantId = null, ?int $stockCountId = null, ?string $referenceType = null, ?int $referenceId = null, ?User $user = null): StockMovement
    {
        return $this->recordInMovement(StockMovementType::AdjustmentIn, $productId, $warehouseId, $quantity, $unitCost, $variantId, null, null, $referenceType ?? 'Adjustment', $referenceId ?? $stockCountId, $user, $stockCountId);
    }

    /**
     * Correction decrease (e.g. after stock count).
     */
    public function adjustmentOut(int $productId, int $warehouseId, float $quantity, ?int $variantId = null, ?int $stockCountId = null, ?string $referenceType = null, ?int $referenceId = null, ?User $user = null): StockMovement
    {
        return $this->recordOutMovement(StockMovementType::AdjustmentOut, $productId, $warehouseId, $quantity, $variantId, null, $referenceType ?? 'Adjustment', $referenceId ?? $stockCountId, $user, $stockCountId);
    }

    /**
     * Customer return (goods back into stock).
     */
    public function returnIn(int $productId, int $warehouseId, float $quantity, ?float $unitCost = null, ?int $variantId = null, ?string $referenceType = 'SaleReturn', ?int $referenceId = null, ?User $user = null): StockMovement
    {
        return $this->recordInMovement(StockMovementType::ReturnIn, $productId, $warehouseId, $quantity, $unitCost, $variantId, null, null, $referenceType, $referenceId, $user);
    }

    /**
     * Damaged stock removed. Optionally link damage_report_id.
     */
    public function damage(int $productId, int $warehouseId, float $quantity, ?int $variantId = null, ?int $damageReportId = null, ?string $referenceType = null, ?int $referenceId = null, ?User $user = null): StockMovement
    {
        return $this->recordOutMovement(StockMovementType::DamageOut, $productId, $warehouseId, $quantity, $variantId, null, $referenceType ?? 'DamageReport', $referenceId ?? $damageReportId, $user, null, $damageReportId);
    }

    /**
     * Finished goods into stock (production).
     */
    public function productionIn(int $productId, int $warehouseId, float $quantity, ?float $unitCost = null, ?int $variantId = null, ?string $referenceType = 'Production', ?int $referenceId = null, ?User $user = null): StockMovement
    {
        return $this->recordInMovement(StockMovementType::ProductionIn, $productId, $warehouseId, $quantity, $unitCost, $variantId, null, null, $referenceType, $referenceId, $user);
    }

    /**
     * Raw material consumption (production out).
     */
    public function productionOut(int $productId, int $warehouseId, float $quantity, ?int $variantId = null, ?string $referenceType = 'Production', ?int $referenceId = null, ?User $user = null): StockMovement
    {
        return $this->recordOutMovement(StockMovementType::ProductionOut, $productId, $warehouseId, $quantity, $variantId, null, $referenceType, $referenceId, $user);
    }

    private function recordInMovement(StockMovementType $type, int $productId, int $warehouseId, float $quantity, ?float $unitCost, ?int $variantId, ?int $batchId, ?int $serialId, ?string $referenceType, ?int $referenceId, ?User $user, ?int $stockCountId = null): StockMovement
    {
        $product = Product::withoutGlobalScope('company')->findOrFail($productId);
        $payload = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
            'quantity' => abs($quantity),
            'unit_cost' => $unitCost,
            'type' => $type,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'batch_id' => $batchId,
            'serial_id' => $serialId,
            'stock_count_id' => $stockCountId,
            'created_by' => $user?->id,
        ];
        return StockMovement::withoutGlobalScope('company')->create($payload);
    }

    private function recordOutMovement(StockMovementType $type, int $productId, int $warehouseId, float $quantity, ?int $variantId, ?int $serialId, ?string $referenceType, ?int $referenceId, ?User $user, ?int $stockCountId = null, ?int $damageReportId = null): StockMovement
    {
        $payload = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
            'quantity' => abs($quantity),
            'type' => $type,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'serial_id' => $serialId,
            'stock_count_id' => $stockCountId,
            'damage_report_id' => $damageReportId,
            'created_by' => $user?->id,
        ];
        return StockMovement::withoutGlobalScope('company')->create($payload);
    }
}
