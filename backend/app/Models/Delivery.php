<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'record_id',
        'delivery_date',
        'delivered_by_name',
        'received_by_name',
        'prepared_return_amount',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'prepared_return_amount' => 'decimal:2',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    public function cashDenominations(): HasMany
    {
        return $this->hasMany(DeliveryCashDenomination::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(History::class);
    }
}
