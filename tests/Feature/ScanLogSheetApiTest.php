<?php

namespace Tests\Feature;

use App\Services\VisionLogSheetReader;
use Illuminate\Http\UploadedFile;
use Mockery\MockInterface;
use Tests\TestCase;

class ScanLogSheetApiTest extends TestCase
{
    private function scanPost(string $token, array $data = [])
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->post('/api/scan-log-sheet', $data);
    }

    private function jpegFile(string $name = 'sheet.jpg'): UploadedFile
    {
        $jpeg = hex2bin(
            'ffd8ffe000104a46494600010101000100010000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffc40014100100000000000000000000000000000000ffda00080001000100003f00fbffd9'
        );

        return UploadedFile::fake()->createWithContent($name, $jpeg);
    }

    public function test_scan_requires_auth_and_image(): void
    {
        $this->postJson('/api/scan-log-sheet')->assertStatus(401);

        $token = $this->issueToken($this->createUser());
        $this->scanPost($token)->assertStatus(422)->assertJsonValidationErrors(['image']);
    }

    public function test_scan_rejects_non_image_files(): void
    {
        $token = $this->issueToken($this->createUser());
        $file = UploadedFile::fake()->createWithContent('notes.txt', 'not an image');

        $this->scanPost($token, ['image' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_scan_returns_normalized_sheet_from_reader(): void
    {
        $token = $this->issueToken($this->createUser());
        $this->mock(VisionLogSheetReader::class, function (MockInterface $mock) {
            $mock->shouldReceive('read')->once()->andReturn([
                'date' => '13/8',
                'entries' => [
                    ['no' => 1, 'id_no' => '128', 'sex' => 'M', 'age' => 22, 'service' => null],
                ],
            ]);
        });

        $this->scanPost($token, ['image' => $this->jpegFile()])
            ->assertOk()
            ->assertJsonPath('data.date', '13/8')
            ->assertJsonPath('data.entries.0.id_no', '128');
    }

    public function test_scan_maps_reader_runtime_errors_to_422(): void
    {
        $token = $this->issueToken($this->createUser());
        $this->mock(VisionLogSheetReader::class, function (MockInterface $mock) {
            $mock->shouldReceive('read')->once()->andThrow(new \RuntimeException('The scan service is busy. Wait a moment and try again.'));
        });

        $this->scanPost($token, ['image' => $this->jpegFile()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The scan service is busy. Wait a moment and try again.');
    }

    public function test_scan_is_rate_limited_per_user(): void
    {
        $token = $this->issueToken($this->createUser());
        $this->mock(VisionLogSheetReader::class, function (MockInterface $mock) {
            $mock->shouldReceive('read')->andReturn(['date' => '13/8', 'entries' => []]);
        });

        for ($i = 0; $i < 6; $i++) {
            $this->scanPost($token, ['image' => $this->jpegFile()])->assertOk();
        }

        $this->scanPost($token, ['image' => $this->jpegFile()])->assertStatus(429);
    }
}
