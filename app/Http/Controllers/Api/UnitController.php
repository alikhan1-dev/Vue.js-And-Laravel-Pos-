<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $units = Unit::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return response()->json($units);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'short_name' => ['required', 'string', 'max:20'],
            'is_active' => ['boolean'],
        ]);

        $validated['company_id'] = $user->company_id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $unit = Unit::withoutGlobalScope('company')->create($validated);

        return response()->json($unit, 201);
    }
}
