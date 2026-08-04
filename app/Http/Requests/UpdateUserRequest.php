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
            'role' => [
                'sometimes',
                'string',
                'exists:roles,name',
                function ($attribute, $value, $fail) {
                    $currentUser = auth('api')->user();
                    if ($currentUser && $currentUser->hasRole('Branch Coordinator')) {
                        $restrictedRoles = ['Admin', 'Super Admin', 'CEO', 'COO'];
                        if (in_array($value, $restrictedRoles)) {
                            $fail('You are not authorized to assign this role.');
                        }
                    }
                }
            ],

            // Employee Code (Required for hierarchy users)
            'employee_code' => 'sometimes|required_if:user_type,hierarchy|nullable|string|max:255|unique:users,employee_code,' . $id,

            'is_head_office_user' => 'sometimes|boolean',

            'level_id' => 'required_if:user_type,hierarchy|nullable|exists:levels,id',
            'parent_user_id' => [
                'required_if:is_head_office_user,true,1',
                'nullable',
                'exists:users,id',
            ],

            'branch_id' => [
                'required_if:is_head_office_user,true,1',
                'nullable',
                'exists:branches,id',
                function ($attribute, $value, $fail) {
                    $currentUser = auth('api')->user();
                    if ($currentUser && $currentUser->hasRole('Branch Coordinator')) {
                        $assignedBranchIds = $currentUser->assignedBranches()->pluck('branches.id')->toArray();
                        if (!in_array($value, $assignedBranchIds)) {
                            $fail('The selected branch is not assigned to you.');
                        }
                    }
                }
            ],
            'zone_id' => 'nullable|exists:zones,id',
            'region_id' => 'nullable|exists:regions,id',
            'province_id' => 'nullable|exists:provinces,id',

            'profile_image' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'can_login' => 'sometimes|boolean',

            // Identification based on user type (Staff vs Customer)
            'id_type' => 'required_if:user_type,admin,hierarchy|nullable|in:nic,passport,driving_license,other',
            'id_number' => 'required_if:user_type,admin,hierarchy|nullable|string|max:255|unique:users,id_number,' . $id,

            // Branch Coordinator assigned branches
            'assigned_branch_ids' => 'nullable|array',
            'assigned_branch_ids.*' => 'exists:branches,id',
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
