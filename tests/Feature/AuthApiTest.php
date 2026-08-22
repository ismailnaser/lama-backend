<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    public function test_login_success_returns_token_and_user(): void
    {
        $user = $this->createUser(['username' => 'nurse1', 'role' => 'nurse']);

        $this->postJson('/api/auth/login', [
            'username' => 'nurse1',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.username', 'nurse1')
            ->assertJsonPath('user.role', 'nurse')
            ->assertJsonPath('user.is_active', true)
            ->assertJsonMissingPath('user.password')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'username', 'role']]);

        $this->assertSame(1, DB::table('api_tokens')->where('user_id', $user->id)->count());
        $this->assertSame('web', DB::table('api_tokens')->where('user_id', $user->id)->value('name'));
    }

    public function test_remember_me_issues_longer_lived_token(): void
    {
        $this->createUser(['username' => 'nurse1']);

        $this->postJson('/api/auth/login', [
            'username' => '  nurse1  ',
            'password' => 'password',
            'remember_me' => true,
        ])->assertOk();

        $row = DB::table('api_tokens')->first();
        $this->assertSame('web_remember', $row->name);
        $this->assertTrue(now()->diffInDays($row->expires_at) >= 29);
    }

    public function test_login_rejects_bad_credentials_with_same_message(): void
    {
        $this->createUser(['username' => 'nurse1']);

        $this->postJson('/api/auth/login', [
            'username' => 'missing',
            'password' => 'password',
        ])->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.');

        $this->postJson('/api/auth/login', [
            'username' => 'nurse1',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_login_rejects_disabled_account(): void
    {
        $this->createUser(['username' => 'nurse1', 'is_active' => false]);

        $this->postJson('/api/auth/login', [
            'username' => 'nurse1',
            'password' => 'password',
        ])->assertStatus(422)->assertJsonPath('message', 'Account is disabled.');
    }

    public function test_login_validates_required_fields(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_me_and_logout(): void
    {
        $user = $this->createUser(['username' => 'nurse1']);
        $token = $this->issueToken($user);

        $this->apiGet('/api/auth/me', $token)
            ->assertOk()
            ->assertJsonPath('user.username', 'nurse1')
            ->assertJsonMissingPath('user.password');

        $this->apiPost('/api/auth/logout', [], $token)->assertNoContent();
        $this->assertSame(0, DB::table('api_tokens')->count());

        $this->apiGet('/api/auth/me', $token)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_me_rejects_expired_token(): void
    {
        $user = $this->createUser();
        $token = $this->issueToken($user, false, now()->subMinute());

        $this->apiGet('/api/auth/me', $token)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token expired.');
        $this->assertSame(0, DB::table('api_tokens')->count());
    }

    public function test_disabled_user_token_is_revoked(): void
    {
        $user = $this->createUser(['is_active' => false]);
        $token = $this->issueToken($user);

        $this->apiGet('/api/auth/me', $token)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Account is disabled.');
        $this->assertSame(0, DB::table('api_tokens')->count());
    }
}
