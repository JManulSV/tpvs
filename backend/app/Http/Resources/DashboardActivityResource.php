<?php

namespace App\Http\Resources;

use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Record */
class DashboardActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'company' => $this->company === null ? null : [
                'id' => $this->company->getKey(),
                'name' => $this->company->name,
            ],
            'year' => $this->year,
            'week' => $this->week,
            'status' => $this->status->value,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
