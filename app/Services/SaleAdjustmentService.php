<?php

namespace App\Services;

use App\Enums\SaleAdjustmentType;
use App\Enums\SaleStatus;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Sale;
use App\Models\SaleAdjustment;
use App\Models\SaleAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Service for creating and approving sale adjustments on completed (immutable) sales.
 *
 * Workflow: create (pending) → approve (posted + journal entry).
 * Only users with admin/adjustment permissions should call approve().
 */
class SaleAdjustmentService
{
    public function create(Sale $sale, array $data, User $creator): SaleAdjustment
    {
        if ($sale->status !== SaleStatus::Completed) {
            throw new InvalidArgumentException('Adjustments can only be created for completed sales.');
        }

        $type = SaleAdjustmentType::from($data['type']);
        $amount = (float) $data['amount'];
        $reason = $data['reason'];

        if ($amount <= 0) {
            throw new InvalidArgumentException('Adjustment amount must be positive.');
        }

        $adjustment = SaleAdjustment::withoutGlobalScope('company')->create([
            'company_id' => $creator->company_id,
            'sale_id' => $sale->id,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'status' => 'pending',
            'metadata' => $data['metadata'] ?? null,
            'created_by' => $creator->id,
        ]);

        $this->assignAdjustmentNumber($adjustment);

        SaleAuditLog::create([
            'sale_id' => $sale->id,
            'event' => SaleAuditLog::EVENT_ADJUSTMENT_CREATED,
            'from_status' => $sale->status->value,
            'to_status' => $sale->status->value,
            'metadata' => [
                'adjustment_id' => $adjustment->id,
                'type' => $type->value,
                'amount' => $amount,
                'reason' => $reason,
            ],
            'created_by' => $creator->id,
        ]);

        return $adjustment->load('sale', 'creator');
    }

    public function approve(SaleAdjustment $adjustment, User $approver): SaleAdjustment
    {
        if ($adjustment->status !== 'pending') {
            throw new InvalidArgumentException('Only pending adjustments can be approved.');
        }

        if ($adjustment->created_by === $approver->id) {
            throw new InvalidArgumentException('The creator of an adjustment cannot approve it (four-eyes principle).');
        }

        return DB::transaction(function () use ($adjustment, $approver) {
            $sale = Sale::withoutGlobalScope('company')->findOrFail($adjustment->sale_id);
            $companyId = $adjustment->company_id;

            $receivable = Account::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('code', '1100')
                ->where('is_active', true)
                ->firstOrFail();

            $salesRevenue = Account::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('code', '4000')
                ->where('is_active', true)
                ->firstOrFail();

            $entry = JournalEntry::withoutGlobalScope('company')->create([
                'company_id' => $companyId,
                'branch_id' => $sale->branch_id,
                'reference_type' => 'SaleAdjustment',
                'reference_id' => $adjustment->id,
                'reference_number' => $adjustment->adjustment_number,
                'entry_type' => 'adjustment',
                'created_by' => $approver->id,
                'is_locked' => true,
                'posted_at' => now(),
            ]);

            JournalEntryLine::insert([
                [
                    'journal_entry_id' => $entry->id,
                    'account_id' => $salesRevenue->id,
                    'type' => 'debit',
                    'amount' => $adjustment->amount,
                    'description' => "Adj {$adjustment->adjustment_number}: {$adjustment->reason}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'journal_entry_id' => $entry->id,
                    'account_id' => $receivable->id,
                    'type' => 'credit',
                    'amount' => $adjustment->amount,
                    'description' => "Adj {$adjustment->adjustment_number}: {$adjustment->reason}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $adjustment->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'journal_entry_id' => $entry->id,
            ]);

            SaleAuditLog::create([
                'sale_id' => $sale->id,
                'event' => SaleAuditLog::EVENT_ADJUSTMENT_APPROVED,
                'from_status' => $sale->status->value,
                'to_status' => $sale->status->value,
                'metadata' => [
                    'adjustment_id' => $adjustment->id,
                    'journal_entry_id' => $entry->id,
                    'approved_by' => $approver->id,
                ],
                'created_by' => $approver->id,
            ]);

            return $adjustment->load('sale', 'creator', 'approver', 'journalEntry');
        });
    }

    private function assignAdjustmentNumber(SaleAdjustment $adjustment): void
    {
        $year = now()->format('Y');
        $seq = SaleAdjustment::withoutGlobalScope('company')
            ->where('company_id', $adjustment->company_id)
            ->whereYear('created_at', $year)
            ->count();
        $number = 'ADJ-' . $year . '-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
        $adjustment->update(['adjustment_number' => $number]);
    }
}
