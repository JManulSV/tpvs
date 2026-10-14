<?php

namespace App\Http\Requests\Companies;

use App\Models\Company;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexCompaniesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->query('search'))) {
            $this->merge(['search' => trim($this->query('search'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Company::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
