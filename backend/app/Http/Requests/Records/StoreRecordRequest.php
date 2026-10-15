<?php

namespace App\Http\Requests\Records;

use App\Models\Record;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Record::class) ?? false;
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
            'week_start_date' => ['required', 'date_format:Y-m-d'],
            'week_end_date' => ['required', 'date_format:Y-m-d'],
            'affiliation' => ['required', 'string', 'max:255'],
            'branch' => ['required', 'string', 'max:255'],
            'details' => ['required', 'array', 'min:1'],
            'details.*' => ['required', 'array'],
            'details.*.operation_number' => ['required', 'string', 'max:255'],
            'details.*.income' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'details.*.commission_percentage' => [
                'sometimes', 'nullable', 'string', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/', 'numeric', 'between:0,100',
            ],
            'details.*.commission' => [
                'sometimes', 'nullable', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/',
            ],
            'source' => ['prohibited'],
            'status' => ['prohibited'],
            'details.*.order' => ['prohibited'],
            'details.*.return_amount' => ['prohibited'],
        ];
    }
}
