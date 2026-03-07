<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    /**
     * List suppliers (tenant-scoped).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::with('currency:id,code,name')
            ->where('is_active', true)
            ->orderBy('name');

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        $suppliers = $query->paginate($request->input('per_page', 15));

        return response()->json($suppliers);
    }

    /**
     * Create supplier.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'tax_number' => 'nullable|string|max:50',
            'currency_id' => 'nullable|exists:currencies,id',
            'credit_limit' => 'nullable|numeric|min:0',
        ]);

        $validated['company_id'] = $request->user()->company_id;
        $validated['created_by'] = $request->user()->id;
        $validated['is_active'] = true;

        $supplier = Supplier::create($validated);

        return response()->json($supplier->load('currency'), 201);
    }
}
