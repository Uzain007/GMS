<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function readiness(): JsonResponse
    {
        try {
            DB::selectOne('SELECT 1');
            Redis::connection()->command('ping');

            return response()->json(['status' => 'ready']);
        } catch (Throwable) {
            // Readiness must not disclose connection names, credentials,
            // infrastructure topology or any tenant-specific information.
            return response()->json(['status' => 'unavailable'], 503);
        }
    }
}
