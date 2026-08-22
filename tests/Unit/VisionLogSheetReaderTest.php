<?php

namespace Tests\Unit;

use App\Services\VisionLogSheetReader;
use ReflectionMethod;
use Tests\TestCase;

class VisionLogSheetReaderTest extends TestCase
{
    public function test_normalize_payload_pads_to_sixty_rows_and_cleans_ids(): void
    {
        $reader = new VisionLogSheetReader();
        $method = new ReflectionMethod(VisionLogSheetReader::class, 'normalizePayload');
        $method->setAccessible(true);

        $result = $method->invoke($reader, [
            'date' => ' 13/8 ',
            'entries' => [
                ['no' => 1, 'id_no' => 'ID-128', 'sex' => 'female', 'age' => '10mon', 'service' => 'WW'],
                ['no' => 2, 'id_no' => '12', 'sex' => 'X', 'age' => 200, 'service' => 'dressing'],
                ['no' => 99, 'id_no' => '333', 'sex' => 'M', 'age' => 20, 'service' => 'lab'],
            ],
        ]);

        $this->assertSame('13/8', $result['date']);
        $this->assertCount(60, $result['entries']);
        $this->assertSame('128', $result['entries'][0]['id_no']);
        $this->assertSame('F', $result['entries'][0]['sex']);
        $this->assertSame(0, $result['entries'][0]['age']);
        $this->assertSame('ww', $result['entries'][0]['service']);
        $this->assertSame('', $result['entries'][1]['id_no']);
        $this->assertNull($result['entries'][1]['sex']);
        $this->assertNull($result['entries'][1]['age']);
        $this->assertNull($result['entries'][1]['service']);
        $this->assertSame('', $result['entries'][2]['id_no']);
    }

    public function test_http_error_maps_provider_failures(): void
    {
        $reader = new VisionLogSheetReader();
        $method = new ReflectionMethod(VisionLogSheetReader::class, 'httpError');
        $method->setAccessible(true);

        $this->assertStringContainsString('not available', $method->invoke($reader, 401, '{"error":{"status":"UNAUTHENTICATED"}}'));
        $this->assertStringContainsString('busy', $method->invoke($reader, 429, '{"error":{"message":"quota exceeded"}}'));
        $this->assertStringContainsString('failed', $method->invoke($reader, 503, '{}'));
    }
}
