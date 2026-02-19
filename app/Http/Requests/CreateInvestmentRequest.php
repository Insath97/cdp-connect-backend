<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateInvestmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Permissions can be handled in controller or via middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'application_number' => 'nullable|string|unique:investments,application_number',
            'sales_code' => 'nullable|string|unique:investments,sales_code',
            'reservation_date' => 'required|date',
            'customer_id' => 'required|exists:customers,id',
            'branch_id' => 'sometimes|exists:branches,id',
            'investment_product_id' => 'required|exists:investment_products,id',
            'beneficiary_id' => 'nullable|exists:beneficiaries,id',
            'customer_bank_detail_id' => 'nullable|exists:customer_bank_details,id',

            // Nested Beneficiary Data
            'beneficiary' => 'nullable|array',
            'beneficiary.full_name' => 'required_with:beneficiary|string|max:255',
            'beneficiary.id_type' => 'required_with:beneficiary|in:nic,passport,driving_license,other',
            'beneficiary.id_number' => 'required_with:beneficiary|string|max:50',
            'beneficiary.phone_primary' => 'required_with:beneficiary|string|max:20',
            'beneficiary.relationship' => 'required_with:beneficiary|string|max:100',
            'beneficiary.share_percentage' => 'required_with:beneficiary|numeric|min:0|max:100',

            // Nested Bank Detail Data
            'bank_detail' => 'nullable|array',
            'bank_detail.bank_name' => 'required_with:bank_detail|string|max:255',
            'bank_detail.branch_name' => 'required_with:bank_detail|string|max:255',
            'bank_detail.account_number' => 'required_with:bank_detail|string|max:50',
            'bank_detail.payment_method' => 'required_with:bank_detail|in:bank_transfer,cheque,cash',

            'investment_amount' => 'required|numeric|min:0',
            'bank' => 'required|in:HNB,Sampath,Commercial Bank,People\'s Bank,NSB,Other',
            'payment_type' => 'required|in:full_payment,monthly',
            'payment_description' => 'nullable|string',
            'initial_payment' => 'required|numeric|min:0',
            'initial_payment_date' => 'nullable|date',
            'monthly_payment_amount' => 'nullable|numeric|min:0',
            'monthly_payment_date' => 'nullable|date',
            'unit_head_id' => 'required|exists:users,id',
            'notes' => 'nullable|string',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errorMessages = $validator->errors();

        $fieldErrors = collect($errorMessages->getMessages())->map(function ($messages, $field) {
            return [
                'field' => $field,
                'messages' => $messages,
            ];
        })->values();

        $message = $fieldErrors->count() > 1
            ? 'There are multiple validation errors. Please review the form and correct the issues.'
            : 'There is an issue with the input for ' . $fieldErrors->first()['field'] . '.';

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => $fieldErrors,
        ], 422));
    }
}
