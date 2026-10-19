<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateInvestorQuotationRequest extends FormRequest
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
            'investment_product_id' => 'required|exists:investment_products,id',
            'investment_amount'     => 'required|numeric|min:1',
            'branch_id'             => 'nullable|exists:branches,id',
            'notes'                 => 'nullable|string|max:1000',
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'investment_product_id.required' => 'Please select an investment plan.',
            'investment_product_id.exists'   => 'The selected investment plan is invalid.',
            'investment_amount.required'     => 'Investment amount is required.',
            'investment_amount.numeric'      => 'Investment amount must be a valid number.',
            'investment_amount.min'          => 'Investment amount must be greater than zero.',
            'branch_id.exists'               => 'The selected branch is invalid.',
        ];
    }

    /**
     * Standardized failed validation response.
     */
    protected function failedValidation(Validator $validator)
    {
        $errorMessages = $validator->errors();

        $fieldErrors = collect($errorMessages->getMessages())->map(function ($messages, $field) {
            return [
                'field'    => $field,
                'messages' => $messages,
            ];
        })->values();

        $message = $fieldErrors->count() > 1
            ? 'There are multiple validation errors. Please review the input and correct the issues.'
            : ($fieldErrors->first()['messages'][0] ?? 'There is an issue with the input.');

        throw new HttpResponseException(response()->json([
            'status'  => 'error',
            'message' => $message,
            'errors'  => $fieldErrors,
        ], 422));
    }
}
