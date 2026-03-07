<?php

namespace App\Services;

use App\Models\CustomerLedger;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Customer ledger: invoice, payment, refund, adjustment, credit.
 * Powers aging reports, credit control, and statements.
 */
class CustomerLedgerService
{
    /**
     * Post invoice (sale) to customer ledger. Call when sale is completed and has customer_id.
     */
    public function postInvoice(Sale $sale, User $creator): void
    {
        if (! $sale->customer_id) {
            return;
        }

        $amount = (float) $sale->grand_total;
        if ($amount <= 0) {
            return;
        }

        $balanceAfter = $this->getNextBalance($sale->company_id, $sale->customer_id, $amount);
        CustomerLedger::withoutGlobalScope('company')->create([
            'company_id' => $sale->company_id,
            'customer_id' => $sale->customer_id,
            'type' => CustomerLedger::TYPE_INVOICE,
            'reference_type' => 'Sale',
            'reference_id' => $sale->id,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'entry_date' => $sale->created_at->toDateString(),
            'description' => 'Sale ' . ($sale->number ?? $sale->id),
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Post payment to customer ledger. Call when payment is completed and linked to a sale with customer_id.
     */
    public function postPayment(Payment $payment, User $creator): void
    {
        $customerId = $payment->customer_id ?? $payment->sale?->customer_id;
        if (! $customerId) {
            return;
        }

        $amount = (float) $payment->amount;
        if ($amount <= 0) {
            return; // Refunds handled by postRefund
        }

        $balanceAfter = $this->getNextBalance($payment->company_id, $customerId, -$amount);
        CustomerLedger::withoutGlobalScope('company')->create([
            'company_id' => $payment->company_id,
            'customer_id' => $customerId,
            'type' => CustomerLedger::TYPE_PAYMENT,
            'reference_type' => 'Payment',
            'reference_id' => $payment->id,
            'amount' => -$amount,
            'balance_after' => $balanceAfter,
            'entry_date' => $payment->payment_date?->format('Y-m-d') ?? now()->toDateString(),
            'description' => 'Payment ' . ($payment->payment_number ?? $payment->id),
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Post refund (negative payment or return) to customer ledger.
     */
    public function postRefund(Payment $payment, float $refundAmount, User $creator): void
    {
        $customerId = $payment->customer_id ?? $payment->sale?->customer_id;
        if (! $customerId) {
            return;
        }

        $balanceAfter = $this->getNextBalance($payment->company_id, $customerId, $refundAmount);
        CustomerLedger::withoutGlobalScope('company')->create([
            'company_id' => $payment->company_id,
            'customer_id' => $customerId,
            'type' => CustomerLedger::TYPE_REFUND,
            'reference_type' => 'Payment',
            'reference_id' => $payment->id,
            'amount' => $refundAmount,
            'balance_after' => $balanceAfter,
            'entry_date' => now()->toDateString(),
            'description' => 'Refund for payment ' . ($payment->payment_number ?? $payment->id),
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Post return (goods) to customer ledger when SaleReturn is completed. Reduces receivable.
     */
    public function postReturnCredit(SaleReturn $saleReturn, User $creator): void
    {
        if (! $saleReturn->customer_id) {
            return;
        }

        $amount = (float) $saleReturn->refund_amount;
        if ($amount <= 0) {
            return;
        }

        $balanceAfter = $this->getNextBalance($saleReturn->company_id, $saleReturn->customer_id, $amount);
        CustomerLedger::withoutGlobalScope('company')->create([
            'company_id' => $saleReturn->company_id,
            'customer_id' => $saleReturn->customer_id,
            'type' => CustomerLedger::TYPE_REFUND,
            'reference_type' => 'SaleReturn',
            'reference_id' => $saleReturn->id,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'entry_date' => $saleReturn->created_at->toDateString(),
            'description' => 'Return ' . ($saleReturn->return_number ?? $saleReturn->id),
            'created_by' => $creator->id,
        ]);
    }

    private function getNextBalance(int $companyId, int $customerId, float $signedAmount): float
    {
        $last = CustomerLedger::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->orderByDesc('created_at')
            ->value('balance_after');

        $previous = $last !== null ? (float) $last : 0.0;

        return $previous + $signedAmount;
    }
}
