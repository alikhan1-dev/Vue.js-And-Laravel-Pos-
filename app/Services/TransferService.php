<?php

namespace App\Services;

use App\Events\StockTransferCompleted;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\StockCache;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Inter-warehouse transfer. All operations run inside DB::transaction().
 * Prevents crash scenario: transfer_out recorded, transfer_in failed → stock would disappear.
 * Uses SELECT ... FOR UPDATE on stock_cache for concurrency safety.
 */
class TransferService
{
    /**
     * Execute transfer atomically: transfer_out + transfer_in + serial warehouse update.
     * Serial validation before transfer_out: serial must exist, be in source warehouse, and in_stock.
     *
     * @param  array{product_id: int, variant_id?: int, from_warehouse_id: int, to_warehouse_id: int, quantity: float, unit_cost?: float, batch_id?: int, serial_id?: int}  $input
     * @return array{transfer_out: StockMovement, transfer_in: StockMovement}
     */
    public function executeTransfer(array $input, User $user): array
    {
        $product = Product::find($input['product_id']);
        if (! $product) {
            throw new InvalidArgumentException('Product not found or access denied.');
        }

        $fromWarehouse = Warehouse::with('branch')->find($input['from_warehouse_id']);
        $toWarehouse = Warehouse::with('branch')->find($input['to_warehouse_id']);
        if (! $fromWarehouse || $fromWarehouse->branch->company_id !== $user->company_id) {
            throw new InvalidArgumentException('Source warehouse not found or access denied.');
        }
        if (! $toWarehouse || $toWarehouse->branch->company_id !== $user->company_id) {
            throw new InvalidArgumentException('Destination warehouse not found or access denied.');
        }
        if ($fromWarehouse->id === $toWarehouse->id) {
            throw new InvalidArgumentException('Source and destination warehouse must differ.');
        }

        $this->ensureWarehouseNotLocked($input['from_warehouse_id'], $input['to_warehouse_id']);

        if (! empty($input['serial_id'])) {
            $this->validateSerialForTransfer(
                (int) $input['serial_id'],
                $product->id,
                $input['from_warehouse_id']
            );
        }

        return DB::transaction(function () use ($input, $user, $product) {
            $cached = StockCache::where('product_id', $product->id)
                ->where('warehouse_id', $input['from_warehouse_id'])
                ->lockForUpdate()
                ->first();

            $available = $cached ? (float) $cached->available_quantity : 0.0;
            $quantity = (float) $input['quantity'];

            if (! $product->allow_negative_stock && $available < $quantity) {
                throw new InvalidArgumentException("Insufficient stock in source warehouse. Available: {$available}, requested: {$quantity}.");
            }

            $outMovement = StockMovement::withoutGlobalScope('company')->create([
                'product_id' => $product->id,
                'variant_id' => $input['variant_id'] ?? null,
                'warehouse_id' => $input['from_warehouse_id'],
                'quantity' => $quantity,
                'unit_cost' => $input['unit_cost'] ?? null,
                'type' => StockMovementType::TransferOut,
                'reference_type' => 'Transfer',
                'reference_id' => null,
                'batch_id' => $input['batch_id'] ?? null,
                'serial_id' => $input['serial_id'] ?? null,
                'created_by' => $user->id,
            ]);

            $inMovement = StockMovement::withoutGlobalScope('company')->create([
                'product_id' => $product->id,
                'variant_id' => $input['variant_id'] ?? null,
                'warehouse_id' => $input['to_warehouse_id'],
                'quantity' => $quantity,
                'unit_cost' => $input['unit_cost'] ?? null,
                'type' => StockMovementType::TransferIn,
                'reference_type' => 'Transfer',
                'reference_id' => $outMovement->id,
                'batch_id' => $input['batch_id'] ?? null,
                'serial_id' => $input['serial_id'] ?? null,
                'created_by' => $user->id,
            ]);

            DB::table('stock_movements')
                ->where('id', $outMovement->id)
                ->update(['reference_id' => $inMovement->id]);

            if (! empty($input['serial_id'])) {
                ProductSerial::where('id', $input['serial_id'])
                    ->update(['warehouse_id' => $input['to_warehouse_id']]);
            }

            StockTransferCompleted::dispatch($outMovement->fresh(), $inMovement->fresh());

            return [
                'transfer_out' => $outMovement->fresh(),
                'transfer_in' => $inMovement->fresh(),
            ];
        });
    }

    /**
     * Before transfer_out: serial must exist, be in source warehouse, and status = in_stock.
     */
    private function validateSerialForTransfer(int $serialId, int $productId, int $fromWarehouseId): void
    {
        $serial = ProductSerial::find($serialId);
        if (! $serial) {
            throw new InvalidArgumentException("Serial id {$serialId} not found.");
        }
        if ($serial->product_id !== $productId) {
            throw new InvalidArgumentException('Serial does not belong to this product.');
        }
        if ((int) $serial->warehouse_id !== $fromWarehouseId) {
            throw new InvalidArgumentException('Serial must be in the source warehouse before transfer.');
        }
        if ($serial->status !== 'in_stock') {
            throw new InvalidArgumentException("Serial is not available for transfer (status: {$serial->status}).");
        }
    }

    private function ensureWarehouseNotLocked(int $fromWarehouseId, int $toWarehouseId): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('warehouse_locks')) {
            return;
        }
        $now = now();
        $locked = WarehouseLock::whereIn('warehouse_id', [$fromWarehouseId, $toWarehouseId])
            ->where('locked_at', '<=', $now)
            ->where(function ($q) use ($now): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->exists();
        if ($locked) {
            throw new InvalidArgumentException('One or both warehouses are locked for inventory (e.g. stock count or audit). Transfers not allowed.');
        }
    }
}
