<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityApiTest extends TestCase
{
    public function test_protected_routes_require_bearer_token(): void
    {
        $this->getJson('/api/patients')->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
        $this->getJson('/api/auth/me')->assertStatus(401);
        $this->postJson('/api/patients', $this->patientPayload())->assertStatus(401);
        $this->getJson('/api/users')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer ')->getJson('/api/patients')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer not-a-real-token')->getJson('/api/patients')->assertStatus(401);
    }

    public function test_nurse_cannot_see_or_mutate_doctor_patients(): void
    {
        $nurseToken = $this->issueToken($this->createUser(['role' => 'nurse']));
        $doctor = $this->createUser(['role' => 'doctor']);
        $doctorToken = $this->issueToken($doctor);

        $doctorRow = $this->apiPost('/api/patients', $this->patientPayload(['id_no' => '900']), $doctorToken)
            ->assertCreated()
            ->json('data');

        $this->apiGet('/api/patients', $nurseToken)->assertOk()->assertJsonCount(0, 'data');
        $this->apiGet('/api/patients/'.$doctorRow['id'].'/audits', $nurseToken)->assertStatus(403);
        $this->apiPatch('/api/patients/'.$doctorRow['id'], ['notes' => 'hack'], $nurseToken)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Not found.');
        $this->apiDelete('/api/patients/'.$doctorRow['id'], $nurseToken)->assertStatus(404);
        $this->assertSame('900', Patient::query()->find($doctorRow['id'])->id_no);
    }

    public function test_doctor_cannot_see_nurse_patients(): void
    {
        $nurseToken = $this->issueToken($this->createUser(['role' => 'nurse']));
        $doctorToken = $this->issueToken($this->createUser(['role' => 'doctor']));

        $this->apiPost('/api/patients', $this->patientPayload(['id_no' => '100']), $nurseToken)->assertCreated();
        $this->apiGet('/api/patients', $doctorToken)->assertOk()->assertJsonCount(0, 'data');
        $this->apiGet('/api/patients/count', $doctorToken)->assertJsonPath('count', 0);
    }

    public function test_non_admin_cannot_manage_users_or_read_audits(): void
    {
        $nurseToken = $this->issueToken($this->createUser(['role' => 'nurse']));
        $doctorToken = $this->issueToken($this->createUser(['role' => 'doctor']));
        $patientId = $this->apiPost('/api/patients', $this->patientPayload(['id_no' => '110']), $nurseToken)->json('data.id');

        foreach ([$nurseToken, $doctorToken] as $token) {
            $this->apiGet('/api/users', $token)->assertStatus(403)->assertJsonPath('message', 'Forbidden.');
            $this->apiPost('/api/users', [
                'name' => 'X',
                'username' => 'x1',
                'password' => 'secret1',
                'role' => 'nurse',
            ], $token)->assertStatus(403);
        }

        $this->apiGet('/api/patients/'.$patientId.'/audits', $nurseToken)->assertStatus(403);
    }

    public function test_nurse_admin_cannot_create_or_list_doctor_users(): void
    {
        $adminToken = $this->issueToken($this->createUser(['role' => 'nurse_admin']));
        $this->createUser(['role' => 'doctor', 'username' => 'doc1']);

        $this->apiGet('/api/users', $adminToken)
            ->assertOk()
            ->assertJsonMissing(['username' => 'doc1']);

        $this->apiPost('/api/users', [
            'name' => 'Doc',
            'username' => 'docnew',
            'password' => 'secret1',
            'role' => 'doctor',
        ], $adminToken)
            ->assertStatus(403)
            ->assertJsonPath('message', 'You can only create users in your section.');
    }

    public function test_doctor_admin_cannot_manage_nurse_users(): void
    {
        $docAdmin = $this->issueToken($this->createUser(['role' => 'doctor_admin']));
        $nurse = $this->createUser(['role' => 'nurse', 'username' => 'n1']);

        $this->apiPatch('/api/users/'.$nurse->id, ['is_active' => false], $docAdmin)
            ->assertStatus(403);
        $this->apiDelete('/api/users/'.$nurse->id, $docAdmin)->assertStatus(403);
    }

    public function test_cannot_delete_own_account_or_last_admin(): void
    {
        $admin = $this->createUser(['role' => 'nurse_admin', 'username' => 'na1']);
        $token = $this->issueToken($admin);

        $this->apiDelete('/api/users/'.$admin->id, $token)
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot delete your own account.');

        $this->apiPatch('/api/users/'.$admin->id, ['is_active' => false], $token)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot disable the last active admin in this section.');

        $this->apiPatch('/api/users/'.$admin->id, ['role' => 'nurse'], $token)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot demote the last active admin in this section.');
    }

    public function test_password_change_revokes_tokens(): void
    {
        $admin = $this->createUser(['role' => 'nurse_admin']);
        $target = $this->createUser(['role' => 'nurse']);
        $targetToken = $this->issueToken($target);
        $adminToken = $this->issueToken($admin);

        $this->apiPatch('/api/users/'.$target->id, ['password' => 'newpass1'], $adminToken)->assertOk();
        $this->assertSame(0, DB::table('api_tokens')->where('user_id', $target->id)->count());
        $this->apiGet('/api/auth/me', $targetToken)->assertStatus(401);
    }

    public function test_login_is_rate_limited(): void
    {
        $this->createUser(['username' => 'nurse1']);

        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/auth/login', [
                'username' => 'nurse1',
                'password' => 'wrong',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', [
            'username' => 'nurse1',
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_id_no_with_quotes_is_stored_safely_not_executed(): void
    {
        $token = $this->issueToken($this->createUser());
        $payload = $this->patientPayload(['id_no' => "128' OR 1=1 --"]);

        $this->apiPost('/api/patients', $payload, $token)
            ->assertCreated()
            ->assertJsonPath('data.id_no', "128' OR 1=1 --");

        $this->apiGet('/api/patients?id_no_exact='.urlencode("128' OR 1=1 --").'&date='.now()->toDateString(), $token)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_pdf_escapes_html_in_notes(): void
    {
        $token = $this->issueToken($this->createUser());
        $this->apiPost('/api/patients', $this->patientPayload([
            'id_no' => '128',
            'notes' => '<script>alert(1)</script>',
        ]), $token)->assertCreated();

        $pdf = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/patients/pdf?date='.now()->toDateString());
        $pdf->assertOk();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $pdf->getContent());
    }
}
