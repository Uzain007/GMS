<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Currency;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReportOverviewRequest;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function overview(ReportOverviewRequest $request, ReportService $reports): JsonResponse
    {
        $filters = $request->validated();

        // Tenant middleware has already bound route + header identity; the
        // service repeats gym_id on every aggregate and inside its cache key.
        return response()->json([
            'data' => $reports->overview(
                $filters['from'],
                $filters['to'],
                Currency::from($filters['currency']),
            ),
        ]);
    }
}
