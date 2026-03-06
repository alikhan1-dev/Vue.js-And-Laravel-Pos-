<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentService
{
    private const AMOUNT_MIN = 0.01;
    private const AMOUNT_MAX = 999_999_999.99;

    /**
     * Create payment with lines. Validates sale (if present), amounts, and accounts.
     * Only status=completed posts journal entries and updates account balances.
     */
    public function create(array $data, User $creator): Payment
    {
        $saleId = isset($data['sale_id']) ? (int) $data['sale_id'] : null;
        $branchId = (int) $data['branch_id'];
        $warehouseId = ! empty($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;
        $lines = $data['lines'] ?? [];
        $status = isset($data['status']) ? PaymentStatus::from($data['status']) : PaymentStatus::Completed;

        $this->ensureBranchBelongsToCompany($creator->company_id, $branchId);
        if ($warehouseId) {
            $this->ensureWarehouseBelongsToCompany($creator->company_id, $warehouseId);
        }

        $totalAmount = 0;
        foreach ($lines as $line) {
            $amt = (float) ($line['amount'] ?? 0);
            $this->validateAmount($amt);
            $totalAmount += $amt;
        }
        if ($totalAmount < self::AMOUNT_MIN) {
            throw new InvalidArgumentException('Payment total must be at least ' . self::AMOUNT_MIN . '.');
        }

        return DB::transaction(function () use ($data, $creator, $saleId, $branchId, $warehouseId, $lines, $totalAmount, $status) {
            $sale = null;
            if ($saleId) {
                $sale = Sale::withoutGlobalScope('company')
                    ->where('company_id', $creator->company_id)
                    ->lockForUpdate()
                    ->findOrFail($saleId);
                $alreadyPaid = $sale->payments()->where('status', PaymentStatus::Completed)->sum('amount');
                $remaining = (float) $sale->total - (float) $alreadyPaid;
                if ($totalAmount > $remaining) {
                    throw new InvalidArgumentException('Payment total (' . $totalAmount . ') exceeds remaining due (' . $remaining . ').');
                }
            }
            $payment = Payment::withoutGlobalScope('company')->create([
                'sale_id' => $saleId,
                'company_id' => $creator->company_id,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'amount' => $totalAmount,
                'status' => $status,
                'created_by' => $creator->id,
            ]);

            $receivableAccount = $this->getAccountsReceivableAccount($creator->company_id);

            foreach ($lines as $line) {
                $paymentMethodId = (int) $line['payment_method_id'];
                $accountId = (int) $line['account_id'];
                $amount = (float) ($line['amount'] ?? 0);
                $reference = $line['reference'] ?? null;
                $description = $line['description'] ?? null;

                $account = Account::withoutGlobalScope('company')->where('company_id', $creator->company_id)
                    ->where('id', $accountId)->where('is_active', true)->firstOrFail();
                $paymentMethod = PaymentMethod::withoutGlobalScope('company')
                    ->where('company_id', $creator->company_id)
                    ->where('id', $paymentMethodId)
                    ->where('is_active', true)
                    ->firstOrFail();

                PaymentLine::withoutGlobalScope('company')->create([
                    'payment_id' => $payment->id,
                    'payment_method_id' => $paymentMethod->id,
                    'account_id' => $account->id,
                    'amount' => $amount,
                    'reference' => $reference,
                    'description' => $description,
                ]);

                if ($status === PaymentStatus::Completed) {
                    // One journal entry per payment (header) with multiple lines.
                    // We build lines after the loop, outside.
                }
            }

            if ($status === PaymentStatus::Completed && $totalAmount > 0) {
                $this->createBalancedJournalEntry(
                    companyId: $creator->company_id,
                    referenceType: JournalEntry::REFERENCE_TYPE_PAYMENT,
                    referenceId: $payment->id,
                    entryType: JournalEntry::ENTRY_TYPE_PAYMENT_RECEIPT,
                    createdBy: $creator->id,
                    debitLines: array_map(fn ($line) => [
                        'account_id' => (int) $line['account_id'],
                        'amount' => (float) $line['amount'],
                        'description' => $line['description'] ?? null,
                    ], $lines),
                    creditLines: [[
                        'account_id' => $receivableAccount->id,
                        'amount' => $totalAmount,
                        'description' => 'Payment for sale #' . ($sale?->id ?? 'N/A'),
                    ]],
                );
            }

            return $payment->load([
                'lines.account',
                'lines.paymentMethod',
                'journalEntries.lines.account',
                'sale',
                'branch',
                'warehouse',
                'creator',
            ]);
        });
    }

    /**
     * Refund: create a new payment with negative amount and reverse journal entries.
     * Refund amount is applied: Debit Sales Returns, Credit the given account (e.g. Cash).
     */
    public function refund(Payment $payment, array $data, User $creator): Payment
    {
        $amount = (float) ($data['amount'] ?? 0);
        $accountId = (int) ($data['account_id'] ?? 0);
        if ($amount < self::AMOUNT_MIN || $amount > self::AMOUNT_MAX) {
            throw new InvalidArgumentException('Refund amount must be between ' . self::AMOUNT_MIN . ' and ' . self::AMOUNT_MAX . '.');
        }

        $payment->load('company');
        $companyId = $payment->company_id;
        $account = Account::withoutGlobalScope('company')->where('company_id', $companyId)
            ->where('id', $accountId)->where('is_active', true)->firstOrFail();
        $salesReturnsAccount = $this->getSalesReturnsAccount($companyId);

        return DB::transaction(function () use ($payment, $amount, $account, $salesReturnsAccount, $creator) {
            $refundPayment = Payment::withoutGlobalScope('company')->create([
                'sale_id' => $payment->sale_id,
                'company_id' => $payment->company_id,
                'branch_id' => $payment->branch_id,
                'warehouse_id' => $payment->warehouse_id,
                'amount' => -$amount,
                'status' => PaymentStatus::Refunded,
                'created_by' => $creator->id,
            ]);

            // We treat refund as adjustment entry: Dr Sales Returns, Cr Cash/Bank
            $this->createBalancedJournalEntry(
                companyId: $payment->company_id,
                referenceType: JournalEntry::REFERENCE_TYPE_PAYMENT,
                referenceId: $refundPayment->id,
                entryType: JournalEntry::ENTRY_TYPE_REFUND,
                createdBy: $creator->id,
                debitLines: [[
                    'account_id' => $salesReturnsAccount->id,
                    'amount' => $amount,
                    'description' => 'Refund for payment #' . $payment->id,
                ]],
                creditLines: [[
                    'account_id' => $account->id,
                    'amount' => $amount,
                    'description' => 'Refund for payment #' . $payment->id,
                ]],
            );

            return $refundPayment->load([
                'lines.account',
                'journalEntries.lines.account',
                'sale',
                'branch',
                'creator',
            ]);
        });
    }

    private function ensureBranchBelongsToCompany(int $companyId, int $branchId): void
    {
        $exists = \App\Models\Branch::where('company_id', $companyId)->where('id', $branchId)->exists();
        if (! $exists) {
            throw new InvalidArgumentException('Branch does not belong to your company.');
        }
    }

    private function ensureWarehouseBelongsToCompany(int $companyId, int $warehouseId): void
    {
        $exists = \App\Models\Warehouse::whereHas('branch', fn ($q) => $q->where('company_id', $companyId))
            ->where('id', $warehouseId)->exists();
        if (! $exists) {
            throw new InvalidArgumentException('Warehouse does not belong to your company.');
        }
    }

    private function validateAmount(float $amount): void
    {
        if ($amount < self::AMOUNT_MIN || $amount > self::AMOUNT_MAX) {
            throw new InvalidArgumentException('Amount must be between ' . self::AMOUNT_MIN . ' and ' . self::AMOUNT_MAX . '.');
        }
    }

    private function getAccountsReceivableAccount(int $companyId): Account
    {
        $account = Account::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('code', '1100')
            ->where('is_active', true)
            ->first();
        if (! $account) {
            throw new InvalidArgumentException('Accounts Receivable account (code 1100) not found. Please run seeders.');
        }
        return $account;
    }

    private function getSalesReturnsAccount(int $companyId): Account
    {
        $account = Account::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('code', '5000')
            ->where('is_active', true)
            ->first();
        if (! $account) {
            throw new InvalidArgumentException('Sales Returns account (code 5000) not found. Please run seeders.');
        }
        return $account;
    }

    private function getSalesIncomeAccount(int $companyId): Account
    {
        $account = Account::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('code', '4000')
            ->where('is_active', true)
            ->first();
        if (! $account) {
            throw new InvalidArgumentException('Sales Income account (code 4000) not found. Please run seeders.');
        }
        return $account;
    }

    /**
     * Post accrual for a completed sale: Dr Accounts Receivable, Cr Sales Revenue.
     */
    public function postSalePosting(Sale $sale, User $creator): void
    {
        $receivable = $this->getAccountsReceivableAccount($sale->company_id);
        $salesRevenue = $this->getSalesIncomeAccount($sale->company_id);

        $this->createBalancedJournalEntry(
            companyId: $sale->company_id,
            referenceType: JournalEntry::REFERENCE_TYPE_SALE,
            referenceId: $sale->id,
            entryType: JournalEntry::ENTRY_TYPE_SALE_POSTING,
            createdBy: $creator->id,
            debitLines: [[
                'account_id' => $receivable->id,
                'amount' => (float) $sale->total,
                'description' => 'Sale posting for sale #' . $sale->id,
            ]],
            creditLines: [[
                'account_id' => $salesRevenue->id,
                'amount' => (float) $sale->total,
                'description' => 'Sale posting for sale #' . $sale->id,
            ]],
        );
    }

    /**
     * Post accrual for goods returned (before any cash refund): Dr Sales Returns, Cr Accounts Receivable.
     */
    public function postReturnPosting(Sale $originalSale, Sale $returnSale, User $creator): void
    {
        $receivable = $this->getAccountsReceivableAccount($originalSale->company_id);
        $salesReturns = $this->getSalesReturnsAccount($originalSale->company_id);

        $this->createBalancedJournalEntry(
            companyId: $originalSale->company_id,
            referenceType: JournalEntry::REFERENCE_TYPE_SALE,
            referenceId: $returnSale->id,
            entryType: JournalEntry::ENTRY_TYPE_REFUND,
            createdBy: $creator->id,
            debitLines: [[
                'account_id' => $salesReturns->id,
                'amount' => (float) $returnSale->total,
                'description' => 'Return posting for sale #' . $originalSale->id,
            ]],
            creditLines: [[
                'account_id' => $receivable->id,
                'amount' => (float) $returnSale->total,
                'description' => 'Return posting for sale #' . $originalSale->id,
            ]],
        );
    }

    /**
     * Create a balanced journal entry with separate debit and credit lines.
     * Throws if debits != credits.
     */
    private function createBalancedJournalEntry(
        int $companyId,
        string $referenceType,
        int $referenceId,
        string $entryType,
        ?int $createdBy,
        array $debitLines,
        array $creditLines,
    ): void {
        $totalDebit = array_reduce($debitLines, fn ($carry, $line) => $carry + (float) $line['amount'], 0.0);
        $totalCredit = array_reduce($creditLines, fn ($carry, $line) => $carry + (float) $line['amount'], 0.0);

        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            throw new InvalidArgumentException('Journal entry not balanced (debits != credits).');
        }

        $entry = JournalEntry::withoutGlobalScope('company')->create([
            'company_id' => $companyId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'entry_type' => $entryType,
            'created_by' => $createdBy,
            'is_locked' => true,
            'posted_at' => now(),
        ]);

        $lines = [];
        foreach ($debitLines as $line) {
            $lines[] = [
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account_id'],
                'type' => 'debit',
                'amount' => $line['amount'],
                'description' => $line['description'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach ($creditLines as $line) {
            $lines[] = [
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account_id'],
                'type' => 'credit',
                'amount' => $line['amount'],
                'description' => $line['description'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        JournalEntryLine::insert($lines);
    }
}
