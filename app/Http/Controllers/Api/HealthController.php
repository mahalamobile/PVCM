<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dbReachable = true;

        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            $dbReachable = false;
        }

        return response()->json([
            'status' => $dbReachable ? 'ok' : 'degraded',
            'checks' => [
                'database' => $dbReachable ? 'ok' : 'failed',
            ],
            'timestamp' => now()->toIso8601String(),
        ], $dbReachable ? 200 : 503);
    }
}
