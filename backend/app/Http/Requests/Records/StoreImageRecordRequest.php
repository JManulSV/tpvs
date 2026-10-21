<?php

namespace App\Http\Requests\Records;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreImageRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                File::types(['image/jpeg', 'image/png', 'image/webp'])->max(10240),
            ],
            'company_id' => ['prohibited'],
            'source' => ['prohibited'],
            'status' => ['prohibited'],
            'year' => ['prohibited'],
            'week' => ['prohibited'],
            'week_start_date' => ['prohibited'],
            'week_end_date' => ['prohibited'],
            'details' => ['prohibited'],
            'income' => ['prohibited'],
            'commission' => ['prohibited'],
            'commission_percentage' => ['prohibited'],
            'return_amount' => ['prohibited'],
        ];
    }
}
