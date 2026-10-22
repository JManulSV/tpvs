<?php

namespace App\Http\Resources;

use App\Models\AiProcessing;
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
            'ai_processing' => $this->whenLoaded('image', static function (?Image $image): ?array {
                if ($image === null || ! $image->relationLoaded('aiProcessing') || $image->aiProcessing === null) {
                    return null;
                }

                /** @var AiProcessing $processing */
                $processing = $image->aiProcessing;
                $result = $processing->result ?? [];

                return [
                    'status' => $processing->status->value,
                    'provider' => $processing->provider,
                    'model' => $processing->model,
                    'started_at' => $processing->started_at?->toISOString(),
                    'completed_at' => $processing->completed_at?->toISOString(),
                    'normalized_extraction' => $result['normalized_extraction'] ?? null,
                    'issues' => $result['issues'] ?? [],
                    'unconvertible_numeric_count' => $result['unconvertible_numeric_count'] ?? 0,
                ];
            }),
            'income_total' => $this->when(isset($this->income_total), fn (): string => (string) $this->income_total),
            'commission_total' => $this->when(isset($this->commission_total), fn (): string => (string) $this->commission_total),
            'return_total' => $this->when(isset($this->return_total), fn (): string => (string) $this->return_total),
            'details' => RecordDetailResource::collection($this->whenLoaded('details')),
        ];
    }
}
