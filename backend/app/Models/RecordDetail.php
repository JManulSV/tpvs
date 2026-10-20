<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecordDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'record_id',
        'operation_number',
        'affiliation',
        'branch',
        'income',
        'commission_percentage',
        'commission',
        'return_amount',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'income' => 'decimal:2',
            'commission_percentage' => 'decimal:2',
            'commission' => 'decimal:2',
            'return_amount' => 'decimal:2',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(History::class);
    }
}
