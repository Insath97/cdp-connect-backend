<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Add proper authorization logic here if needed
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:csv,txt|max:5120', // 5MB limit
            'table' => [
                'required',
                'string',
                Rule::in([
                    'countries',
                    'provinces',
                    'zones',
                    'regions',
                    'branches',
                    'levels',
                    'users',
                    'customers',
                    'investments',
                    'beneficiaries',
                    'receipts',
                    'customer_bank_details',
                    'commissions',
                    'commission_settings',
                    'investment_products',
                    'investment_product_rates',
                    'quotations',
                    'targets',
                    'system_settings'
                ])
            ],
        ];
    }

    /**
     * Store the table from the route parameter into the request data for validation.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'table' => $this->route('table'),
        ]);
    }
}
