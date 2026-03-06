<?php

namespace App\Http\Controllers\Api;

use App\Events\WarrantyClaimCreated;
use App\Enums\WarrantyClaimStatus;
use App\Enums\WarrantyClaimType;
use App\Http\Controllers\Controller;
use App\Models\WarrantyClaim;
use App\Models\WarrantyRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarrantyClaimController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = WarrantyClaim::with([
            'registration.product:id,name,sku',
            'registration.serial:id,serial_number',
            'registration.warranty:id,name',
            'approver:id,name',
        ]);

        if ($request->filled('status') && in_array($request->status, WarrantyClaimStatus::values(), true)) {
            $query->where('status', $request->status);
        }

        $claims = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 15));

        return response()->json($claims);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warranty_registration_id' => ['required', 'integer', 'exists:warranty_registrations,id'],
            'claim_type' => ['required', 'string', 'in:'.implode(',', WarrantyClaimType::values())],
            'description' => ['required', 'string'],
        ]);

        /** @var WarrantyRegistration $registration */
        $registration = WarrantyRegistration::findOrFail($validated['warranty_registration_id']);

        if ($registration->is_expired) {
            return response()->json(['message' => 'Warranty has expired. Claim cannot be created.'], 422);
        }

        $claimNumber = 'CLM-'.now()->format('Ymd-His').'-'.$registration->id;

        $claim = WarrantyClaim::create([
            'warranty_registration_id' => $registration->id,
            'claim_number' => $claimNumber,
            'claim_type' => $validated['claim_type'],
            'description' => $validated['description'],
            'status' => WarrantyClaimStatus::Pending,
            'approved_by' => null,
            'resolution_notes' => null,
        ]);

        WarrantyClaimCreated::dispatch($claim);

        return response()->json($claim->load(['registration.product:id,name,sku', 'registration.warranty:id,name']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $claim = WarrantyClaim::findOrFail($id);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', WarrantyClaimStatus::values())],
            'resolution_notes' => ['nullable', 'string'],
        ]);

        if (isset($validated['status'])) {
            $claim->status = $validated['status'];
            if (in_array($validated['status'], [WarrantyClaimStatus::Approved->value, WarrantyClaimStatus::Rejected->value, WarrantyClaimStatus::Completed->value], true)) {
                $claim->approved_by = $request->user()->id;
            }
        }

        if (array_key_exists('resolution_notes', $validated)) {
            $claim->resolution_notes = $validated['resolution_notes'];
        }

        $claim->save();

        return response()->json($claim->fresh(['registration.product:id,name,sku', 'registration.warranty:id,name', 'approver:id,name']));
    }
}

