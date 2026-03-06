<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseStockController extends Controller
{
    /**
     * Warehouse stock report using stock_cache for fast reads.
     * Filters: warehouse_id, product_id, variant_id, low_stock (threshold).
     */
    public function index(Request $request): JsonResponse
    {
        $query = StockCache::with([
            'product:id,name,sku,type',
            'warehouse:id,name,code',
            'variant:id,product_id,name,sku',
        ]);

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        if ($request->filled('variant_id')) {
            $query->where('variant_id', $request->integer('variant_id'));
        }

        if ($request->filled('low_stock')) {
            $threshold = (float) $request->input('low_stock');
            $query->where('quantity', '<=', $threshold);
        }

        $perPage = min($request->integer('per_page', 25), 100);
        $stock = $query->orderBy('product_id')->paginate($perPage);

        return response()->json($stock);
    }
}
