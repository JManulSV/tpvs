<?php

namespace App\Http\Requests\Records;

use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use App\Models\Record;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRecordsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->query('search'))) {
            $this->merge(['search' => trim($this->query('search'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Record::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company_id' => ['sometimes', 'integer', 'min:1', 'exists:companies,id'],
            'year' => ['required_with:week', 'integer', 'between:1,9999'],
            'month' => ['sometimes', 'integer', 'between:1,12'],
            'week' => ['sometimes', 'integer', 'between:1,53'],
            'status' => ['sometimes', Rule::in(array_column(RecordStatus::cases(), 'value'))],
            'source' => ['sometimes', Rule::in(array_column(RecordSource::cases(), 'value'))],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
