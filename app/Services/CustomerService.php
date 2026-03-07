<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CustomerService
{
    /**
     * Create customer with optional addresses.
     */
    public function create(array $data, User $creator): Customer
    {
        $data['company_id'] = $creator->company_id;
        $data['created_by'] = $creator->id;
        $data['status'] = $data['status'] ?? CustomerStatus::Active;

        return DB::transaction(function () use ($data, $creator) {
            $addresses = $data['addresses'] ?? [];
            unset($data['addresses']);

            $customer = Customer::create($data);

            foreach ($addresses as $addr) {
                $this->addAddress($customer, $addr);
            }

            return $customer->load('addresses');
        });
    }

    /**
     * Update customer and optionally sync addresses.
     */
    public function update(Customer $customer, array $data, User $updater): Customer
    {
        $customer->updated_at = now();
        $addresses = $data['addresses'] ?? null;
        unset($data['addresses'], $data['company_id']);

        return DB::transaction(function () use ($customer, $data, $addresses) {
            $customer->update($data);

            if (is_array($addresses)) {
                $customer->addresses()->delete();
                foreach ($addresses as $addr) {
                $this->addAddress($customer, $addr);
                }
            }

            return $customer->fresh('addresses');
        });
    }

    private function addAddress(Customer $customer, array $addr): void
    {
        CustomerAddress::create([
            'customer_id' => $customer->id,
            'company_id' => $customer->company_id,
            'type' => $addr['type'] ?? 'billing',
            'address' => $addr['address'] ?? null,
            'city' => $addr['city'] ?? null,
            'state' => $addr['state'] ?? null,
            'country' => $addr['country'] ?? null,
            'postal_code' => $addr['postal_code'] ?? null,
            'is_default' => (bool) ($addr['is_default'] ?? false),
        ]);
    }

    /**
     * Ensure customer belongs to user's company.
     */
    public function ensureCustomerBelongsToCompany(int $companyId, int $customerId): void
    {
        $exists = Customer::withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('id', $customerId)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException('Customer not found or does not belong to your company.');
        }
    }
}
