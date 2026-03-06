<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductSerial;
use App\Models\WarrantyRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarrantyLookupController extends Controller
{
    /**
     * GET /api/warranty/lookup?serial=ABC123
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'serial' => ['nullable', 'string', 'max:255'],
            'sale_id' => ['nullable', 'integer'],
        ]);

        $serialNumber = $request->query('serial');
        $saleId = $request->integer('sale_id') ?: null;

        $query = WarrantyRegistration::with([
            'product:id,name,sku',
            'sale:id,created_at',
            'serial:id,serial_number',
            'warranty:id,name,duration_months,type',
        ]);

        if ($serialNumber) {
            $serial = ProductSerial::where('serial_number', $serialNumber)->first();
            if (! $serial) {
                return response()->json(['message' => 'No warranty registration found for this serial.'], 404);
            }
            $query->where('serial_id', $serial->id);
        } elseif ($saleId) {
            $query->where('sale_id', $saleId);
        } else {
            return response()->json(['message' => 'Please provide serial or sale_id.'], 422);
        }

        $registrations = $query->get();

        if ($registrations->isEmpty()) {
            return response()->json(['message' => 'No warranty registration found.'], 404);
        }

        return response()->json($registrations);
    }

    /**
     * GET /api/customers/{id}/warranties
     * For now, customer_id is a scalar field; a full customer model can be added later.
     */
    public function customerWarranties(int $customerId): JsonResponse
    {
        $registrations = WarrantyRegistration::with([
            'product:id,name,sku',
            'sale:id,created_at',
            'serial:id,serial_number',
            'warranty:id,name,duration_months,type',
        ])->where('customer_id', $customerId)->get();

        return response()->json($registrations);
    }
}

