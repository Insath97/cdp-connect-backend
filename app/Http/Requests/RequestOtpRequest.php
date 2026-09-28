<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RequestOtpRequest extends FormRequest
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
            'id_type' => 'sometimes|nullable|string|in:nic,passport,driving_license,other',
            'id_number' => 'required|string|max:100',
        ];
    }

    /**
     * Custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'id_number.required' => 'Customer identity document number is required.',
            'id_number.string' => 'Customer identity document number must be a valid string.',
            'id_number.max' => 'Customer identity document number cannot exceed 100 characters.',
            'id_type.in' => 'Identity document type must be one of: nic, passport, driving_license, other.',
        ];
    }

    /**
     * Configure the validator instance with custom identity document format validation.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();
            $idNumber = trim((string) ($data['id_number'] ?? $this->input('id_number', '')));
            $idType = strtolower(trim((string) ($data['id_type'] ?? $this->input('id_type', ''))));

            if ($idNumber === '') {
                return;
            }

            if (!empty($idType)) {
                switch ($idType) {
                    case 'nic':
                        // Old NIC: 9 digits + V/v/X/x | New NIC: 12 digits
                        if (!preg_match('/^([0-9]{9}[vVxX]|[0-9]{12})$/', $idNumber)) {
                            $validator->errors()->add('id_number', 'The ID number must be a valid National Identity Card (NIC) format (e.g., 123456789V or 199012345678).');
                        }
                        break;

                    case 'passport':
                        // Standard passport format: 6 to 20 alphanumeric characters
                        if (!preg_match('/^[a-zA-Z0-9]{6,20}$/', $idNumber)) {
                            $validator->errors()->add('id_number', 'The ID number must be a valid Passport number (6 to 20 alphanumeric characters, e.g., N1234567).');
                        }
                        break;

                    case 'driving_license':
                        // Sri Lankan & international DL: 6 to 20 alphanumeric characters with optional hyphen
                        if (!preg_match('/^[a-zA-Z0-9\-]{6,20}$/', $idNumber)) {
                            $validator->errors()->add('id_number', 'The ID number must be a valid Driving License number (e.g., B1234567 or 12345678).');
                        }
                        break;

                    case 'other':
                        // Official document ID: 3 to 50 characters, letters, numbers, hyphens, slashes
                        if (!preg_match('/^[a-zA-Z0-9\/\-\s]{3,50}$/', $idNumber)) {
                            $validator->errors()->add('id_number', 'The ID number must be a valid identity document number (3 to 50 alphanumeric characters).');
                        }
                        break;
                }
            } else {
                // If id_type is not specified, validate that id_number satisfies at least one valid identity type
                $isNic = (bool) preg_match('/^([0-9]{9}[vVxX]|[0-9]{12})$/', $idNumber);
                $isPassport = (bool) preg_match('/^[a-zA-Z0-9]{6,20}$/', $idNumber);
                $isDl = (bool) preg_match('/^[a-zA-Z0-9\-]{6,20}$/', $idNumber);
                $isOther = (bool) preg_match('/^[a-zA-Z0-9\/\-\s]{3,50}$/', $idNumber);

                if (!$isNic && !$isPassport && !$isDl && !$isOther) {
                    $validator->errors()->add('id_number', 'The ID number format is invalid. Please enter a valid NIC, Passport, Driving License, or registered document number.');
                }
            }
        });
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        $errorMessages = $validator->errors();

        $fieldErrors = collect($errorMessages->getMessages())->map(function ($messages, $field) {
            return [
                'field' => $field,
                'messages' => $messages,
            ];
        })->values();

        $message = $validator->errors()->first();

        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => $message,
            'errors' => $fieldErrors,
        ], 422));
    }
}
