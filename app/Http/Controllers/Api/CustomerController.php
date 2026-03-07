<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        private CustomerService $customerService
    ) {}

    /**
     * List customers (tenant-scoped). Optional: search, status, per_page.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Customer::with('addresses')->orderBy('name');

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $customers = $query->paginate($request->integer('per_page', 15));

        return response()->json($customers);
    }

    /**
     * Create customer. Body: name, email?, phone?, tax_number?, address?, city?, country?, loyalty_points?, credit_limit?, status?, notes?, addresses?: [{type, address, city, state, country, postal_code, is_default}].
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'tax_number' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'loyalty_points' => 'nullable|numeric|min:0',
            'credit_limit' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:active,inactive,blocked',
            'notes' => 'nullable|string',
            'addresses' => 'nullable|array',
            'addresses.*.type' => 'nullable|string|in:billing,shipping',
            'addresses.*.address' => 'nullable|string',
            'addresses.*.city' => 'nullable|string|max:100',
            'addresses.*.state' => 'nullable|string|max:100',
            'addresses.*.country' => 'nullable|string|max:100',
            'addresses.*.postal_code' => 'nullable|string|max:20',
            'addresses.*.is_default' => 'nullable|boolean',
        ]);

        $customer = $this->customerService->create($validated, $request->user());

        return response()->json($customer, 201);
    }

    /**
     * Show customer with addresses and recent sales count.
     */
    public function show(int $id): JsonResponse
    {
        $customer = Customer::with('addresses')->findOrFail($id);

        return response()->json($customer);
    }

    /**
     * Update customer.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'tax_number' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'loyalty_points' => 'nullable|numeric|min:0',
            'credit_limit' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:active,inactive,blocked',
            'notes' => 'nullable|string',
            'addresses' => 'nullable|array',
            'addresses.*.type' => 'nullable|string|in:billing,shipping',
            'addresses.*.address' => 'nullable|string',
            'addresses.*.city' => 'nullable|string|max:100',
            'addresses.*.state' => 'nullable|string|max:100',
            'addresses.*.country' => 'nullable|string|max:100',
            'addresses.*.postal_code' => 'nullable|string|max:20',
            'addresses.*.is_default' => 'nullable|boolean',
        ]);

        $customer = $this->customerService->update($customer, $validated, $request->user());

        return response()->json($customer);
    }
}
