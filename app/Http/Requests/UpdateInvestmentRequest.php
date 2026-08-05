<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvestmentRequest extends FormRequest
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
        $id = $this->route('investment');
        $investment = \App\Models\Investment::findOrFail($id);
        $hasExistingProof = $investment && !empty($investment->payment_proof);

        return [
            'application_number' => 'sometimes|string|unique:investments,application_number,' . $id,
            'sales_code' => ['sometimes', 'string', 'unique:investments,sales_code,' . $id],
            'reservation_date' => 'sometimes|date',
            'customer_id' => 'sometimes|exists:customers,id',
            'branch_id' => 'sometimes|exists:branches,id',
            'investment_product_id' => 'sometimes|exists:investment_products,id',
            'beneficiary_id' => [
                'nullable',
                'exists:beneficiaries,id',
                function ($attribute, $value, $fail) {
                    $currentUser = auth('api')->user();
                    if ($currentUser && ($currentUser->hasPermissionTo('Allow Hierarchy As Beneficiary') || $currentUser->can('Allow Hierarchy As Beneficiary'))) {
                        return;
                    }
                    $beneficiary = \App\Models\Beneficiary::find($value);
                    if ($beneficiary) {
                        $userExists = \App\Models\User::where('id_type', $beneficiary->id_type)
                            ->where('id_number', $beneficiary->id_number)
                            ->whereIn('user_type', ['admin', 'hierarchy'])
                            ->exists();
                        if ($userExists) {
                            $fail('The selected beneficiary cannot be a staff or hierarchy member.');
                        }
                    }
                }
            ],
            'customer_bank_detail_id' => 'nullable|exists:customer_bank_details,id',

            // Nested Beneficiary Data (Optional update)
            'beneficiary' => 'nullable|array',
            'beneficiary.full_name' => 'required_with:beneficiary|string|max:255',
            'beneficiary.type' => 'sometimes|required_with:beneficiary|in:adult,child',
            'beneficiary.id_type' => 'required_with:beneficiary|in:nic,passport,driving_license,other',
            'beneficiary.id_number' => [
                'required_with:beneficiary',
                'string',
                'max:50',
                function ($attribute, $value, $fail) {
                    $currentUser = auth('api')->user();
                    if ($currentUser && ($currentUser->hasPermissionTo('Allow Hierarchy As Beneficiary') || $currentUser->can('Allow Hierarchy As Beneficiary'))) {
                        return;
                    }
                    $idType = $this->input('beneficiary.id_type');
                    if ($idType && $value) {
                        $userExists = \App\Models\User::where('id_type', $idType)
                            ->where('id_number', $value)
                            ->whereIn('user_type', ['admin', 'hierarchy'])
                            ->exists();
                        if ($userExists) {
                            $fail('The beneficiary cannot be a staff or hierarchy member.');
                        }
                    }
                }
            ],
            'beneficiary.phone_primary' => 'required_with:beneficiary|string|max:20',
            'beneficiary.relationship' => 'required_with:beneficiary|string|max:100',
            'beneficiary.share_percentage' => 'required_with:beneficiary|numeric|min:0|max:100',
            'beneficiary.id_image' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'beneficiary.child_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',

            // Nested Bank Detail Data (Optional update)
            'bank_detail' => 'nullable|array',
            'bank_detail.bank_name' => 'required_with:bank_detail|string|max:255',
            'bank_detail.branch_name' => 'required_with:bank_detail|string|max:255',
            'bank_detail.account_number' => 'required_with:bank_detail|string|max:50',
            'bank_detail.payment_method' => 'required_with:bank_detail|in:bank_transfer,cheque,cash',

            'business_type' => 'sometimes|in:counter_business,bank_deposit,special',
            'special_business_description' => 'nullable|string',
            'investment_amount' => 'sometimes|numeric|min:0',
            'bank' => 'sometimes|in:HNB,Sampath,Commercial Bank,Peoples Bank,NSB,Other',
            'payment_type' => 'sometimes|in:full_payment,monthly',
            'payment_description' => 'nullable|string',
            'payment_proof' => ($hasExistingProof ? 'nullable' : 'required_if:business_type,bank_deposit') . '|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'initial_payment' => 'sometimes|numeric|min:0',
            'initial_payment_date' => 'nullable|date',
            'monthly_payment_amount' => 'nullable|numeric|min:0',
            'monthly_payment_date' => 'nullable|date',
            'unit_head_id' => 'sometimes|exists:users,id',
            'notes' => 'nullable|string',
            'status' => 'sometimes|in:pending,approved,rejected',
            'signature_document' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
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

        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
            'status' => 'error',
            'message' => $message,
            'errors' => $fieldErrors,
        ], 422));
    }
}
