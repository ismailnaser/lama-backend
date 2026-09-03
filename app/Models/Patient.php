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

    public static function clinicTimezone(): string
    {
        $tz = trim((string) config('app.clinic_timezone', 'Asia/Gaza'));
        return $tz !== '' ? $tz : 'Asia/Gaza';
    }

    /**
     * Inclusive clinic-calendar-day bounds, returned in UTC for timestamp columns.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function dayBounds(mixed $from, mixed $to = null): array
    {
        $tz = self::clinicTimezone();
        $start = self::toClinicDay($from, $tz)->startOfDay()->utc();
        $endSource = $to ?? $from;
        $end = self::toClinicDay($endSource, $tz)->endOfDay()->utc();

        return [$start, $end];
    }

    private static function toClinicDay(mixed $value, string $tz): CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::parse($value)->timezone($tz);
        }
        $s = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return CarbonImmutable::parse($s, $tz);
        }
        return CarbonImmutable::parse($s)->timezone($tz);
    }
}
