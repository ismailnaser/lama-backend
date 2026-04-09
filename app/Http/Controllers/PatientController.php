<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Barryvdh\DomPDF\Facade\Pdf;

class PatientController extends Controller
{
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
            'ww' => ['nullable', 'string', 'max:255'],
        ]);

        if (Patient::query()->where('id_no', $data['id_no'])->exists()) {
            return response()->json([
                'message' => 'Patient already exists.',
            ], 409);
        }

        $patient = Patient::create($data);

        return response()->json([
            'data' => $patient,
        ], 201);
    }

    public function update(Request $request, Patient $patient)
    {
        $data = $request->validate([
            'id_no' => ['sometimes', 'string', 'max:50'],
            'sex' => ['sometimes', 'in:M,F'],
            'age' => ['sometimes', 'integer', 'min:0', 'max:150'],
            'ww' => ['nullable', 'string', 'max:255'],
        ]);

        $patient->update($data);

        return response()->json([
            'data' => $patient->fresh(),
        ]);
    }

    public function destroy(Patient $patient)
    {
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
            ->get(['id_no', 'sex', 'age', 'ww', 'created_at']);

        $titleDate = $this->filtersToTitleDate($filters);
        $filename = 'surgical-dressing-log-'.$titleDate.'.csv';

        $escape = function (?string $value): string {
            $v = $value ?? '';
            $v = str_replace('"', '""', $v);
            return '"'.$v.'"';
        };

        $lines = [];
        // Force Excel to use comma as delimiter, regardless of OS locale.
        $lines[] = 'sep=,';
        $lines[] = implode(',', [
            $escape('ID No'),
            $escape('Sex'),
            $escape('Age'),
            $escape('Notes'),
            $escape('Date'),
            $escape('Time'),
        ]);

        foreach ($patients as $p) {
            $lines[] = implode(',', [
                $escape((string) $p->id_no),
                $escape((string) $p->sex),
                $escape((string) $p->age),
                $escape($p->ww),
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
