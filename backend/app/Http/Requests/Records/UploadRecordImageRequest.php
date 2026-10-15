<?php

namespace App\Http\Requests\Records;

use App\Models\Record;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UploadRecordImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('record');

        return $record instanceof Record
            && ($this->user()?->can('uploadImage', $record) ?? false);
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
            'record_id' => ['prohibited'],
            'file_path' => ['prohibited'],
            'mime_type' => ['prohibited'],
            'file_size' => ['prohibited'],
        ];
    }
}
