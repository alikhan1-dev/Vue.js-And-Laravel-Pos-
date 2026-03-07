<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierInvoice;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierInvoiceController extends Controller
{
    public function __construct(
        private PurchaseService $purchaseService
    ) {}

    /**
     * List supplier invoices (tenant-scoped).
     */
    public function index(Request $request): JsonResponse
    {
        $query = SupplierInvoice::with(['supplier:id,name,code', 'purchase:id,purchase_number'])
            ->orderByDesc('created_at');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $invoices = $query->paginate($request->input('per_page', 15));

        return response()->json($invoices);
    }

    /**
     * Create supplier invoice.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'purchase_id' => 'nullable|exists:purchases,id',
            'supplier_invoice_number' => 'nullable|string|max:100',
            'total' => 'nullable|numeric|min:0',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'currency_id' => 'nullable|exists:currencies,id',
            'exchange_rate' => 'nullable|numeric|min:0',
        ]);

        $invoice = $this->purchaseService->createSupplierInvoice($validated, $request->user());

        return response()->json($invoice->load(['supplier', 'purchase']), 201);
    }

    /**
     * Post supplier invoice (Dr Inventory, Cr Accounts Payable).
     */
    public function post(Request $request, int $id): JsonResponse
    {
        $invoice = SupplierInvoice::findOrFail($id);
        $this->purchaseService->postSupplierInvoice($invoice, $request->user());

        return response()->json(['message' => 'Invoice posted successfully.', 'invoice' => $invoice->fresh()]);
    }
}
