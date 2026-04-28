<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\PatientAuditLog;
use App\Support\AuthUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Barryvdh\DomPDF\Facade\Pdf;

class PatientController extends Controller
{
    private function requestSection(Request $request): string
    {
        $u = AuthUser::fromRequest($request);
        $role = (string) ($u?->role ?? 'user');
        return in_array($role, ['doctor', 'doctor_admin'], true) ? 'doctor' : 'nurse';
    }

    private function isInSection(Request $request, Patient $patient): bool
    {
        return (string) ($patient->section ?? 'nurse') === $this->requestSection($request);
    }

    public function audits(Request $request, Patient $patient)
    {
        if (!$this->isInSection($request, $patient)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $logs = PatientAuditLog::query()
            ->where('patient_id', $patient->id)
            ->latest()
            ->get(['id', 'action', 'username', 'user_id', 'changes', 'created_at']);

        return response()->json(['data' => $logs]);
    }

    public function count()
    {
        $section = $this->requestSection(request());
        return response()->json([
            'count' => Patient::query()->where('section', $section)->count(),
        ]);
    }

    public function index(Request $request)
    {
        $filters = $this->validatedFilters($request);
        $section = $this->requestSection($request);

        $createdBySub = PatientAuditLog::query()
            ->select('patient_id')
            ->selectRaw('MAX(username) as created_by')
            ->where('action', 'created')
            ->groupBy('patient_id');

        $patients = Patient::query()
            ->leftJoinSub($createdBySub, 'created_logs', function ($join) {
                $join->on('created_logs.patient_id', '=', 'patients.id');
            })
            ->where('patients.section', $section)
            ->filter($filters)
            ->select('patients.*')
            ->addSelect(DB::raw("COALESCE(created_logs.created_by, '') as created_by"))
            ->latest('patients.created_at')
            ->get();

        return response()->json([
            'data' => $patients,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id_no' => ['required', 'string', 'max:50'],
            'sex' => ['required', 'in:M,F'],
            'age' => ['required', 'integer', 'min:0', 'max:150'],
            'room' => ['required', 'in:room1,room2'],
            'ww' => ['sometimes', 'boolean'],
            'lab' => ['sometimes', 'boolean'],
            'burn' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['id_no'] = trim($data['id_no']);
        $section = $this->requestSection($request);
        $data['section'] = $section;

        $duplicateToday = Patient::query()
            ->where('section', $section)
            ->where('id_no', $data['id_no'])
            ->whereDate('created_at', CarbonImmutable::now())
            ->exists();

        if ($duplicateToday) {
            return response()->json([
                'message' => 'This ID number is already registered today.',
            ], 409);
        }

        $patient = Patient::create($data);

        $u = AuthUser::fromRequest($request);
        PatientAuditLog::create([
            'patient_id' => $patient->id,
            'user_id' => $u?->id,
            'username' => $u?->username,
            'action' => 'created',
            'changes' => [
                'before' => null,
                'after' => $patient->only(['id_no', 'sex', 'age', 'room', 'ww', 'lab', 'burn', 'notes']),
            ],
        ]);

        return response()->json([
            'data' => $patient,
        ], 201);
    }

    public function update(Request $request, Patient $patient)
    {
        if (!$this->isInSection($request, $patient)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $before = $patient->only(['id_no', 'sex', 'age', 'room', 'ww', 'lab', 'burn', 'notes']);

        $data = $request->validate([
            'id_no' => ['sometimes', 'string', 'max:50'],
            'sex' => ['sometimes', 'in:M,F'],
            'age' => ['sometimes', 'integer', 'min:0', 'max:150'],
            'room' => ['sometimes', 'in:room1,room2'],
            'ww' => ['sometimes', 'boolean'],
            'lab' => ['sometimes', 'boolean'],
            'burn' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (array_key_exists('id_no', $data)) {
            $data['id_no'] = trim($data['id_no']);
            $day = CarbonImmutable::parse($patient->created_at)->toDateString();
            $conflict = Patient::query()
                ->where('section', (string) ($patient->section ?? 'nurse'))
                ->where('id_no', $data['id_no'])
                ->whereDate('created_at', $day)
                ->where('id', '!=', $patient->id)
                ->exists();
            if ($conflict) {
                return response()->json([
                    'message' => 'This ID number is already used for another record on the same day.',
                ], 409);
            }
        }

        $patient->update($data);

        $after = $patient->fresh()->only(['id_no', 'sex', 'age', 'room', 'ww', 'lab', 'burn', 'notes']);
        $u = AuthUser::fromRequest($request);
        PatientAuditLog::create([
            'patient_id' => $patient->id,
            'user_id' => $u?->id,
            'username' => $u?->username,
            'action' => 'updated',
            'changes' => [
                'before' => $before,
                'after' => $after,
            ],
        ]);

        return response()->json([
            'data' => $patient->fresh(),
        ]);
    }

    public function destroy(Patient $patient)
    {
        if (!$this->isInSection(request(), $patient)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $before = $patient->only(['id_no', 'sex', 'age', 'room', 'ww', 'lab', 'burn', 'notes']);
        $u = AuthUser::fromRequest(request());

        PatientAuditLog::create([
            'patient_id' => $patient->id,
            'user_id' => $u?->id,
            'username' => $u?->username,
            'action' => 'deleted',
            'changes' => [
                'before' => $before,
                'after' => null,
            ],
        ]);

        $patient->delete();

        return response()->noContent();
    }

    public function pdf(Request $request)
    {
        $filters = $this->validatedFilters($request);
        $section = $this->requestSection($request);

        $patients = Patient::query()
            ->where('section', $section)
            ->filter($filters)
            ->oldest()
            ->get();

        $titleDate = $this->filtersToTitleDate($filters);

        $pdf = Pdf::loadView('pdf.patients', [
            'patients' => $patients,
            'titleDate' => $titleDate,
        ])->setPaper('a4', 'portrait');

        $filename = 'surgical-dressing-log-'.$titleDate.'.pdf';

        return $pdf->download($filename);
    }

    public function excel(Request $request)
    {
        $filters = $this->validatedFilters($request);
        $section = $this->requestSection($request);

        $patients = Patient::query()
            ->where('section', $section)
            ->filter($filters)
            ->oldest()
            ->get(['id_no', 'sex', 'age', 'room', 'ww', 'lab', 'burn', 'notes', 'created_at']);
        // Safety: drop any legacy rows missing an ID so Excel doesn't show a blank first row.
        $patients = $patients
            ->filter(fn ($p) => trim((string) ($p->id_no ?? '')) !== '')
            ->values();

        $titleDate = $this->filtersToTitleDate($filters);
        $filename = 'surgical-dressing-log-'.$titleDate.'.csv';

        $escape = function (?string $value): string {
            $v = $value ?? '';
            $v = str_replace('"', '""', $v);
            return '"'.$v.'"';
        };

        // Use semicolon delimiter for better Excel compatibility on many Windows/Arabic locales.
        $delim = ';';

        $lines = [];
        $lines[] = implode($delim, [
            $escape('No'),
            $escape('ID No'),
            $escape('Sex'),
            $escape('Age'),
            $escape('Room'),
            $escape('WW'),
            $escape('Lab'),
            $escape('Burn'),
            $escape('Notes'),
            $escape('Date'),
            $escape('Time'),
        ]);

        foreach ($patients as $idx => $p) {
            $lines[] = implode($delim, [
                $escape((string) ($idx + 1)),
                $escape((string) $p->id_no),
                $escape((string) $p->sex),
                $escape((string) $p->age),
                $escape((string) ($p->room ?? '')),
                $escape($p->ww ? 'Yes' : 'No'),
                $escape($p->lab ? 'Yes' : 'No'),
                $escape($p->burn ? 'Yes' : 'No'),
                $escape($p->notes),
                $escape(optional($p->created_at)?->format('d-M-Y')),
                $escape(optional($p->created_at)?->format('H:i')),
            ]);
        }

        // UTF-8 BOM helps Excel open Arabic/UTF8 correctly.
        $csv = "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function validatedFilters(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'id_no' => ['nullable', 'string', 'max:50'],
            'id_no_exact' => ['nullable', 'string', 'max:50'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $data = $validator->validate();

        $from = $data['from_date'] ?? null;
        $to = $data['to_date'] ?? null;
        if (($from && !$to) || (!$from && $to)) {
            abort(422, 'from_date and to_date must be provided together.');
        }
        if ($from && $to && CarbonImmutable::parse($from)->gt(CarbonImmutable::parse($to))) {
            abort(422, 'from_date must be before or equal to to_date.');
        }
        if (!empty($data['date']) && ($from || $to)) {
            abort(422, 'Use either date or from_date/to_date, not both.');
        }

        return $data;
    }

    private function filtersToTitleDate(array $filters): string
    {
        if (!empty($filters['from_date']) && !empty($filters['to_date'])) {
            return $filters['from_date'].'_to_'.$filters['to_date'];
        }
        if (!empty($filters['date'])) {
            return $filters['date'];
        }
        return CarbonImmutable::today()->toDateString();
    }
}
