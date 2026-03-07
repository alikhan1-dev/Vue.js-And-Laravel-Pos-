<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SaleController extends Controller
{
    public function __construct(
        private SaleService $saleService
    ) {}

    /**
     * List sales (tenant-aware). Optional filters: type, branch_id, status, date_from, date_to.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Sale::with([
            'branch:id,name,code',
            'warehouse:id,name,code',
            'creator:id,name',
            'lines' => fn ($q) => $q->with('product:id,name,sku'),
        ]);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $sales = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 15));

        return response()->json($sales);
    }

    /**
     * Create sale or quotation. Body: branch_id, warehouse_id, type (sale|quotation), lines: [{product_id, quantity, unit_price, discount?}]
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'type' => ['required', 'string', 'in:sale,quotation'],
            'status' => ['nullable', 'string', 'in:draft'],
            'currency' => ['nullable', 'string', 'max:10'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001'],
            'notes' => ['nullable', 'string'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'discounts' => ['nullable', 'array'],
            'discounts.*.type' => ['nullable', 'string', 'in:percentage,fixed,promotion,coupon,manual'],
            'discounts.*.value' => ['required_with:discounts', 'numeric', 'min:0'],
            'discounts.*.description' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'pos_session_id' => ['nullable', 'integer', 'exists:pos_sessions,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.serial_id' => ['nullable', 'integer', 'exists:product_serials,id'],
            'lines.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'lines.*.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);

        $serialIds = collect($validated['lines'])
            ->pluck('serial_id')
            ->filter()
            ->values();

        if ($serialIds->count() !== $serialIds->unique()->count()) {
            return response()->json([
                'message' => 'Sale validation failed.',
                'errors' => ['sale' => ['Duplicate serial_id (IMEI) detected in the same request. Each serial can only appear once per sale.']],
            ], 422);
        }

        foreach ($validated['lines'] as $i => $line) {
            $maxDiscount = ($line['quantity'] ?? 0) * ($line['unit_price'] ?? 0);
            if (($line['discount'] ?? 0) > $maxDiscount) {
                $validated['lines'][$i]['discount'] = $maxDiscount;
            }
        }

        try {
            $sale = $this->saleService->create($validated, $request->user());
            return response()->json($sale, 201);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => 'Sale validation failed.',
                'errors' => [
                    'sale' => [$e->getMessage()],
                ],
            ], 422);
        }
    }

    /**
     * Sale detail with lines and stock movement info.
     */
    public function show(int $id): JsonResponse
    {
        $sale = Sale::with([
            'branch:id,name,code',
            'warehouse:id,name,code',
            'creator:id,name',
            'lines.product:id,name,sku,unit_price',
            'lines.stockMovement:id,quantity,type,created_at',
        ])->findOrFail($id);

        return response()->json($sale);
    }

    /**
     * Convert quotation to sale (creates stock movements).
     */
    public function convert(Request $request, int $id): JsonResponse
    {
        $sale = Sale::findOrFail($id);

        try {
            $sale = $this->saleService->convertToSale($sale, $request->user());
            return response()->json($sale);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => 'Sale validation failed.',
                'errors' => [
                    'sale' => [$e->getMessage()],
                ],
            ], 422);
        }
    }

    /**
     * Cancel a sale. Allowed only for draft or pending. Completed sales must use returns/refunds.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $sale = Sale::findOrFail($id);

        try {
            $sale = $this->saleService->cancel($sale, $request->user());
            return response()->json($sale);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => 'Sale validation failed.',
                'errors' => ['sale' => [$e->getMessage()]],
            ], 422);
        }
    }

    /**
     * Complete a draft sale (POS checkout): validate stock, create movements, post accounting.
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        $sale = Sale::findOrFail($id);

        try {
            $sale = $this->saleService->complete($sale, $request->user());
            return response()->json($sale);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => 'Sale validation failed.',
                'errors' => ['sale' => [$e->getMessage()]],
            ], 422);
        }
    }

    /**
     * Create return for a sale. Optional body: lines (override which lines to return).
     */
    public function returnSale(Request $request, int $id): JsonResponse
    {
        $sale = Sale::findOrFail($id);
        $validated = $request->validate([
            'lines' => ['nullable', 'array'],
            'lines.*.product_id' => ['required_with:lines', 'integer'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'return_reason_code' => ['nullable', 'string', 'in:damaged,customer_return,wrong_item,warranty,fraud,other'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $linesOverride = $validated['lines'] ?? null;

        try {
            $returnSale = $this->saleService->createReturn(
                $sale,
                $linesOverride,
                $request->user(),
                $validated['return_reason_code'] ?? null,
                $validated['reason'] ?? null
            );
            return response()->json($returnSale, 201);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => 'Sale validation failed.',
                'errors' => [
                    'sale' => [$e->getMessage()],
                ],
            ], 422);
        }
    }

    /**
     * Current stock for each product in this sale's warehouse; includes warehouse info and all_sufficient.
     */
    public function stockCheck(int $id): JsonResponse
    {
        $sale = Sale::with(['lines.product', 'warehouse:id,name,code'])->findOrFail($id);
        $warehouseId = $sale->warehouse_id;
        $warehouse = $sale->warehouse;

        $stockByLine = $sale->lines->map(function ($line) use ($warehouseId) {
            $current = $line->product ? $line->product->currentStockCached($warehouseId) : 0.0;
            $needed = (float) $line->quantity;
            return [
                'sale_line_id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product->name ?? null,
                'sku' => $line->product->sku ?? null,
                'quantity_in_sale' => $needed,
                'current_stock' => $current,
                'sufficient' => $current >= $needed,
            ];
        });

        $allSufficient = $stockByLine->every(fn ($row) => $row['sufficient']);

        return response()->json([
            'sale_id' => $sale->id,
            'warehouse' => $warehouse ? [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
            ] : null,
            'warehouse_id' => $warehouseId,
            'all_sufficient' => $allSufficient,
            'lines' => $stockByLine,
        ]);
    }
}
