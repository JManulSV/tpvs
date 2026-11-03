<?php

namespace App\Http\Resources;

use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Record */
class ReportRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'company' => [
                'id' => $this->company->getKey(),
                'name' => $this->company->name,
            ],
            'year' => $this->year,
            'week' => $this->week,
            'week_start_date' => $this->week_start_date?->toDateString(),
            'week_end_date' => $this->week_end_date?->toDateString(),
            'details_count' => (int) $this->details_count,
            'income_total' => (string) ($this->income_total ?? '0.00'),
            'commission_total' => (string) ($this->commission_total ?? '0.00'),
            'return_total' => (string) ($this->return_total ?? '0.00'),
            'delivery_date' => $this->delivery?->delivery_date?->toDateString(),
        ];
    }
}
