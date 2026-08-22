<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Patient extends Model
{
    use HasFactory;

    protected $fillable = [
        'id_no',
        'sex',
        'age',
        'section',
        'client_request_id',
        'room',
        'ww',
        'lab',
        'burn',
        'notes',
    ];

    protected $casts = [
        'age' => 'integer',
        'ww' => 'boolean',
        'lab' => 'boolean',
        'burn' => 'boolean',
    ];

    /**
     * @param  Builder<Patient>  $query
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $hasIdExact = !empty($filters['id_no_exact']);
        $hasId = !empty($filters['id_no']);
        if ($hasIdExact) {
            $query->where('id_no', $filters['id_no_exact']);
        } elseif ($hasId) {
            $query->where('id_no', 'like', '%'.$filters['id_no'].'%');
        }

        $date = $filters['date'] ?? null;
        $from = $filters['from_date'] ?? null;
        $to = $filters['to_date'] ?? null;
        $hasDateFilter = (bool) ($date || ($from && $to));
        $hasAnyFilter = $hasId || $hasIdExact || $hasDateFilter;

        if ($from && $to) {
            [$start, $end] = self::dayBounds($from, $to);
            return $query->whereBetween('created_at', [$start, $end]);
        }

        if ($date) {
            [$start, $end] = self::dayBounds($date);
            return $query->whereBetween('created_at', [$start, $end]);
        }

        // If searching by ID without a date filter, do NOT constrain by date.
        if (($hasId || $hasIdExact) && !$hasDateFilter) {
            return $query;
        }

        // No filters provided → return all rows (unfiltered table view).
        if (!$hasAnyFilter) {
            return $query;
        }

        [$start, $end] = self::dayBounds(CarbonImmutable::today());
        return $query->whereBetween('created_at', [$start, $end]);
    }

    /**
     * Inclusive calendar-day bounds that keep created_at filters index-friendly.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function dayBounds(mixed $from, mixed $to = null): array
    {
        $start = $from instanceof CarbonImmutable
            ? $from->startOfDay()
            : CarbonImmutable::parse($from)->startOfDay();
        $endSource = $to ?? $from;
        $end = $endSource instanceof CarbonImmutable
            ? $endSource->endOfDay()
            : CarbonImmutable::parse($endSource)->endOfDay();

        return [$start, $end];
    }
}
