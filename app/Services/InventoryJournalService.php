<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\InventoryJournalEntry;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Posts inventory movements to the inventory_journal (double-entry style)
 * for accounting integration. Debit/Credit legs follow standard patterns.
 */
class InventoryJournalService
{
    /**
     * Post journal entries for a stock movement. Call from observer or after movement is saved.
     * Skips if inventory_journal table does not exist or movement type is not mapped.
     */
    public function postFromMovement(StockMovement $movement): void
    {
        if (! Schema::hasTable('inventory_journal')) {
            return;
        }

        $type = $movement->type instanceof StockMovementType
            ? $movement->type
            : StockMovementType::tryFrom($movement->type);

        if (! $type) {
            return;
        }

        $date = $movement->movement_date?->toDateString() ?? $movement->created_at->toDateString();
        $qty = abs((float) $movement->quantity);
        $unitCost = (float) ($movement->unit_cost ?? 0);
        $amount = $qty * $unitCost;

        if ($amount <= 0) {
            return;
        }

        $companyId = $movement->company_id;
        $productId = $movement->product_id;
        $warehouseId = $movement->warehouse_id;
        $refType = $movement->reference_type;
        $refId = $movement->reference_id;
        $notes = "Movement #{$movement->id} ({$type->value})";

        $entries = $this->entriesForType($type, $amount, $date, $companyId, $movement->id, $productId, $warehouseId, $refType, $refId, $notes);

        if ($entries !== []) {
            DB::transaction(function () use ($entries): void {
                foreach ($entries as $row) {
                    InventoryJournalEntry::create($row);
                }
            });
        }
    }

    /**
     * Returns array of journal entry rows for the given movement type and amount.
     * Each row: journal_date, company_id, stock_movement_id, entry_type, account_type, debit_amount, credit_amount, product_id, warehouse_id, reference_type, reference_id, notes.
     */
    private function entriesForType(
        StockMovementType $type,
        float $amount,
        string $date,
        ?int $companyId,
        ?int $movementId,
        ?int $productId,
        ?int $warehouseId,
        ?string $refType,
        ?int $refId,
        string $notes
    ): array {
        return match ($type) {
            StockMovementType::PurchaseIn, StockMovementType::ReturnIn, StockMovementType::ProductionIn, StockMovementType::InitialStock => [
                array_merge($this->baseEntry($date, $companyId, $movementId, $type->value, $productId, $warehouseId, $refType, $refId, $notes), [
                    'account_type' => 'inventory',
                    'debit_amount' => $amount,
                    'credit_amount' => 0,
                ]),
                array_merge($this->baseEntry($date, $companyId, $movementId, $type->value, $productId, $warehouseId, $refType, $refId, $notes), [
                    'account_type' => 'accounts_payable',
                    'debit_amount' => 0,
                    'credit_amount' => $amount,
                ]),
            ],
            StockMovementType::SaleOut => [
                array_merge($this->baseEntry($date, $companyId, $movementId, $type->value, $productId, $warehouseId, $refType, $refId, $notes), [
                    'account_type' => 'cogs',
                    'debit_amount' => $amount,
                    'credit_amount' => 0,
                ]),
                array_merge($this->baseEntry($date, $companyId, $movementId, $type->value, $productId, $warehouseId, $refType, $refId, $notes), [
                    'account_type' => 'inventory',
                    'debit_amount' => 0,
                    'credit_amount' => $amount,
                ]),
            ],
            StockMovementType::AdjustmentIn => [
                array_merge($this->baseEntry($date, $companyId, $movementId, $type->value, $productId, $warehouseId, $refType, $refId, $notes), [
                    'account_type' => 'inventory',
                    'debit_amount' => $amount,
                    'credit_amount' => 0,
                ]),
                array_merge($this->baseEntry($date, $companyId, $movementId, $type->value, $productId, $warehouseId, $refType, $refId, $notes), [
                    'account_type' => 'inventory_adjustment',
                    'debit_amount' => 0,
                    'credit_amount' => $amount,
                ]),
            ],
            StockMovementType::AdjustmentOut, StockMovementType::DamageOut, StockMovementType::ReturnOut => [
                array_merge($this->baseEntry($date, $companyId, $movementId, $type->value, $productId, $warehouseId, $refType, $refId, $notes), [
                    'account_type' => 'inventory_adjustment',
                    'debit_amount' => $amount,
                    'credit_amount' => 0,
                ]),
                array_merge($this->baseEntry($date, $companyId, $movementId, $type->value, $productId, $warehouseId, $refType, $refId, $notes), [
                    'account_type' => 'inventory',
                    'debit_amount' => 0,
                    'credit_amount' => $amount,
                ]),
            ],
            default => [],
        };
    }

    private function baseEntry(
        string $date,
        ?int $companyId,
        ?int $movementId,
        string $entryType,
        ?int $productId,
        ?int $warehouseId,
        ?string $refType,
        ?int $refId,
        string $notes
    ): array {
        return [
            'company_id' => $companyId,
            'stock_movement_id' => $movementId,
            'journal_date' => $date,
            'entry_type' => $entryType,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'notes' => $notes,
        ];
    }
}
