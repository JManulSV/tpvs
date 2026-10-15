<?php

namespace App\Http\Resources;

use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Record */
class RecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'company' => CompanyResource::make($this->whenLoaded('company')),
            'source' => $this->source->value,
            'status' => $this->status->value,
            'year' => $this->year,
            'week' => $this->week,
            'week_start_date' => $this->week_start_date?->toDateString(),
            'week_end_date' => $this->week_end_date?->toDateString(),
            'affiliation' => $this->affiliation,
            'branch' => $this->branch,
            'income_total' => $this->when(isset($this->income_total), fn (): string => (string) $this->income_total),
            'commission_total' => $this->when(isset($this->commission_total), fn (): string => (string) $this->commission_total),
            'return_total' => $this->when(isset($this->return_total), fn (): string => (string) $this->return_total),
            'details' => RecordDetailResource::collection($this->whenLoaded('details')),
        ];
    }
}
