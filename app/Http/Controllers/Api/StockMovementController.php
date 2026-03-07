<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    private const MAX_QUANTITY = 999_999_999.99;

    private const MAX_PER_PAGE = 100;

    /**
     * List stock movements (tenant-aware). Filter by product_id, warehouse_id, type.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = StockMovement::withoutGlobalScope('company')
            ->where('company_id', $user->company_id)
            ->with(['product:id,name,sku,company_id', 'warehouse:id,name,code', 'creator:id,name']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }
        if ($request->filled('type') && in_array($request->type, StockMovementType::values(), true)) {
            $query->where('type', $request->type);
        }

        $perPage = min($request->integer('per_page', 15), self::MAX_PER_PAGE);
        $movements = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($movements);
    }

    /**
     * Record a stock movement (audit-ready: created_by, reference_type, reference_id).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:'.self::MAX_QUANTITY],
            'type' => ['required', 'string', 'in:'.implode(',', StockMovementType::values())],
            'reference_type' => ['nullable', 'string', 'max:255'],
            'reference_id' => ['nullable', 'integer'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'batch_id' => ['nullable', 'integer', 'exists:product_batches,id'],
            'serial_id' => ['nullable', 'integer', 'exists:product_serials,id'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        if (! empty($validated['idempotency_key'])) {
            $existing = StockMovement::findByIdempotencyKey($validated['idempotency_key']);
            if ($existing) {
                return response()->json(
                    $existing->load(['product:id,name,sku', 'warehouse:id,name,code', 'creator:id,name']),
                    200
                );
            }
        }

        $product = Product::find($validated['product_id']);
        $warehouse = Warehouse::with('branch')->find($validated['warehouse_id']);

        if (! $product) {
            return response()->json(['message' => 'Product not found or access denied.'], 403);
        }
        if (! $warehouse || $warehouse->branch->company_id !== $user->company_id) {
            return response()->json(['message' => 'Warehouse not found or access denied.'], 403);
        }

        $validated['created_by'] = $user->id;

        $movement = StockMovement::withoutGlobalScope('company')->create($validated);

        return response()->json($movement->load(['product:id,name,sku', 'warehouse:id,name,code', 'creator:id,name']), 201);
    }
}
