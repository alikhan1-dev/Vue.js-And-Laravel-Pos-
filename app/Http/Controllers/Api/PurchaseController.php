<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function __construct(
        private PurchaseService $purchaseService
    ) {}

    /**
     * List purchases (tenant-scoped).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Purchase::with(['supplier:id,name,code', 'branch:id,name', 'warehouse:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('date_from')) {
            $query->where('purchase_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('purchase_date', '<=', $request->date_to);
        }

        $purchases = $query->paginate($request->input('per_page', 15));

        return response()->json($purchases);
    }

    /**
     * Create purchase order.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'branch_id' => 'required|exists:branches,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'purchase_date' => 'nullable|date',
            'expected_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'currency_id' => 'nullable|exists:currencies,id',
            'exchange_rate' => 'nullable|numeric|min:0',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|exists:products,id',
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.unit_cost' => 'required|numeric|min:0',
            'lines.*.discount' => 'nullable|numeric|min:0',
        ]);

        $purchase = $this->purchaseService->createPurchase($validated, $request->user());

        return response()->json($purchase, 201);
    }

    /**
     * Show purchase detail.
     */
    public function show(int $id): JsonResponse
    {
        $purchase = Purchase::with([
            'lines.product',
            'supplier',
            'branch',
            'warehouse',
            'goodsReceipts.lines',
            'creator:id,name',
        ])->findOrFail($id);

        return response()->json($purchase);
    }

    /**
     * Confirm purchase (draft → confirmed).
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $purchase = Purchase::findOrFail($id);
        $this->purchaseService->confirmPurchase($purchase, $request->user());

        return response()->json(['message' => 'Purchase confirmed.', 'purchase' => $purchase->fresh()]);
    }

    /**
     * Mark purchase as ordered (draft/confirmed → ordered).
     */
    public function markOrdered(Request $request, int $id): JsonResponse
    {
        $purchase = Purchase::findOrFail($id);
        $this->purchaseService->markOrdered($purchase, $request->user());

        return response()->json(['message' => 'Purchase marked ordered.', 'purchase' => $purchase->fresh()]);
    }

    /**
     * Receive goods against purchase.
     */
    public function receive(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'received_by' => 'nullable|exists:users,id',
            'lines' => 'required|array|min:1',
            'lines.*.purchase_line_id' => 'required|exists:purchase_lines,id',
            'lines.*.quantity_received' => 'required|numeric|min:0.0001',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        $purchase = Purchase::findOrFail($id);
        $receipt = $this->purchaseService->receiveGoods(
            $purchase,
            $validated['lines'],
            $request->user(),
            $validated['received_by'] ?? null
        );

        return response()->json($receipt->load(['lines.purchaseLine.product', 'purchase']), 201);
    }
}
