<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    private const MAX_UNIT_PRICE = 999_999_999.99;
    /**
     * List products (tenant-aware).
     */
    public function index(Request $request): JsonResponse
    {
        $products = Product::with([
                'company:id,name',
                'category:id,name',
                'brand:id,name',
                'unit:id,name,short_name',
            ])
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->integer('brand_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->orderBy('name')
            ->get();

        return response()->json($products);
    }

    /**
     * Create a product (tenant-aware: assigned to current user's company).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:50', Rule::unique('products')->where('company_id', $user->company_id)],
            'barcode' => array_filter([
                'nullable',
                'string',
                'max:50',
                $request->filled('barcode') ? Rule::unique('products')->where('company_id', $user->company_id) : null,
            ]),
            'description' => ['nullable', 'string'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_UNIT_PRICE],
            'uom' => array_filter(['nullable', 'string', 'max:20', config('pos.allowed_uom') ? Rule::in(config('pos.allowed_uom')) : null]),
            'is_active' => ['boolean'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'type' => ['nullable', 'string', 'in:simple,variable'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'track_stock' => ['boolean'],
            'track_serial' => ['boolean'],
            'track_batch' => ['boolean'],
            'allow_negative_stock' => ['boolean'],
        ]);

        $validated['company_id'] = $user->company_id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $product = Product::withoutGlobalScope('company')->create($validated);

        return response()->json($product->load('company:id,name'), 201);
    }

    /**
     * Stock per warehouse for a product (single aggregated query for all warehouses).
     */
    public function stock(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $warehouses = Warehouse::get();
        $warehouseIds = $warehouses->pluck('id');
        $quantities = $product->stockByWarehousesCached($warehouseIds);

        $stockByWarehouse = $warehouses->map(fn ($warehouse) => [
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'warehouse_code' => $warehouse->code,
            'quantity' => $quantities->get($warehouse->id, 0.0),
        ])->values()->all();

        return response()->json([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'uom' => $product->uom,
            'stock_by_warehouse' => $stockByWarehouse,
        ]);
    }
}
