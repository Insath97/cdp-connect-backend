<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CalculatePortalReturnRequest extends FormRequest
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
            'investment_product_id' => 'required|integer|exists:investment_products,id',
            'investment_amount'     => 'required|numeric|min:1',
        ];
    }

    /**
     * Custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'investment_product_id.required' => 'Investment product ID is required.',
            'investment_product_id.exists'   => 'Selected investment product does not exist.',
            'investment_amount.required'     => 'Investment amount is required.',
            'investment_amount.numeric'      => 'Investment amount must be a valid number.',
            'investment_amount.min'          => 'Investment amount must be at least 1.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'  => 'error',
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422));
    }
}
