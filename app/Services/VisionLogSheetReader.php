<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class VisionLogSheetReader
{
    public function read(string $absolutePath, string $mime): array
    {
        $bytes = file_get_contents($absolutePath);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Could not read this photo. Try another image.');
        }

        $base64 = base64_encode($bytes);
        $mime = $this->normalizeMime($mime);
        $provider = $this->resolveProvider();

        $raw = $provider === 'openai'
            ? $this->callOpenAi($base64, $mime)
            : $this->callGemini($base64, $mime);

        return $this->normalizePayload($this->decodeJson($raw));
    }

    private function resolveProvider(): string
    {
        $requested = strtolower((string) config('services.vision.provider', 'auto'));
        $geminiKey = trim((string) config('services.vision.gemini.key', ''));
        $openaiKey = trim((string) config('services.vision.openai.key', ''));

        if ($requested === 'gemini') {
            if ($geminiKey === '') {
                throw new RuntimeException('The scan service is not available right now. Try again later.');
            }
            return 'gemini';
        }
        if ($requested === 'openai') {
            if ($openaiKey === '') {
                throw new RuntimeException('The scan service is not available right now. Try again later.');
            }
            return 'openai';
        }
        if ($geminiKey !== '') {
            return 'gemini';
        }
        if ($openaiKey !== '') {
            return 'openai';
        }

        throw new RuntimeException('The scan service is not available right now. Try again later.');
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
You are reading a photographed paper form: "Surgical Dressing Log Sheet/OPD".

Layout:
- Two tables side by side on one page.
- Left table rows 1-30, right table rows 31-60.
- Columns: NO, ID NO, Sex, Age, WW.

Read the handwriting carefully. Never invent a patient. If a cell is blank or unreadable, leave it empty.

Rules:
- Always return entries for numbers 1 through 60.
- ID NO is ALWAYS exactly 3 digits (example 128). If you cannot read all 3 digits, use "".
- Do not use the printed row number (1-60) as the ID.
- Sex is only "M" or "F". If unreadable use null.
- Age is years as an integer. "10mon" / "10 m" / months → 0. If unreadable use null.
- WW / service column:
  - Dressing / Dsg / Psg / dress → service must be null (do not tick anything)
  - lab → "lab"
  - W or WW → "ww"
  - burn / brn → "burn"
- Date is in the header (example 13/8).

Return JSON only:
{
  "date": "13/8" or "13/8/2026" or null,
  "entries": [
    {"no": 1, "id_no": "128", "sex": "M", "age": 22, "service": null}
  ]
}
PROMPT;
    }

    private function callGemini(string $base64, string $mime): string
    {
        $key = trim((string) config('services.vision.gemini.key'));
        $preferred = trim((string) config('services.vision.gemini.model', 'gemini-3.6-flash'));
        $models = array_values(array_unique(array_filter([
            $preferred,
            'gemini-3.6-flash',
            'gemini-flash-latest',
        ])));

        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $this->prompt()],
                    ['inline_data' => [
                        'mime_type' => $mime,
                        'data' => $base64,
                    ]],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
            ],
        ];

        $res = null;
        foreach ($models as $model) {
            // The key goes in the x-goog-api-key header: newer Gemini keys are
            // rejected with 401 when passed as a ?key= query parameter.
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent';
            $res = Http::timeout(150)
                ->connectTimeout(15)
                ->withHeaders(['x-goog-api-key' => $key])
                ->post($url, $payload);
            if ($res->successful()) {
                break;
            }
            $unavailable = $res->status() === 404
                && str_contains((string) $res->body(), 'no longer available');
            if (!$unavailable) {
                throw new RuntimeException($this->httpError($res->status(), $res->body()));
            }
        }

        if ($res === null || !$res->successful()) {
            throw new RuntimeException($this->httpError($res?->status() ?? 0, $res?->body() ?? ''));
        }

        $text = data_get($res->json(), 'candidates.0.content.parts.0.text');
        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException('Could not read the sheet from this photo. Try a clearer photo.');
        }

        return $text;
    }

    private function callOpenAi(string $base64, string $mime): string
    {
        $key = trim((string) config('services.vision.openai.key'));
        $model = trim((string) config('services.vision.openai.model', 'gpt-4o'));
        $base = rtrim((string) config('services.vision.openai.base_url', 'https://api.openai.com/v1'), '/');

        $res = Http::timeout(90)
            ->withToken($key)
            ->post($base.'/chat/completions', [
                'model' => $model,
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $this->prompt()],
                        ['type' => 'image_url', 'image_url' => [
                            'url' => 'data:'.$mime.';base64,'.$base64,
                        ]],
                    ],
                ]],
            ]);

        if (!$res->successful()) {
            throw new RuntimeException($this->httpError($res->status(), $res->body()));
        }

        $text = data_get($res->json(), 'choices.0.message.content');
        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException('Could not read the sheet from this photo. Try a clearer photo.');
        }

        return $text;
    }

    private function decodeJson(string $raw): array
    {
        $t = trim($raw);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $t, $m)) {
            $t = $m[1];
        }
        $start = strpos($t, '{');
        $end = strrpos($t, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $t = substr($t, $start, $end - $start + 1);
        }
        $json = json_decode($t, true);
        if (!is_array($json)) {
            throw new RuntimeException('Could not read the sheet from this photo. Try a clearer photo.');
        }

        return $json;
    }

    private function normalizePayload(array $data): array
    {
        $byNo = [];
        foreach (($data['entries'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $no = (int) ($row['no'] ?? 0);
            if ($no < 1 || $no > 60) {
                continue;
            }
            $byNo[$no] = $this->normalizeEntry($row, $no);
        }

        $entries = [];
        for ($i = 1; $i <= 60; $i++) {
            $entries[] = $byNo[$i] ?? [
                'no' => $i,
                'id_no' => '',
                'sex' => null,
                'age' => null,
                'service' => null,
            ];
        }

        $date = $data['date'] ?? $data['dateRaw'] ?? null;

        return [
            'date' => is_string($date) ? trim($date) : null,
            'entries' => $entries,
        ];
    }

    private function normalizeEntry(array $row, int $no): array
    {
        $id = preg_replace('/\D+/', '', (string) ($row['id_no'] ?? $row['id'] ?? '')) ?? '';
        if (strlen($id) > 3) {
            if (str_starts_with($id, (string) $no) && strlen(substr($id, strlen((string) $no))) === 3) {
                $id = substr($id, strlen((string) $no));
            } else {
                $id = substr($id, -3);
            }
        }
        if (!preg_match('/^\d{3}$/', $id)) {
            $id = '';
        }

        $sexRaw = strtoupper(trim((string) ($row['sex'] ?? '')));
        $sex = $sexRaw === 'F' || $sexRaw === 'FEMALE' ? 'F' : ($sexRaw === 'M' || $sexRaw === 'MALE' ? 'M' : null);

        $age = $row['age'] ?? null;
        if (is_string($age) && preg_match('/mon|month|\bm\b/i', $age)) {
            $age = 0;
        }
        if (!is_int($age) && is_numeric($age)) {
            $age = (int) $age;
        }
        if (!is_int($age) || $age < 0 || $age > 150) {
            $age = null;
        }

        $service = strtolower(trim((string) ($row['service'] ?? '')));
        if (in_array($service, ['dress', 'dressing', 'dsg', 'psg', 'none', 'null', ''], true)) {
            $service = null;
        } elseif (in_array($service, ['lab', 'laboratory'], true)) {
            $service = 'lab';
        } elseif (in_array($service, ['ww', 'w'], true)) {
            $service = 'ww';
        } elseif (in_array($service, ['burn', 'brn'], true)) {
            $service = 'burn';
        } else {
            $service = null;
        }

        return [
            'no' => $no,
            'id_no' => $id,
            'sex' => $sex,
            'age' => $age,
            'service' => $service,
        ];
    }

    private function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        return match ($mime) {
            'image/jpg' => 'image/jpeg',
            'image/png', 'image/jpeg', 'image/webp' => $mime,
            default => 'image/jpeg',
        };
    }

    private function httpError(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $apiMessage = is_array($decoded) ? strtolower((string) data_get($decoded, 'error.message', '')) : '';
        $apiStatus = is_array($decoded) ? (string) data_get($decoded, 'error.status', '') : '';

        if (
            $status === 401
            || $status === 403
            || $apiStatus === 'UNAUTHENTICATED'
            || str_contains($apiMessage, 'api key')
        ) {
            return 'The scan service is not available right now. Try again later.';
        }
        if (
            $status === 429
            || $apiStatus === 'RESOURCE_EXHAUSTED'
            || str_contains($apiMessage, 'quota')
            || str_contains($apiMessage, 'rate')
        ) {
            return 'The scan service is busy. Wait a moment and try again.';
        }
        if ($status >= 500) {
            return 'The scan service failed. Try again in a moment.';
        }

        return 'Could not analyze this photo. Try a clearer photo of the sheet.';
    }
}
