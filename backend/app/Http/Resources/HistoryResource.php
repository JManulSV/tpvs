<?php

namespace App\Http\Resources;

use App\Models\History;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin History */
class HistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'field_name' => $this->field_name,
            'previous_value' => $this->previous_value,
            'new_value' => $this->new_value,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toISOString(),
            'user' => $this->whenLoaded('user', static fn (User $user): array => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ]),
        ];
    }
}
