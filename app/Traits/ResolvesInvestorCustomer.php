<?php

namespace App\Traits;

use App\Models\Customer;

trait ResolvesInvestorCustomer
{
    /**
     * Resolve the customer profile linked to the authenticated user.
     */
    protected function resolveCustomer(): ?Customer
    {
        $user = auth('api')->user();

        if (!$user) {
            return null;
        }

        return Customer::where('customer_id', $user->id)
            ->orWhereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($user->id_number)])
            ->first();
    }
}
