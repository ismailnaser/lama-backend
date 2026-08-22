<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserAdminApiTest extends TestCase
{
    public function test_nurse_admin_crud_for_nurse_users(): void
    {
        $admin = $this->createUser(['role' => 'nurse_admin', 'username' => 'na1']);
        $token = $this->issueToken($admin);

        $created = $this->apiPost('/api/users', [
            'name' => 'Sara',
            'username' => ' sara1 ',
            'password' => 'secret1',
            'role' => 'nurse',
        ], $token)->assertCreated()->assertJsonPath('data.username', 'sara1');

        $id = $created->json('data.id');
        $this->apiGet('/api/users', $token)
            ->assertOk()
            ->assertJsonFragment(['username' => 'sara1']);

        $this->apiPatch('/api/users/'.$id, ['name' => 'Sara N', 'is_active' => false], $token)
            ->assertOk()
            ->assertJsonPath('data.name', 'Sara N');
        $this->assertFalse((bool) DB::table('users')->where('id', $id)->value('is_active'));

        $this->apiDelete('/api/users/'.$id, $token)->assertNoContent();
    }

    public function test_legacy_user_role_is_normalized_to_nurse(): void
    {
        $token = $this->issueToken($this->createUser(['role' => 'admin']));

        $this->apiPost('/api/users', [
            'name' => 'Legacy',
            'username' => 'legacy1',
            'password' => 'secret1',
            'role' => 'user',
        ], $token)->assertCreated()->assertJsonPath('data.role', 'nurse');
    }

    public function test_duplicate_username_conflicts(): void
    {
        $token = $this->issueToken($this->createUser(['role' => 'nurse_admin', 'username' => 'na1']));
        $this->createUser(['username' => 'taken', 'role' => 'nurse']);

        $this->apiPost('/api/users', [
            'name' => 'X',
            'username' => 'taken',
            'password' => 'secret1',
            'role' => 'nurse',
        ], $token)->assertStatus(409)->assertJsonPath('message', 'Username already exists.');
    }

    public function test_password_must_be_at_least_six_characters(): void
    {
        $token = $this->issueToken($this->createUser(['role' => 'nurse_admin']));

        $this->apiPost('/api/users', [
            'name' => 'X',
            'username' => 'shortpw',
            'password' => '123',
            'role' => 'nurse',
        ], $token)->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_doctor_admin_manages_only_doctor_section(): void
    {
        $token = $this->issueToken($this->createUser(['role' => 'doctor_admin']));

        $this->apiPost('/api/users', [
            'name' => 'Dr A',
            'username' => 'dra',
            'password' => 'secret1',
            'role' => 'doctor',
        ], $token)->assertCreated()->assertJsonPath('data.role', 'doctor');

        $this->apiGet('/api/users', $token)
            ->assertOk()
            ->assertJsonMissing(['role' => 'nurse']);
    }

    public function test_second_admin_can_be_deleted_but_not_the_last(): void
    {
        $a = $this->createUser(['role' => 'nurse_admin', 'username' => 'na1']);
        $b = $this->createUser(['role' => 'nurse_admin', 'username' => 'na2']);
        $token = $this->issueToken($a);

        $this->apiDelete('/api/users/'.$b->id, $token)->assertNoContent();
        $this->apiDelete('/api/users/'.$a->id, $token)
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot delete your own account.');
    }

    public function test_admin_can_read_patient_audits_in_section(): void
    {
        $adminToken = $this->issueToken($this->createUser(['role' => 'nurse_admin']));
        $nurseToken = $this->issueToken($this->createUser(['role' => 'nurse', 'username' => 'n1']));

        $id = $this->apiPost('/api/patients', $this->patientPayload(['id_no' => '128']), $nurseToken)->json('data.id');
        $this->apiPatch('/api/patients/'.$id, ['notes' => 'changed'], $nurseToken)->assertOk();

        $this->apiGet('/api/patients/'.$id.'/audits', $adminToken)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
