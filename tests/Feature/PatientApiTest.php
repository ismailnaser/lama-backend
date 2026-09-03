<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\PatientAuditLog;
use App\Models\User;
use App\Support\PatientSectionCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PatientApiTest extends TestCase
{
    public function test_nurse_can_create_list_update_and_delete_patient(): void
    {
        $nurse = $this->createUser(['role' => 'nurse']);
        $token = $this->issueToken($nurse);

        $created = $this->apiPost('/api/patients', $this->patientPayload([
            'id_no' => '128',
            'notes' => 'first dressing',
            'ww' => true,
        ]), $token);
        $created->assertCreated()->assertJsonPath('data.id_no', '128')->assertJsonPath('data.section', 'nurse');
        $id = $created->json('data.id');

        $this->apiGet('/api/patients?date='.now()->toDateString(), $token)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.created_by', $nurse->username);

        $this->apiGet('/api/patients/count', $token)
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->apiPatch('/api/patients/'.$id, ['notes' => 'updated', 'lab' => true], $token)
            ->assertOk()
            ->assertJsonPath('data.notes', 'updated')
            ->assertJsonPath('data.lab', true);

        $this->assertSame(2, PatientAuditLog::query()->where('patient_id', $id)->count());

        $this->apiDelete('/api/patients/'.$id, $token)->assertNoContent();
        $this->assertDatabaseMissing('patients', ['id' => $id]);
    }

    public function test_create_validation_failures(): void
    {
        $token = $this->issueToken($this->createUser());

        $this->apiPost('/api/patients', [], $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_no', 'sex', 'age', 'room']);

        $this->apiPost('/api/patients', $this->patientPayload(['sex' => 'X', 'age' => 200, 'room' => 'room9']), $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sex', 'age', 'room']);
    }

    public function test_duplicate_id_same_day_returns_409_with_existing_row(): void
    {
        $token = $this->issueToken($this->createUser());
        $first = $this->apiPost('/api/patients', $this->patientPayload(['id_no' => '128']), $token)->assertCreated();

        $this->apiPost('/api/patients', $this->patientPayload(['id_no' => '128', 'notes' => 'second']), $token)
            ->assertStatus(409)
            ->assertJsonPath('message', 'This ID number is already registered on this date.')
            ->assertJsonPath('data.id', $first->json('data.id'));
    }

    public function test_same_id_on_another_day_is_allowed(): void
    {
        $token = $this->issueToken($this->createUser());
        $this->apiPost('/api/patients', $this->patientPayload([
            'id_no' => '128',
            'recorded_at' => '2026-08-01T10:00:00',
        ]), $token)->assertCreated();

        $this->apiPost('/api/patients', $this->patientPayload([
            'id_no' => '128',
            'recorded_at' => '2026-08-02T10:00:00',
        ]), $token)->assertCreated();

        $this->assertSame(2, Patient::query()->count());
    }

    public function test_same_id_next_clinic_day_is_allowed_across_utc_midnight(): void
    {
        $token = $this->issueToken($this->createUser());
        $this->apiPost('/api/patients', $this->patientPayload([
            'id_no' => '128',
            'recorded_at' => '2026-09-02T12:00:00Z',
        ]), $token)->assertCreated();

        $this->apiPost('/api/patients', $this->patientPayload([
            'id_no' => '128',
            'recorded_at' => '2026-09-02T22:00:00Z',
        ]), $token)->assertCreated();

        $this->assertSame(2, Patient::query()->count());
    }

    public function test_client_request_id_is_idempotent(): void
    {
        $token = $this->issueToken($this->createUser());
        $rid = 'offline-abc-123';

        $first = $this->apiPost('/api/patients', $this->patientPayload([
            'id_no' => '201',
            'client_request_id' => $rid,
        ]), $token)->assertCreated();

        $this->apiPost('/api/patients', $this->patientPayload([
            'id_no' => '201',
            'client_request_id' => $rid,
            'notes' => 'retry after timeout',
        ]), $token)
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, Patient::query()->count());
    }

    public function test_cannot_mass_assign_section_from_request(): void
    {
        $token = $this->issueToken($this->createUser(['role' => 'nurse']));

        $this->apiPost('/api/patients', $this->patientPayload([
            'id_no' => '333',
            'section' => 'doctor',
        ]), $token)->assertCreated()->assertJsonPath('data.section', 'nurse');
    }

    public function test_filters_and_invalid_filter_combinations(): void
    {
        $token = $this->issueToken($this->createUser());
        $this->apiPost('/api/patients', $this->patientPayload(['id_no' => '128', 'recorded_at' => '2026-08-10T08:00:00']), $token)->assertCreated();
        $this->apiPost('/api/patients', $this->patientPayload(['id_no' => '129', 'recorded_at' => '2026-08-12T08:00:00']), $token)->assertCreated();

        $this->apiGet('/api/patients?date=2026-08-10', $token)->assertOk()->assertJsonCount(1, 'data');
        $this->apiGet('/api/patients?from_date=2026-08-10&to_date=2026-08-12', $token)->assertOk()->assertJsonCount(2, 'data');
        $this->apiGet('/api/patients?id_no_exact=128&date=2026-08-10', $token)->assertOk()->assertJsonCount(1, 'data');

        $this->apiGet('/api/patients?from_date=2026-08-10', $token)->assertStatus(422);
        $this->apiGet('/api/patients?from_date=2026-08-12&to_date=2026-08-10', $token)->assertStatus(422);
        $this->apiGet('/api/patients?date=2026-08-10&from_date=2026-08-10&to_date=2026-08-12', $token)->assertStatus(422);
    }

    public function test_excel_export_is_csv_with_bom_and_pdf_downloads(): void
    {
        $token = $this->issueToken($this->createUser());
        $this->apiPost('/api/patients', $this->patientPayload(['id_no' => '128', 'notes' => 'ok']), $token)->assertCreated();

        $excel = $this->withHeader('Authorization', 'Bearer '.$token)->get('/api/patients/excel?date='.now()->toDateString());
        $excel->assertOk();
        $this->assertStringContainsString('text/csv', (string) $excel->headers->get('content-type'));
        $body = $excel->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('128', $body);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/patients/pdf?date='.now()->toDateString())
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_count_cache_invalidates_after_create_and_delete(): void
    {
        $token = $this->issueToken($this->createUser());
        $this->apiGet('/api/patients/count', $token)->assertJsonPath('count', 0);
        $this->assertTrue(Cache::has(PatientSectionCache::countKey('nurse')));

        $created = $this->apiPost('/api/patients', $this->patientPayload(['id_no' => '501']), $token)->assertCreated();
        $this->apiGet('/api/patients/count', $token)->assertJsonPath('count', 1);

        $this->apiDelete('/api/patients/'.$created->json('data.id'), $token)->assertNoContent();
        $this->apiGet('/api/patients/count', $token)->assertJsonPath('count', 0);
    }

    public function test_update_duplicate_id_same_day_conflict(): void
    {
        $token = $this->issueToken($this->createUser());
        $a = $this->apiPost('/api/patients', $this->patientPayload(['id_no' => '111']), $token)->json('data.id');
        $b = $this->apiPost('/api/patients', $this->patientPayload(['id_no' => '222']), $token)->json('data.id');

        $this->apiPatch('/api/patients/'.$b, ['id_no' => '111'], $token)
            ->assertStatus(409);
        $this->assertSame('111', Patient::query()->find($a)->id_no);
    }

    public function test_recorded_at_sets_created_at(): void
    {
        $token = $this->issueToken($this->createUser());
        $this->apiPost('/api/patients', $this->patientPayload([
            'id_no' => '777',
            'recorded_at' => '2026-05-01T13:45:00',
        ]), $token)->assertCreated();

        $patient = Patient::query()->first();
        $this->assertSame('2026-05-01', CarbonImmutable::parse($patient->created_at)->toDateString());
    }
}
