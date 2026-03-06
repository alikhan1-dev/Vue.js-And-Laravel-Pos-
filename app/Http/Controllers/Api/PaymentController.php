<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService
    ) {}

    /**
     * List payments (tenant-aware). Filters: sale_id, payment_method_id, status, branch_id, date_from, date_to.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with([
            'sale:id,company_id,total,type,status',
            'branch:id,name,code',
            'warehouse:id,name,code',
            'creator:id,name',
            'lines' => fn ($q) => $q->with(['account:id,code,name,type', 'paymentMethod:id,name,type']),
        ]);

        if ($request->filled('sale_id')) {
            $query->where('sale_id', $request->integer('sale_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }
        if ($request->filled('date_from')) {
            $query->where(function ($q) use ($request) {
                $q->whereDate('payment_date', '>=', $request->date_from)
                    ->orWhereNull('payment_date')->whereDate('created_at', '>=', $request->date_from);
            });
        }
        if ($request->filled('date_to')) {
            $query->where(function ($q) use ($request) {
                $q->whereDate('payment_date', '<=', $request->date_to)
                    ->orWhereNull('payment_date')->whereDate('created_at', '<=', $request->date_to);
            });
        }
        if ($request->filled('payment_method_id')) {
            $query->whereHas('lines', fn ($q) => $q->where('payment_method_id', $request->integer('payment_method_id')));
        }

        $payments = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 15));

        return response()->json($payments);
    }

    /**
     * Create payment. Body: sale_id?, branch_id, warehouse_id?, status?, notes?, lines: [{payment_method_id, account_id, amount, reference?, description?}]
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sale_id' => ['nullable', 'integer', 'exists:sales,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'payment_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:pending,completed,failed,refunded,cancelled'],
            'notes' => ['nullable', 'string', 'max:65535'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'lines.*.reference' => ['nullable', 'string', 'max:255'],
            'lines.*.description' => ['nullable', 'string', 'max:65535'],
        ]);

        try {
            $payment = $this->paymentService->create($validated, $request->user());
            return response()->json($payment, 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Payment detail including lines and journal entries.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $payment = Payment::with([
            'sale:id,company_id,total,type,status,created_at',
            'branch:id,name,code',
            'warehouse:id,name,code',
            'creator:id,name',
            'lines.account',
            'lines.paymentMethod',
            'journalEntries.lines.account',
            'journalEntries.creator:id,name',
        ])->findOrFail($id);

        return response()->json($payment);
    }

    /**
     * Refund a payment. Body: amount, account_id (e.g. Cash account to refund from).
     */
    public function refund(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
        ]);

        $payment = Payment::findOrFail($id);

        try {
            $refundPayment = $this->paymentService->refund($payment, $validated, $request->user());
            return response()->json($refundPayment, 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
