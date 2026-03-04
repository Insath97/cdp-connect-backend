<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        $id = $this->route('user');
        return [
            'name' => 'sometimes|string|max:255',
            'username' => 'sometimes|string|max:255|unique:users,username,' . $id,
            'email' => 'sometimes|email|max:255|unique:users,email,' . $id,
            'password' => 'sometimes|string|min:8',
            'user_type' => 'sometimes|in:admin,hierarchy,customer',
            'role' => 'sometimes|string|exists:roles,name',

            'level_id' => 'required_if:user_type,hierarchy|nullable|exists:levels,id',
            'parent_user_id' => 'nullable|exists:users,id',

            'branch_id' => 'nullable|exists:branches,id',
            'zone_id' => 'nullable|exists:zones,id',
            'region_id' => 'nullable|exists:regions,id',
            'province_id' => 'nullable|exists:provinces,id',

            'profile_image' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'can_login' => 'sometimes|boolean',

            // Identification based on user type (Staff vs Customer)
            'id_type' => 'required_if:user_type,admin,hierarchy|nullable|in:nic,passport,driving_license,other',
            'id_number' => 'required_if:user_type,admin,hierarchy|nullable|string|max:255|unique:users,id_number,' . $id,
        ];
    }

    public function bodyParameters()
    {
        return [];
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
