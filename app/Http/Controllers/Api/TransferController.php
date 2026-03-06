<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    private const MAX_QUANTITY = 999_999_999.99;

    public function __construct(
        private TransferService $transferService
    ) {}

    /**
     * Inter-warehouse stock transfer.
     * TransferService::executeTransfer() runs inside DB::transaction() with row locking (FOR UPDATE).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id', 'exists:warehouses,id'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:' . self::MAX_QUANTITY],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'batch_id' => ['nullable', 'integer', 'exists:product_batches,id'],
            'serial_id' => ['nullable', 'integer', 'exists:product_serials,id'],
        ]);

        try {
            $result = $this->transferService->executeTransfer($validated, $user);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Transfer completed successfully.',
            'transfer_out' => $result['transfer_out']->load(['product:id,name,sku', 'warehouse:id,name,code']),
            'transfer_in' => $result['transfer_in']->load(['product:id,name,sku', 'warehouse:id,name,code']),
        ], 201);
    }
}
