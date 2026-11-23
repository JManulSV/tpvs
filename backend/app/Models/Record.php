<?php

namespace App\Models;

use App\Enums\RecordSource;
use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Record extends Model
{
    use HasFactory;

    /** Relations that RecordResource renders. */
    private const RESOURCE_RELATIONS = ['company', 'details', 'image.aiProcessing'];

    /** Aggregate attribute => detail column summed by RecordResource. */
    private const RESOURCE_SUMS = [
        'income_total' => 'income',
        'commission_total' => 'commission',
        'return_total' => 'return_amount',
    ];

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

    /** Lock the Record row for the rest of the current transaction. */
    public static function findLockedOrFail(int $id): self
    {
        return self::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Eager load everything RecordResource needs.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithResourceAggregates(Builder $query): void
    {
        $query->with(self::RESOURCE_RELATIONS);

        foreach (self::RESOURCE_SUMS as $attribute => $column) {
            $query->withSum("details as {$attribute}", $column);
        }
    }

    /** Load everything RecordResource needs onto an already retrieved Record. */
    public function loadResourceAggregates(): static
    {
        $this->load(self::RESOURCE_RELATIONS);

        foreach (self::RESOURCE_SUMS as $attribute => $column) {
            $this->loadSum("details as {$attribute}", $column);
        }

        return $this;
    }
}
