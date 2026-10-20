<?php

namespace App\Models;

use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Record extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'source',
        'status',
        'week',
        'year',
        'week_start_date',
        'week_end_date',
    ];

    protected function casts(): array
    {
        return [
            'source' => RecordSource::class,
            'status' => RecordStatus::class,
            'week_start_date' => 'date',
            'week_end_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(RecordDetail::class)->orderBy('order');
    }

    public function image(): HasOne
    {
        return $this->hasOne(Image::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(History::class);
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }
}
