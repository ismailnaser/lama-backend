<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\PatientAuditLog;
use App\Support\AuthUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Barryvdh\DomPDF\Facade\Pdf;

class PatientController extends Controller
{
    public function audits(Request $request, Patient $patient)
    {
        $logs = PatientAuditLog::query()
            ->where('patient_id', $patient->id)
            ->latest()
            ->get(['id', 'action', 'username', 'user_id', 'changes', 'created_at']);

        return response()->json(['data' => $logs]);
    }

    public function count()
    {
        return response()->json([
            'count' => Patient::query()->count(),
        ]);
    }

    public function index()
    {
        $filters = $this->validatedFilters(request());

        $patients = Patient::query()
            ->filter($filters)
            ->latest()
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
            'ww' => ['sometimes', 'boolean'],
            'lab' => ['sometimes', 'boolean'],
            'burn' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['id_no'] = trim($data['id_no']);

        $duplicateToday = Patient::query()
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
                'after' => $patient->only(['id_no', 'sex', 'age', 'ww', 'lab', 'burn', 'notes']),
            ],
        ]);

        return response()->json([
            'data' => $patient,
        ], 201);
    }

    public function update(Request $request, Patient $patient)
    {
        $before = $patient->only(['id_no', 'sex', 'age', 'ww', 'lab', 'burn', 'notes']);

        $data = $request->validate([
            'id_no' => ['sometimes', 'string', 'max:50'],
            'sex' => ['sometimes', 'in:M,F'],
            'age' => ['sometimes', 'integer', 'min:0', 'max:150'],
            'ww' => ['sometimes', 'boolean'],
            'lab' => ['sometimes', 'boolean'],
            'burn' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (array_key_exists('id_no', $data)) {
            $data['id_no'] = trim($data['id_no']);
            $day = CarbonImmutable::parse($patient->created_at)->toDateString();
            $conflict = Patient::query()
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

        $after = $patient->fresh()->only(['id_no', 'sex', 'age', 'ww', 'lab', 'burn', 'notes']);
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
        $before = $patient->only(['id_no', 'sex', 'age', 'ww', 'lab', 'burn', 'notes']);
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

        $patients = Patient::query()
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

        $patients = Patient::query()
            ->filter($filters)
            ->oldest()
            ->get(['id_no', 'sex', 'age', 'ww', 'lab', 'burn', 'notes', 'created_at']);

        $titleDate = $this->filtersToTitleDate($filters);
        $filename = 'surgical-dressing-log-'.$titleDate.'.csv';

        $escape = function (?string $value): string {
            $v = $value ?? '';
            $v = str_replace('"', '""', $v);
            return '"'.$v.'"';
        };

        $lines = [];
        $lines[] = implode(',', [
            $escape('ID No'),
            $escape('Sex'),
            $escape('Age'),
            $escape('WW'),
            $escape('Lab'),
            $escape('Burn'),
            $escape('Notes'),
            $escape('Date'),
            $escape('Time'),
        ]);

        foreach ($patients as $p) {
            $lines[] = implode(',', [
                $escape((string) $p->id_no),
                $escape((string) $p->sex),
                $escape((string) $p->age),
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
