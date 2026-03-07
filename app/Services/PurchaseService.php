<?php

namespace App\Services;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Enums\SupplierPaymentStatus;
use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PurchaseService
{
    /**
     * Create purchase order with lines.
     */
    public function createPurchase(array $data, User $creator): Purchase
    {
        $supplierId = (int) $data['supplier_id'];
        $branchId = (int) $data['branch_id'];
        $warehouseId = (int) $data['warehouse_id'];
        $lines = $data['lines'] ?? [];

        $this->ensureBranchBelongsToCompany($creator->company_id, $branchId);
        $this->ensureWarehouseBelongsToCompany($creator->company_id, $warehouseId);

        $supplier = Supplier::withoutGlobalScope('company')
            ->where('company_id', $creator->company_id)
            ->where('id', $supplierId)
            ->where('is_active', true)
            ->firstOrFail();

        $warehouse = Warehouse::withoutGlobalScope('company')
            ->whereHas('branch', fn ($q) => $q->where('company_id', $creator->company_id))
            ->findOrFail($warehouseId);

        if (empty($lines)) {
            throw new InvalidArgumentException('Purchase must have at least one line.');
        }

        return DB::transaction(function () use ($data, $creator, $supplierId, $branchId, $warehouseId, $lines) {
            $total = 0;
            $taxTotal = 0;
            $discountTotal = 0;

            $purchase = Purchase::withoutGlobalScope('company')->create([
                'company_id' => $creator->company_id,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'supplier_id' => $supplierId,
                'status' => PurchaseStatus::Draft,
                'currency_id' => $data['currency_id'] ?? null,
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'notes' => $data['notes'] ?? null,
                'purchase_date' => $data['purchase_date'] ?? now()->toDateString(),
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'created_by' => $creator->id,
            ]);

            foreach ($lines as $line) {
                $productId = (int) $line['product_id'];
                $quantity = (float) ($line['quantity'] ?? 0);
                $unitCost = (float) ($line['unit_cost'] ?? 0);
                $discount = (float) ($line['discount'] ?? 0);

                if ($quantity <= 0 || $unitCost < 0) {
                    throw new InvalidArgumentException('Invalid quantity or unit_cost on line.');
                }

                $product = Product::withoutGlobalScope('company')
                    ->where('company_id', $creator->company_id)
                    ->findOrFail($productId);

                $subtotal = $quantity * $unitCost - $discount;
                $total += $subtotal;
                $discountTotal += $discount;

                PurchaseLine::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'discount' => $discount,
                    'subtotal' => $subtotal,
                ]);
            }

            $purchase->update([
                'total' => $total,
                'tax_total' => $taxTotal,
                'discount_total' => $discountTotal,
            ]);

            return $purchase->load(['lines.product', 'supplier', 'branch', 'warehouse']);
        });
    }

    /**
     * Confirm purchase order (draft → confirmed). Use before sending to supplier.
     */
    public function confirmPurchase(Purchase $purchase, User $creator): void
    {
        if ($purchase->status !== PurchaseStatus::Draft) {
            throw new InvalidArgumentException('Only draft purchases can be confirmed.');
        }
        $purchase->update(['status' => PurchaseStatus::Confirmed]);
    }

    /**
     * Mark purchase as ordered (confirmed → ordered). Use when order is sent to supplier.
     */
    public function markOrdered(Purchase $purchase, User $creator): void
    {
        if (! in_array($purchase->status, [PurchaseStatus::Draft, PurchaseStatus::Confirmed], true)) {
            throw new InvalidArgumentException('Only draft or confirmed purchases can be marked ordered.');
        }
        $purchase->update(['status' => PurchaseStatus::Ordered]);
    }

    /**
     * Receive goods against a purchase. Creates goods receipt (posted), stock movements
     * (Dr Inventory Cr GRNI), syncs line received_status, and updates purchase status.
     *
     * Warehouse mismatch is validated: receipt warehouse must match purchase warehouse.
     */
    public function receiveGoods(Purchase $purchase, array $lines, User $creator, ?int $receivedBy = null): GoodsReceipt
    {
        if ($purchase->status === PurchaseStatus::Cancelled) {
            throw new InvalidArgumentException('Cannot receive goods for a cancelled purchase.');
        }

        $warehouseId = (int) $purchase->warehouse_id;
        $branchId = (int) $purchase->branch_id;

        $this->ensureWarehouseBelongsToCompany($creator->company_id, $warehouseId);

        return DB::transaction(function () use ($purchase, $lines, $creator, $warehouseId, $branchId, $receivedBy) {
            $receipt = GoodsReceipt::withoutGlobalScope('company')->create([
                'company_id' => $purchase->company_id,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'purchase_id' => $purchase->id,
                'status' => GoodsReceiptStatus::Posted,
                'received_at' => now(),
                'created_by' => $creator->id,
                'received_by' => $receivedBy,
            ]);

            $inventoryService = app(InventoryService::class);
            $receiptTotalValue = 0.0;

            foreach ($lines as $lineData) {
                $purchaseLineId = (int) $lineData['purchase_line_id'];
                $quantityReceived = (float) ($lineData['quantity_received'] ?? 0);
                $unitCostOverride = isset($lineData['unit_cost']) ? (float) $lineData['unit_cost'] : null;

                if ($quantityReceived <= 0) {
                    continue;
                }

                $purchaseLine = PurchaseLine::where('purchase_id', $purchase->id)->findOrFail($purchaseLineId);
                $remaining = (float) $purchaseLine->quantity - (float) $purchaseLine->received_quantity;

                if ($quantityReceived > $remaining) {
                    throw new InvalidArgumentException(
                        "Quantity received ({$quantityReceived}) exceeds remaining ({$remaining}) for line #{$purchaseLineId}."
                    );
                }

                $unitCost = $unitCostOverride ?? (float) $purchaseLine->unit_cost;
                $lineValue = $quantityReceived * $unitCost;
                $receiptTotalValue += $lineValue;

                GoodsReceiptLine::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_line_id' => $purchaseLineId,
                    'product_id' => $purchaseLine->product_id,
                    'quantity_received' => $quantityReceived,
                    'unit_cost' => $unitCost,
                ]);

                $purchaseLine->increment('received_quantity', $quantityReceived);

                $inventoryService->purchase(
                    $purchaseLine->product_id,
                    $warehouseId,
                    $quantityReceived,
                    $unitCost,
                    null,
                    null,
                    $creator,
                    'GoodsReceipt',
                    $receipt->id
                );

                $purchaseLine->refresh();
                $purchaseLine->syncReceivedStatus();
            }

            if ($receiptTotalValue > 0) {
                $this->postGoodsReceiptEntry($purchase->company_id, $branchId, $receipt->id, $receipt->receipt_number, $receiptTotalValue, $creator->id);
            }

            $purchase->refresh();
            $allReceived = $purchase->lines()->where(function ($q) {
                $q->whereColumn('received_quantity', '<', 'quantity');
            })->doesntExist();

            $purchase->update([
                'status' => $allReceived ? PurchaseStatus::Received : PurchaseStatus::PartiallyReceived,
            ]);

            return $receipt->load(['lines.purchaseLine.product', 'purchase']);
        });
    }

    /**
     * Post goods receipt: Dr Inventory (1200), Cr GRNI (2100). 3-way matching support.
     */
    private function postGoodsReceiptEntry(int $companyId, int $branchId, int $receiptId, string $receiptNumber, float $amount, int $createdBy): void
    {
        $inventoryAccount = $this->getInventoryAccount($companyId);
        $grniAccount = $this->getGrniAccount($companyId);

        $this->createBalancedJournalEntry(
            companyId: $companyId,
            branchId: $branchId,
            referenceType: JournalEntry::REFERENCE_TYPE_GOODS_RECEIPT,
            referenceId: $receiptId,
            referenceNumber: $receiptNumber,
            entryType: JournalEntry::ENTRY_TYPE_GOODS_RECEIPT,
            createdBy: $createdBy,
            debitLines: [[
                'account_id' => $inventoryAccount->id,
                'amount' => $amount,
                'description' => 'Goods receipt ' . $receiptNumber,
            ]],
            creditLines: [[
                'account_id' => $grniAccount->id,
                'amount' => $amount,
                'description' => 'Goods receipt ' . $receiptNumber,
            ]],
        );
    }

    /**
     * Create supplier invoice (optionally linked to purchase).
     */
    public function createSupplierInvoice(array $data, User $creator): SupplierInvoice
    {
        $supplierId = (int) $data['supplier_id'];
        $purchaseId = isset($data['purchase_id']) ? (int) $data['purchase_id'] : null;
        $total = (float) ($data['total'] ?? 0);

        $supplier = Supplier::withoutGlobalScope('company')
            ->where('company_id', $creator->company_id)
            ->where('id', $supplierId)
            ->where('is_active', true)
            ->firstOrFail();

        if ($purchaseId) {
            $purchase = Purchase::withoutGlobalScope('company')
                ->where('company_id', $creator->company_id)
                ->findOrFail($purchaseId);
            $total = $total > 0 ? $total : (float) $purchase->total;
        }

        if ($total <= 0) {
            throw new InvalidArgumentException('Invoice total must be positive.');
        }

        return SupplierInvoice::withoutGlobalScope('company')->create([
            'company_id' => $creator->company_id,
            'supplier_id' => $supplierId,
            'purchase_id' => $purchaseId,
            'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
            'total' => $total,
            'currency_id' => $data['currency_id'] ?? null,
            'exchange_rate' => $data['exchange_rate'] ?? 1,
            'status' => SupplierInvoiceStatus::Draft,
            'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
            'due_date' => $data['due_date'] ?? null,
        ]);
    }

    /**
     * Post supplier invoice: Dr GRNI (2100), Cr Accounts Payable (2000). Clears GRNI when invoice arrives (3-way matching).
     */
    public function postSupplierInvoice(SupplierInvoice $invoice, User $creator): void
    {
        if ($invoice->status !== SupplierInvoiceStatus::Draft) {
            throw new InvalidArgumentException('Only draft invoices can be posted.');
        }

        $grniAccount = $this->getGrniAccount($invoice->company_id);
        $payableAccount = $this->getAccountsPayableAccount($invoice->company_id);
        $amount = (float) $invoice->total;

        $branchId = null;
        if ($invoice->purchase_id) {
            $purchase = Purchase::withoutGlobalScope('company')->find($invoice->purchase_id);
            $branchId = $purchase?->branch_id;
        }

        $this->createBalancedJournalEntry(
            companyId: $invoice->company_id,
            branchId: $branchId,
            referenceType: JournalEntry::REFERENCE_TYPE_SUPPLIER_INVOICE,
            referenceId: $invoice->id,
            referenceNumber: $invoice->invoice_number,
            entryType: JournalEntry::ENTRY_TYPE_PURCHASE_INVOICE,
            createdBy: $creator->id,
            debitLines: [[
                'account_id' => $grniAccount->id,
                'amount' => $amount,
                'description' => 'Purchase invoice #' . $invoice->invoice_number,
            ]],
            creditLines: [[
                'account_id' => $payableAccount->id,
                'amount' => $amount,
                'description' => 'Purchase invoice #' . $invoice->invoice_number,
            ]],
        );

        $invoice->update(['status' => SupplierInvoiceStatus::Posted]);
    }

    /**
     * Create supplier payment: Dr Accounts Payable, Cr Cash/Bank.
     */
    public function createSupplierPayment(array $data, User $creator): SupplierPayment
    {
        $supplierId = (int) $data['supplier_id'];
        $invoiceId = isset($data['supplier_invoice_id']) ? (int) $data['supplier_invoice_id'] : null;
        $amount = (float) ($data['amount'] ?? 0);
        $accountId = (int) ($data['account_id'] ?? 0);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        $supplier = Supplier::withoutGlobalScope('company')
            ->where('company_id', $creator->company_id)
            ->where('id', $supplierId)
            ->where('is_active', true)
            ->firstOrFail();

        $account = Account::withoutGlobalScope('company')
            ->where('company_id', $creator->company_id)
            ->where('id', $accountId)
            ->where('is_active', true)
            ->firstOrFail();

        return DB::transaction(function () use ($data, $creator, $supplierId, $invoiceId, $amount, $accountId) {
            $payment = SupplierPayment::withoutGlobalScope('company')->create([
                'company_id' => $creator->company_id,
                'supplier_id' => $supplierId,
                'supplier_invoice_id' => $invoiceId,
                'account_id' => $accountId,
                'payment_reference' => $data['payment_reference'] ?? null,
                'amount' => $amount,
                'currency_id' => $data['currency_id'] ?? null,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'status' => SupplierPaymentStatus::Completed,
                'created_by' => $creator->id,
            ]);

            $payableAccount = $this->getAccountsPayableAccount($creator->company_id);

            $invoice = $invoiceId ? SupplierInvoice::withoutGlobalScope('company')->find($invoiceId) : null;
            $branchId = $invoice && $invoice->purchase_id
                ? Purchase::withoutGlobalScope('company')->find($invoice->purchase_id)?->branch_id
                : null;

            $this->createBalancedJournalEntry(
                companyId: $creator->company_id,
                branchId: $branchId,
                referenceType: JournalEntry::REFERENCE_TYPE_SUPPLIER_PAYMENT,
                referenceId: $payment->id,
                referenceNumber: 'SP-' . $payment->id,
                entryType: JournalEntry::ENTRY_TYPE_SUPPLIER_PAYMENT,
                createdBy: $creator->id,
                debitLines: [[
                    'account_id' => $payableAccount->id,
                    'amount' => $amount,
                    'description' => 'Supplier payment #' . $payment->id,
                ]],
                creditLines: [[
                    'account_id' => $accountId,
                    'amount' => $amount,
                    'description' => 'Supplier payment #' . $payment->id,
                ]],
            );

            if ($invoiceId) {
                $invoice = SupplierInvoice::withoutGlobalScope('company')->lockForUpdate()->find($invoiceId);
                if ($invoice) {
                    $invoice->increment('paid_amount', $amount);
                    if ((float) $invoice->fresh()->paid_amount >= (float) $invoice->total) {
                        $invoice->update(['status' => SupplierInvoiceStatus::Paid]);
                    }
                }
            }

            return $payment->load(['supplier', 'supplierInvoice', 'account']);
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
        $exists = Warehouse::whereHas('branch', fn ($q) => $q->where('company_id', $companyId))
            ->where('id', $warehouseId)->exists();
        if (! $exists) {
            throw new InvalidArgumentException('Warehouse does not belong to your company.');
        }
    }

    private function getInventoryAccount(int $companyId): Account
    {
        $account = Account::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('code', '1200')
            ->where('is_active', true)
            ->first();
        if (! $account) {
            throw new InvalidArgumentException('Inventory account (code 1200) not found. Please run seeders.');
        }
        return $account;
    }

    private function getAccountsPayableAccount(int $companyId): Account
    {
        $account = Account::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('code', '2000')
            ->where('is_active', true)
            ->first();
        if (! $account) {
            throw new InvalidArgumentException('Accounts Payable account (code 2000) not found. Please run seeders.');
        }
        return $account;
    }

    private function getGrniAccount(int $companyId): Account
    {
        $account = Account::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('code', '2100')
            ->where('is_active', true)
            ->first();
        if (! $account) {
            throw new InvalidArgumentException('GRNI account (code 2100) not found. Please run seeders.');
        }
        return $account;
    }

    private function createBalancedJournalEntry(
        int $companyId,
        ?int $branchId,
        string $referenceType,
        int $referenceId,
        ?string $referenceNumber,
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
            'branch_id' => $branchId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_number' => $referenceNumber,
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
