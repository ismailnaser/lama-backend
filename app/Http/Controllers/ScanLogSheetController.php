<?php

namespace App\Http\Controllers;

use App\Services\VisionLogSheetReader;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ScanLogSheetController extends Controller
{
    public function store(Request $request, VisionLogSheetReader $reader)
    {
        set_time_limit(180);
        ini_set('max_execution_time', '180');

        $data = $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
        ]);

        $file = $data['image'];

        try {
            $payload = $reader->read($file->getRealPath(), (string) $file->getMimeType());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not analyze this photo. Try a clearer photo of the sheet.',
            ], 500);
        }

        return response()->json(['data' => $payload]);
    }
}
