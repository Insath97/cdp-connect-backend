<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateTargetRequest extends FormRequest
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
        $id = $this->route('target');
        return [
            'user_id' => 'sometimes|exists:users,id',
            'period_type' => 'sometimes|in:month,quarter,year',
            'period_key' => [
                'sometimes',
                'string',
                Rule::unique('targets')->where(function ($query) use ($id) {
                    // Check logic slightly complex for update uniqueness, strict check might be needed if user_id changes
                    // If user_id is not passed, use existing. If period_type not passed, use existing.
                    // This simple rule assumes if period_key changes, we check against input user_id or existing.
                    // For simplicity, we might relax update uniqueness check or handle it more manually if needed.
                    // But standard unique ignore works for ID. The composite unique is harder.
                    // Let's rely on basic check for now.
                    return $query->where('user_id', $this->input('user_id') ?? \App\Models\Target::find($this->route('target'))->user_id)
                        ->where('period_type', $this->input('period_type') ?? \App\Models\Target::find($this->route('target'))->period_type);
                })->ignore($id),
            ],
            'target_amount' => 'sometimes|numeric|min:0',
            'achieved_amount' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:active,achieved,expired',
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
