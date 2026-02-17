<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateCustomerRequest extends FormRequest
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
            'customer_id' => 'nullable|exists:users,id',
            'full_name' => 'required|string|max:255',
            'name_with_initials' => 'required|string|max:255',
            'customer_code' => 'required|string|max:255|unique:customers,customer_code',
            'id_type' => 'required|in:nic,passport,driving_license,other',
            'id_number' => 'required|string|max:255|unique:customers,id_number',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'landmark' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'date_of_birth' => 'required|date',
            'phone_primary' => 'required|string|max:20',
            'phone_secondary' => 'nullable|string|max:20',
            'have_whatsapp' => 'boolean',
            'whatsapp_number' => 'nullable|required_if:have_whatsapp,true|string|max:20',
            'preferred_language' => 'required|in:english,sinhala,tamil',
            'employment_status' => 'required|in:employed,self_employed,business,unemployed,retired,student',
            'occupation' => 'nullable|string|max:255',
            'employer_name' => 'nullable|string|max:255',
            'employer_address_line1' => 'nullable|string|max:255',
            'employer_address_line2' => 'nullable|string|max:255',
            'employer_city' => 'nullable|string|max:255',
            'employer_state' => 'nullable|string|max:255',
            'employer_country' => 'nullable|string|max:255',
            'employer_postal_code' => 'nullable|string|max:20',
            'employer_phone' => 'nullable|string|max:20',
            'employer_email' => 'nullable|email|max:255',
            'business_name' => 'nullable|string|max:255',
            'business_registration_number' => 'nullable|string|max:255',
            'business_nature' => 'nullable|string|max:255',
            'business_address_line1' => 'nullable|string|max:255',
            'business_address_line2' => 'nullable|string|max:255',
            'business_city' => 'nullable|string|max:255',
            'business_state' => 'nullable|string|max:255',
            'business_country' => 'nullable|string|max:255',
            'business_postal_code' => 'nullable|string|max:20',
            'business_phone' => 'nullable|string|max:20',
            'business_email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
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
