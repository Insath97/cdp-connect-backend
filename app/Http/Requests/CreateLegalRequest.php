<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateLegalRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'investment_id' => 'required|exists:investments,id',
            'language' => 'required|in:english,tamil,sinhala',
            'full_name' => 'required_if:language,tamil,sinhala|nullable|string|max:255',
            'name_with_initials' => 'required_if:language,tamil,sinhala|nullable|string|max:255',
            'address_line_1' => 'required_if:language,tamil,sinhala|nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'branch_location' => 'nullable|string|max:255',
            'execution_location' => 'nullable|string|max:255',
            'business_entered_date' => 'required_if:language,tamil,sinhala|nullable|string|max:255',
            'completed_date' => 'required_if:language,tamil,sinhala|nullable|string|max:255',
            'execution_year' => 'required_if:language,tamil,sinhala|nullable|string|max:255',
            'amount_in_words' => 'required_if:language,tamil,english|nullable|string|max:255',
            'plan' => 'required_if:language,tamil,english|nullable|string|max:255',
            'monthly_profit' => 'required_if:language,tamil,english|nullable|string|max:255',
            'monthly_profit_day' => 'required_if:language,tamil,english|nullable|string|max:255',
            'month_6_breakdown_in_words' => 'required_if:language,tamil,sinhala|nullable|string|max:255',
            'year_1_breakdown_in_words' => 'required_if:language,tamil,sinhala|nullable|string|max:255',
            'year_2_breakdown_in_words' => 'required_if:language,tamil,sinhala|nullable|string|max:255',
            'year_3_breakdown_in_words' => 'required_if:language,tamil,sinhala|nullable|string|max:255',
            'year_4_breakdown_in_words' => 'required_if:language,tamil,sinhala|nullable|string|max:255',
            'year_5_breakdown_in_words' => 'required_if:language,tamil,sinhala|nullable|string|max:255',
            'date_of_agreement' => 'nullable|date',
            'year_in_words' => 'nullable|string|max:255',
            'year' => 'nullable|string|max:4',
            'witness_01_name' => 'nullable|string|max:255',
            'witness_01_nic' => 'nullable|string|max:50',
            'witness_01_address' => 'nullable|string|max:500',
            'witness_02_name' => 'nullable|string|max:255',
            'witness_02_nic' => 'nullable|string|max:50',
            'witness_02_address' => 'nullable|string|max:500',
            'bank_name' => 'nullable|string|max:255',
            'branch_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:50',
            'beneficiary_full_name' => 'nullable|string|max:255',
            'beneficiary_id_type' => 'nullable|in:nic,passport,driving_license,other',
            'beneficiary_id_number' => 'nullable|string|max:50',
            'beneficiary_phone_primary' => 'nullable|string|max:20',
            'beneficiary_relationship' => 'nullable|string|max:100',
            'beneficiary_share_percentage' => 'nullable|numeric|between:0,100',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $sanitized = [];
        foreach ($this->all() as $key => $value) {
            if (is_string($value) && str_contains($value, '|||METADATA:')) {
                $sanitized[$key] = trim(explode('|||METADATA:', $value)[0]);
            }
        }

        if (!empty($sanitized)) {
            $this->merge($sanitized);
        }
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
