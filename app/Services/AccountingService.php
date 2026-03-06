<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Sale;
use App\Models\User;
use InvalidArgumentException;

class AccountingService
{
    /**
     * Central point to create balanced journal entries.
     * debitLines/creditLines: [account_id, amount, description?, customer_id?, supplier_id?]
     */
    public function createBalancedJournalEntry(
        int $companyId,
        string $referenceType,
        int $referenceId,
        string $entryType,
        ?int $createdBy,
        array $debitLines,
        array $creditLines,
        ?int $currencyId = null,
        string $status = 'posted',
    ): JournalEntry {
        $totalDebit = array_reduce($debitLines, fn ($carry, $line) => $carry + (float) $line['amount'], 0.0);
        $totalCredit = array_reduce($creditLines, fn ($carry, $line) => $carry + (float) $line['amount'], 0.0);

        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            throw new InvalidArgumentException('Journal entry not balanced (debits != credits).');
        }

        // Ensure accounts exist (early fail)
        foreach (array_merge($debitLines, $creditLines) as $line) {
            Account::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->findOrFail($line['account_id']);
        }

        $entry = JournalEntry::withoutGlobalScope('company')->create([
            'company_id' => $companyId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'entry_type' => $entryType,
            'status' => $status,
            'currency_id' => $currencyId,
            'created_by' => $createdBy,
            'posted_at' => $status === 'posted' ? now() : null,
            'is_locked' => $status === 'posted',
        ]);

        $rows = [];
        $now = now();
        foreach ($debitLines as $line) {
            $rows[] = [
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account_id'],
                'type' => 'debit',
                'amount' => $line['amount'],
                'description' => $line['description'] ?? null,
                'customer_id' => $line['customer_id'] ?? null,
                'supplier_id' => $line['supplier_id'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach ($creditLines as $line) {
            $rows[] = [
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account_id'],
                'type' => 'credit',
                'amount' => $line['amount'],
                'description' => $line['description'] ?? null,
                'customer_id' => $line['customer_id'] ?? null,
                'supplier_id' => $line['supplier_id'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        JournalEntryLine::insert($rows);

        if ($createdBy) {
            AuditLog::create([
                'user_id' => $createdBy,
                'action' => 'journal_entry_posted',
                'entity_type' => JournalEntry::class,
                'entity_id' => $entry->id,
                'old_values' => null,
                'new_values' => ['entry_type' => $entryType, 'reference_type' => $referenceType, 'reference_id' => $referenceId],
            ]);
        }

        return $entry;
    }

    /**
     * Accrual for completed sale: Dr AR, Cr Sales Revenue.
     */
    public function postSale(Sale $sale, User $user, Account $receivable, Account $salesRevenue, ?int $currencyId = null): void
    {
        $this->createBalancedJournalEntry(
            companyId: $sale->company_id,
            referenceType: JournalEntry::REFERENCE_TYPE_SALE,
            referenceId: $sale->id,
            entryType: JournalEntry::ENTRY_TYPE_SALE_POSTING,
            createdBy: $user->id,
            debitLines: [[
                'account_id' => $receivable->id,
                'amount' => (float) $sale->total,
                'description' => 'Sale posting for sale #' . $sale->id,
                'customer_id' => $sale->customer_id,
            ]],
            creditLines: [[
                'account_id' => $salesRevenue->id,
                'amount' => (float) $sale->total,
                'description' => 'Sale posting for sale #' . $sale->id,
                'customer_id' => $sale->customer_id,
            ]],
            currencyId: $currencyId,
        );
    }

    /**
     * Returns before refund: Dr Sales Returns, Cr AR.
     */
    public function postSaleReturn(Sale $originalSale, Sale $returnSale, User $user, Account $receivable, Account $salesReturns, ?int $currencyId = null): void
    {
        $this->createBalancedJournalEntry(
            companyId: $originalSale->company_id,
            referenceType: JournalEntry::REFERENCE_TYPE_SALE,
            referenceId: $returnSale->id,
            entryType: JournalEntry::ENTRY_TYPE_REFUND,
            createdBy: $user->id,
            debitLines: [[
                'account_id' => $salesReturns->id,
                'amount' => (float) $returnSale->total,
                'description' => 'Return posting for sale #' . $originalSale->id,
                'customer_id' => $originalSale->customer_id,
            ]],
            creditLines: [[
                'account_id' => $receivable->id,
                'amount' => (float) $returnSale->total,
                'description' => 'Return posting for sale #' . $originalSale->id,
                'customer_id' => $originalSale->customer_id,
            ]],
            currencyId: $currencyId,
        );
    }
}

