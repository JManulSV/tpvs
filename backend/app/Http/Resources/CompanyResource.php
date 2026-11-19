<?php

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    /**
     * @return array{id: int|string, name: string, records_count?: int, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'records_count' => $this->when(
                isset($this->records_count),
                fn (): int => (int) $this->records_count,
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
