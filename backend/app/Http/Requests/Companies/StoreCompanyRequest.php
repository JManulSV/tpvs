<?php

namespace App\Http\Requests\Companies;

use App\Models\Company;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Company::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $name = $this->input('name');

            if (! is_string($name) || $name === '') {
                return;
            }

            $exists = Company::query()
                ->whereRaw('LOWER(BTRIM(name)) = LOWER(BTRIM(?))', [$name])
                ->exists();

            if ($exists) {
                $validator->errors()->add('name', 'A company with this name already exists.');
            }
        }];
    }
}
