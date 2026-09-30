<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class InvestorLoginRequest extends FormRequest
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
            'login' => 'required_without_all:id_number,email,username|nullable|string',
            'id_number' => 'required_without_all:login,email,username|nullable|string',
            'email' => 'required_without_all:login,id_number,username|nullable|string',
            'username' => 'required_without_all:login,id_number,email|nullable|string',
            'password' => 'required|string',
        ];
    }

    /**
     * Custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'login.required_without_all' => 'Please provide your email or ID number to log in.',
            'id_number.required_without_all' => 'Please provide your email or ID number to log in.',
            'email.required_without_all' => 'Please provide your email or ID number to log in.',
            'username.required_without_all' => 'Please provide your email or ID number to log in.',
            'password.required' => 'Password is required.',
        ];
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

        throw new HttpResponseException(
            response()->json([
                'status' => 'error',
                'message' => $message ?? 'Validation failed.',
                'errors' => $fieldErrors,
            ], 422)
        );
    }
}
