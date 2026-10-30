<?php

namespace App\Http\Requests\Reports;

use App\Models\Record;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewReports', Record::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:1,9999'],
            'month' => ['sometimes', 'integer', 'between:1,12'],
            'week' => ['sometimes', 'integer', 'between:1,53'],
            'company_id' => ['sometimes', 'integer', 'min:1', 'exists:companies,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
