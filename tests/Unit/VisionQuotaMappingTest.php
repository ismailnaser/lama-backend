<?php

namespace Tests\Unit;

use App\Services\VisionLogSheetReader;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class VisionQuotaMappingTest extends TestCase
{
    private function callGemini(): string
    {
        $method = new ReflectionMethod(VisionLogSheetReader::class, 'callGemini');
        $method->setAccessible(true);

        return $method->invoke(new VisionLogSheetReader(), base64_encode('photo-bytes'), 'image/jpeg');
    }

    private function httpError(int $status, string $body): string
    {
        $method = new ReflectionMethod(VisionLogSheetReader::class, 'httpError');
        $method->setAccessible(true);

        return $method->invoke(new VisionLogSheetReader(), $status, $body);
    }

    private function dailyQuotaBody(): string
    {
        return json_encode([
            'error' => [
                'code' => 429,
                'status' => 'RESOURCE_EXHAUSTED',
                'message' => 'You exceeded your current quota, please check your plan and billing details.',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
                    'violations' => [[
                        'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_free_tier_requests',
                        'quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier',
                    ]],
                ]],
            ],
        ]);
    }

    public function test_daily_quota_tells_the_user_to_wait_until_tomorrow(): void
    {
        $message = $this->httpError(429, $this->dailyQuotaBody());

        $this->assertStringContainsString('daily free limit', $message);
        $this->assertStringContainsString('resets tomorrow', $message);
        $this->assertStringNotContainsString('busy', $message);
    }

    public function test_per_minute_quota_stays_a_busy_message(): void
    {
        $body = json_encode([
            'error' => [
                'code' => 429,
                'status' => 'RESOURCE_EXHAUSTED',
                'message' => 'Quota exceeded',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
                    'violations' => [[
                        'quotaId' => 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier',
                    ]],
                ]],
            ],
        ]);

        $message = $this->httpError(429, $body);

        $this->assertStringContainsString('busy', $message);
        $this->assertStringNotContainsString('tomorrow', $message);
    }

    public function test_quota_detection_ignores_non_429_responses(): void
    {
        $method = new ReflectionMethod(VisionLogSheetReader::class, 'isQuotaExhausted');
        $method->setAccessible(true);
        $reader = new VisionLogSheetReader();

        $this->assertTrue($method->invoke($reader, 429, $this->dailyQuotaBody()));
        $this->assertFalse($method->invoke($reader, 500, $this->dailyQuotaBody()));
        $this->assertFalse($method->invoke($reader, 429, '{"error":{"status":"INTERNAL"}}'));
    }

    public function test_a_spent_model_falls_through_to_one_that_still_has_quota(): void
    {
        config([
            'services.vision.gemini.key' => 'test-key',
            'services.vision.gemini.model' => 'gemini-3.6-flash',
        ]);

        Http::fake([
            '*gemini-3.6-flash:*' => Http::response($this->dailyQuotaBody(), 429),
            '*gemini-3.1-flash-lite:*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"rows":[]}']]],
                ]],
            ], 200),
        ]);

        $this->assertSame('{"rows":[]}', $this->callGemini());

        Http::assertSentCount(2);
    }

    public function test_every_model_being_spent_reports_the_daily_limit(): void
    {
        config([
            'services.vision.gemini.key' => 'test-key',
            'services.vision.gemini.model' => 'gemini-3.6-flash',
        ]);

        Http::fake(['*' => Http::response($this->dailyQuotaBody(), 429)]);

        try {
            $this->callGemini();
            $this->fail('Expected the reader to fail once no model has quota left.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('daily free limit', $e->getMessage());
        }
    }

    public function test_a_real_error_stops_the_chain_instead_of_trying_every_model(): void
    {
        config([
            'services.vision.gemini.key' => 'test-key',
            'services.vision.gemini.model' => 'gemini-3.6-flash',
        ]);

        Http::fake(['*' => Http::response(['error' => ['status' => 'INVALID_ARGUMENT']], 400)]);

        try {
            $this->callGemini();
            $this->fail('Expected the reader to surface the request error.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('clearer photo', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_auth_and_server_failures_keep_their_own_messages(): void
    {
        $this->assertStringContainsString(
            'not available',
            $this->httpError(401, '{"error":{"status":"UNAUTHENTICATED"}}')
        );
        $this->assertStringContainsString('failed', $this->httpError(503, '{}'));
        $this->assertStringContainsString('clearer photo', $this->httpError(400, '{}'));
    }
}
