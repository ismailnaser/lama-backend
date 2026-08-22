<?php

namespace Tests\Unit;

use App\Models\Patient;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PatientFilterTest extends TestCase
{
    public function test_day_bounds_are_inclusive_calendar_day(): void
    {
        [$start, $end] = Patient::dayBounds('2026-08-22');
        $this->assertSame('2026-08-22 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-22 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function test_filter_by_exact_id_and_date(): void
    {
        $keep = Patient::factory()->create([
            'id_no' => '128',
            'created_at' => CarbonImmutable::parse('2026-08-22 09:00:00'),
            'updated_at' => CarbonImmutable::parse('2026-08-22 09:00:00'),
        ]);
        Patient::factory()->create([
            'id_no' => '128',
            'created_at' => CarbonImmutable::parse('2026-08-21 09:00:00'),
            'updated_at' => CarbonImmutable::parse('2026-08-21 09:00:00'),
        ]);
        Patient::factory()->create([
            'id_no' => '999',
            'created_at' => CarbonImmutable::parse('2026-08-22 09:00:00'),
            'updated_at' => CarbonImmutable::parse('2026-08-22 09:00:00'),
        ]);

        $ids = Patient::query()
            ->filter(['id_no_exact' => '128', 'date' => '2026-08-22'])
            ->pluck('id')
            ->all();

        $this->assertSame([$keep->id], $ids);
    }

    public function test_partial_id_search_without_date_returns_all_matching_days(): void
    {
        Patient::factory()->create([
            'id_no' => '128',
            'created_at' => CarbonImmutable::parse('2026-01-01 09:00:00'),
            'updated_at' => CarbonImmutable::parse('2026-01-01 09:00:00'),
        ]);
        Patient::factory()->create([
            'id_no' => '1280',
            'created_at' => CarbonImmutable::parse('2026-08-22 09:00:00'),
            'updated_at' => CarbonImmutable::parse('2026-08-22 09:00:00'),
        ]);
        Patient::factory()->create(['id_no' => '777']);

        $this->assertSame(2, Patient::query()->filter(['id_no' => '128'])->count());
    }

    public function test_date_range_is_inclusive(): void
    {
        Patient::factory()->create([
            'id_no' => '101',
            'created_at' => CarbonImmutable::parse('2026-08-01 00:00:01'),
            'updated_at' => CarbonImmutable::parse('2026-08-01 00:00:01'),
        ]);
        Patient::factory()->create([
            'id_no' => '102',
            'created_at' => CarbonImmutable::parse('2026-08-03 23:59:00'),
            'updated_at' => CarbonImmutable::parse('2026-08-03 23:59:00'),
        ]);
        Patient::factory()->create([
            'id_no' => '103',
            'created_at' => CarbonImmutable::parse('2026-08-04 00:00:01'),
            'updated_at' => CarbonImmutable::parse('2026-08-04 00:00:01'),
        ]);

        $this->assertSame(
            2,
            Patient::query()->filter(['from_date' => '2026-08-01', 'to_date' => '2026-08-03'])->count()
        );
    }

    public function test_no_filters_returns_all_rows(): void
    {
        Patient::factory()->count(3)->create();
        $this->assertSame(3, Patient::query()->filter([])->count());
    }
}
