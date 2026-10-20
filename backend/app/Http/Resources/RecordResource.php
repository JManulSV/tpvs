<?php

namespace App\Http\Resources;

use App\Models\Image;
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
            'image' => $this->whenLoaded('image', static fn (?Image $image): ?array => $image === null ? null : [
                'id' => $image->getKey(),
                'original_file_name' => $image->original_file_name,
                'mime_type' => $image->mime_type,
                'file_size' => (int) $image->file_size,
                'created_at' => $image->created_at?->toISOString(),
            ]),
            'income_total' => $this->when(array_key_exists('income_total', $this->resource->getAttributes()), fn (): ?string => $this->income_total === null ? null : (string) $this->income_total),
            'commission_total' => $this->when(array_key_exists('commission_total', $this->resource->getAttributes()), fn (): ?string => $this->commission_total === null ? null : (string) $this->commission_total),
            'return_total' => $this->when(array_key_exists('return_total', $this->resource->getAttributes()), fn (): ?string => $this->return_total === null ? null : (string) $this->return_total),
            'details' => RecordDetailResource::collection($this->whenLoaded('details')),
        ];
    }
}
