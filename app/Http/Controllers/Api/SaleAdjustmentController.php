<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleAdjustment;
use App\Services\SaleAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SaleAdjustmentController extends Controller
{
    public function __construct(
        private SaleAdjustmentService $service
    ) {}

    public function store(Request $request, int $saleId): JsonResponse
    {
        $sale = Sale::findOrFail($saleId);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:price_correction,quantity_correction,discount_correction,tax_correction,cancellation,other'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'reason' => ['required', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ]);

        try {
            $adjustment = $this->service->create($sale, $validated, $request->user());
            return response()->json($adjustment, 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $adjustment = SaleAdjustment::findOrFail($id);

        try {
            $adjustment = $this->service->approve($adjustment, $request->user());
            return response()->json($adjustment);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $query = SaleAdjustment::with(['sale:id,number,grand_total,status', 'creator:id,name', 'approver:id,name']);

        if ($request->filled('sale_id')) {
            $query->where('sale_id', $request->integer('sale_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate($request->integer('per_page', 15))
        );
    }
}
