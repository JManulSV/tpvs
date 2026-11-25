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
            'week_start_date' => ['required', 'date'],
            'week_end_date' => ['required', 'date'],
            'commission_percentage' => ['required', 'string', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/', 'numeric', 'between:0,100'],
            'details' => ['required', 'array', 'min:1'],
            'details.*' => ['required', 'array'],
            'details.*.affiliation' => ['required', 'string', 'max:255'],
            'details.*.bank' => ['required', 'string', 'max:255'],
            'details.*.income' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'source' => ['prohibited'],
            'status' => ['prohibited'],
            'affiliation' => ['missing'],
            'branch' => ['prohibited'],
            // Derived by Laravel on the Record (SPEC 27): the client never sends them.
            'commission' => ['prohibited'],
            'return_amount' => ['prohibited'],
            'details.*.order' => ['prohibited'],
            'details.*.branch' => ['prohibited'],
            'details.*.commission_percentage' => ['prohibited'],
            'details.*.commission' => ['prohibited'],
            'details.*.return_amount' => ['prohibited'],
        ];
    }
}
