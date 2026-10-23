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
            'branch' => $this->branch,
            'income' => $this->income,
            'commission_percentage' => $this->commission_percentage,
            'commission' => $this->commission,
            'return_amount' => $this->return_amount,
            'order' => $this->order,
        ];
    }
}
