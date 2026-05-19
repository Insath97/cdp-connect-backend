<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLegalRequest extends FormRequest
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
            'date_of_agreement' => 'nullable|date',
            'year_in_words' => 'nullable|string|max:255',
            'year' => 'nullable|string|max:4',
            'witness_01_name' => 'nullable|string|max:255',
            'witness_01_nic' => 'nullable|string|max:50',
            'witness_01_address' => 'nullable|string|max:500',
            'witness_02_name' => 'nullable|string|max:255',
            'witness_02_nic' => 'nullable|string|max:50',
            'witness_02_address' => 'nullable|string|max:500',
        ];
    }
}
