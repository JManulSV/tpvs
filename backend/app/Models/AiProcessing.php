<?php

namespace App\Models;

use App\Enums\AiProcessingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class AiProcessing extends Model
{
    use HasFactory;

    protected $fillable = [
        'image_id',
        'provider',
        'model',
        'status',
        'result',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AiProcessingStatus::class,
            'result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }
}
