<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function createUser(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    protected function issueToken(User $user, bool $remember = false, ?\DateTimeInterface $expiresAt = null): string
    {
        $plain = 'testtok_'.bin2hex(random_bytes(12));
        DB::table('api_tokens')->insert([
            'user_id' => $user->id,
            'name' => $remember ? 'web_remember' : 'web',
            'token_hash' => hash('sha256', $plain),
            'last_used_at' => now(),
            'expires_at' => $expiresAt ?? ($remember ? now()->addDays(30) : now()->addHours(12)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $plain;
    }

    protected function patientPayload(array $overrides = []): array
    {
        return array_merge([
            'id_no' => '128',
            'sex' => 'M',
            'age' => 22,
            'room' => 'room1',
            'ww' => false,
            'lab' => false,
            'burn' => false,
            'notes' => null,
        ], $overrides);
    }

    protected function apiGet(string $uri, string $token): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson($uri);
    }

    protected function apiPost(string $uri, array $data, string $token): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson($uri, $data);
    }

    protected function apiPatch(string $uri, array $data, string $token): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->patchJson($uri, $data);
    }

    protected function apiDelete(string $uri, string $token): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->deleteJson($uri);
    }
}
