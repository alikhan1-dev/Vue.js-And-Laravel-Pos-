<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierPaymentController extends Controller
{
    public function __construct(
        private PurchaseService $purchaseService
    ) {}

    /**
     * Create supplier payment.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'supplier_invoice_id' => 'nullable|exists:supplier_invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'account_id' => 'required|exists:accounts,id',
            'payment_reference' => 'nullable|string|max:100',
            'payment_date' => 'nullable|date',
            'currency_id' => 'nullable|exists:currencies,id',
        ]);

        $payment = $this->purchaseService->createSupplierPayment($validated, $request->user());

        return response()->json($payment->load(['supplier', 'supplierInvoice']), 201);
    }
}
