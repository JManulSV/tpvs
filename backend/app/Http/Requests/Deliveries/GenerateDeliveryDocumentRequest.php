<?php

namespace App\Http\Requests\Deliveries;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GenerateDeliveryDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $delivery = $this->route('delivery');

        return $delivery !== null
            && ($this->user()?->can('generateDeliveryDocument', $delivery->record) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'document_return_amount' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'customer_will_give_change' => ['required', 'boolean'],
            'change_amount' => ['prohibited'],
        ];
    }
}
