<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class History extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'record_id',
        'record_detail_id',
        'delivery_id',
        'user_id',
        'field_name',
        'previous_value',
        'new_value',
        'reason',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    public function recordDetail(): BelongsTo
    {
        return $this->belongsTo(RecordDetail::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
