<?php

namespace App\Http\Requests\Records;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRecordRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('record')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'min:1', 'exists:companies,id'],
            'year' => ['required', 'integer', 'between:1,9999'],
            'week' => ['required', 'integer', 'between:1,53'],
            'week_start_date' => ['required', 'date'],
            'week_end_date' => ['required', 'date'],
            'details' => ['required', 'array', 'min:1'],
            'details.*' => ['required', 'array'],
            'details.*.affiliation' => ['required', 'string', 'max:255'],
            'details.*.branch' => ['required', 'string', 'max:255'],
            'details.*.income' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'details.*.commission_percentage' => [
                'sometimes', 'nullable', 'string', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/', 'numeric', 'between:0,100',
            ],
            'details.*.commission' => [
                'sometimes', 'nullable', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/',
            ],
            'reason' => ['required', 'string', 'max:2000'],
            'source' => ['prohibited'],
            'status' => ['prohibited'],
            'affiliation' => ['missing'],
            'branch' => ['missing'],
            'details.*.order' => ['prohibited'],
            'details.*.return_amount' => ['prohibited'],
        ];
    }
}
