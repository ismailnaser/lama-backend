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
        'ww',
    ];

    protected $casts = [
        'age' => 'integer',
    ];

    /**
     * @param  Builder<Patient>  $query
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $hasId = !empty($filters['id_no']);
        if ($hasId) {
            $query->where('id_no', 'like', '%'.$filters['id_no'].'%');
        }

        $date = $filters['date'] ?? null;
        $from = $filters['from_date'] ?? null;
        $to = $filters['to_date'] ?? null;
        $hasDateFilter = (bool) ($date || ($from && $to));
        $hasAnyFilter = $hasId || $hasDateFilter;

        if ($from && $to) {
            $start = CarbonImmutable::parse($from)->startOfDay();
            $end = CarbonImmutable::parse($to)->endOfDay();
            return $query->whereBetween('created_at', [$start, $end]);
        }

        if ($date) {
            $day = CarbonImmutable::parse($date);
            return $query->whereDate('created_at', $day);
        }

        // If searching by ID without a date filter, do NOT constrain by date.
        if ($hasId && !$hasDateFilter) {
            return $query;
        }

        // No filters provided → return all rows (unfiltered table view).
        if (!$hasAnyFilter) {
            return $query;
        }

        return $query->whereDate('created_at', CarbonImmutable::today());
    }
}
