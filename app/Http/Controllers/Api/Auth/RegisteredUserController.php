<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use App\Models\Role;

class RegisteredUserController extends Controller
{
    /**
     * Handle an incoming API registration request.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => ['nullable', 'string', 'max:255'],
            'branch_code' => ['nullable', 'string', 'max:255'],
        ]);

        $company = Company::firstOrCreate(
            ['email' => 'default@company.test'],
            [
                'name' => 'Default Company',
                'currency' => 'USD',
                'timezone' => 'UTC',
            ],
        );

        $roleName = $validated['role'] ?? 'Cashier';

        $role = Role::where('company_id', $company->id)
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->first()
            ?? Role::firstOrCreate([
                'company_id' => $company->id,
                'name' => 'Admin',
                'guard_name' => 'web',
            ]);

        // Resolve branch: either by explicit branch_code or fall back to the company's first branch.
        $branch = null;

        if (! empty($validated['branch_code'])) {
            $branch = Branch::where('company_id', $company->id)
                ->where('code', $validated['branch_code'])
                ->first();
        }

        if (! $branch) {
            $branch = Branch::where('company_id', $company->id)->orderBy('id')->first();
        }

        $user = User::create([
            'company_id' => $company->id,
            'branch_id' => $branch?->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        $user->assignRole($role);

        $token = $user->createToken($request->input('device_name', 'web'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->load('company', 'roles'),
        ], 201);
    }
}

