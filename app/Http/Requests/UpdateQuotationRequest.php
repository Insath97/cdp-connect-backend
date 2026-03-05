<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateQuotationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => 'nullable|exists:customers,id',
            'f_name' => 'nullable|string|max:255',
            'l_name' => 'nullable|string|max:255',
            'full_name' => 'sometimes|string|max:255',
            'id_type' => 'sometimes|in:nic,passport,driving_license,other',
            'id_number' => 'sometimes|string|max:255',
            'phone_primary' => 'sometimes|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'branch_id' => 'sometimes|exists:branches,id',
            'investment_product_id' => 'sometimes|exists:investment_products,id',
            'investment_amount' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:draft,sent,accepted,rejected',
            'notes' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
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
