<?php

namespace App\Http\Resources;

use App\Models\RecordDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RecordDetail */
class RecordDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'affiliation' => $this->affiliation,
            'bank' => $this->bank,
            'income' => $this->income,
            'order' => $this->order,
        ];
    }
}
