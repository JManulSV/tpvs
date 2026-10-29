<?php

namespace App\Http\Requests\Deliveries;

use App\Domain\Deliveries\DeliveryValidator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpsertDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('prepareDelivery', $this->route('record')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('delivered_by_name'))) {
            $this->merge(['delivered_by_name' => trim($this->input('delivered_by_name'))]);
        }

        if (is_string($this->input('received_by_name'))) {
            $receivedByName = trim($this->input('received_by_name'));

            $this->merge(['received_by_name' => $receivedByName === '' ? null : $receivedByName]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'delivery_date' => ['required', 'date'],
            'delivered_by_name' => ['required', 'string', 'max:255'],
            'received_by_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'denominations' => ['present', 'array'],
            'denominations.*' => ['required', 'array'],
            'denominations.*.value' => ['required', 'string', 'regex:/^\d{1,4}(?:\.\d{1,2})?$/'],
            'denominations.*.quantity' => ['required', 'integer', 'min:0'],
            'prepared_return_amount' => ['prohibited'],
            'record_id' => ['prohibited'],
            'status' => ['prohibited'],
            'source' => ['prohibited'],
            'reason' => ['prohibited'],
            'total' => ['prohibited'],
            'cash_total' => ['prohibited'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $denominations = $this->input('denominations', []);
                if (! is_array($denominations)) {
                    return;
                }

                $validatorService = app(DeliveryValidator::class);
                $seen = [];

                foreach ($denominations as $index => $denomination) {
                    $quantity = (int) ($denomination['quantity'] ?? 0);

                    if ($quantity === 0) {
                        continue;
                    }

                    $value = $validatorService->canonicalDenomination((string) ($denomination['value'] ?? ''));

                    if ($value === null) {
                        $validator->errors()->add(
                            "denominations.{$index}.value",
                            'The denomination is not part of the official list.',
                        );

                        continue;
                    }

                    if (isset($seen[$value])) {
                        $validator->errors()->add(
                            "denominations.{$index}.value",
                            'The denomination is duplicated.',
                        );

                        continue;
                    }

                    $seen[$value] = true;
                }
            },
        ];
    }
}
