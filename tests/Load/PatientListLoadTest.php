<?php

namespace Tests\Load;

use App\Models\Patient;
use App\Support\PatientSectionCache;
use Illuminate\Support\Benchmark;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PatientListLoadTest extends TestCase
{
    public function test_list_count_and_create_latency_under_load(): void
    {
        $user = $this->createUser(['role' => 'nurse']);
        $token = $this->issueToken($user);
        $today = now()->toDateString();

        Patient::factory()->count(800)->sequence(fn ($seq) => [
            'id_no' => (string) (100000 + $seq->index),
            'section' => 'nurse',
            'room' => 'room1',
            'created_at' => now()->subDays(($seq->index % 13) + 1),
            'updated_at' => now()->subDays(($seq->index % 13) + 1),
        ])->create();

        Patient::factory()->count(200)->sequence(fn ($seq) => [
            'id_no' => (string) (200000 + $seq->index),
            'section' => 'nurse',
            'room' => 'room2',
            'created_at' => now(),
            'updated_at' => now(),
        ])->create();

        Patient::factory()->count(200)->doctor()->sequence(fn ($seq) => [
            'id_no' => (string) (300000 + $seq->index),
        ])->create();

        $listTodayMs = Benchmark::measure(function () use ($token, $today) {
            $this->apiGet('/api/patients?date='.$today, $token)
                ->assertOk()
                ->assertJsonCount(200, 'data');
        });

        $listAllMs = Benchmark::measure(function () use ($token) {
            $this->apiGet('/api/patients', $token)
                ->assertOk()
                ->assertJsonCount(1000, 'data');
        });

        // Wall-clock timings are noise at sub-millisecond scale, so the cache is
        // proven by counting the aggregate queries it removes instead.
        Cache::flush();
        $countCold = $this->measureCountRequest($token);
        $countCached = $this->measureCountRequest($token);
        $countColdMs = $countCold['ms'];
        $countCachedMs = $countCached['ms'];

        $createMs = Benchmark::measure(function () use ($token) {
            $this->apiPost('/api/patients', $this->patientPayload([
                'id_no' => '888001',
            ]), $token)->assertCreated();
        });

        $authRepeatMs = Benchmark::measure(function () use ($token) {
            for ($i = 0; $i < 20; $i++) {
                $this->apiGet('/api/auth/me', $token)->assertOk();
            }
        });

        $payload = [
            'dataset_nurse_rows' => 1000,
            'dataset_doctor_rows' => 200,
            'list_today_ms' => round($listTodayMs, 2),
            'list_all_ms' => round($listAllMs, 2),
            'count_cold_ms' => round($countColdMs, 2),
            'count_cached_ms' => round($countCachedMs, 2),
            'count_cold_aggregate_queries' => $countCold['aggregates'],
            'count_cached_aggregate_queries' => $countCached['aggregates'],
            'create_ms' => round($createMs, 2),
            'auth_me_x20_ms' => round($authRepeatMs, 2),
            'auth_me_avg_ms' => round($authRepeatMs / 20, 2),
            'count_cache_key' => PatientSectionCache::countKey('nurse'),
            'measured_at' => now()->toIso8601String(),
        ];

        $dir = storage_path('app');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir.'/load-test-results.json', json_encode($payload, JSON_PRETTY_PRINT));

        $this->assertLessThan(1500, $listTodayMs, 'Today list should stay interactive with indexes');
        $this->assertLessThan(2500, $listAllMs, 'Unfiltered list of 1000 rows should stay under 2.5s in sqlite');
        $this->assertSame(1, $countCold['aggregates'], 'Cold count must hit the database once');
        $this->assertSame(0, $countCached['aggregates'], 'Cached count must not query the database');
        $this->assertLessThan(800, $createMs);
        $this->assertFileExists($dir.'/load-test-results.json');
    }

    /**
     * @return array{ms: float, aggregates: int}
     */
    private function measureCountRequest(string $token): array
    {
        $aggregates = 0;
        DB::listen(function ($query) use (&$aggregates) {
            if (str_contains(strtolower($query->sql), 'count(*)')) {
                $aggregates++;
            }
        });

        $ms = Benchmark::measure(function () use ($token) {
            $this->apiGet('/api/patients/count', $token)
                ->assertOk()
                ->assertJsonPath('count', 1000);
        });

        // Drop the listener so the next measurement starts clean.
        DB::connection()->flushQueryLog();
        app('events')->forget('Illuminate\Database\Events\QueryExecuted');

        return ['ms' => $ms, 'aggregates' => $aggregates];
    }
}
