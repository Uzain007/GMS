<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GymOwnerAccountActionRequest;
use App\Http\Requests\StoreGymOwnerAccountRequest;
use App\Http\Requests\UpdateGymOwnerAccountRequest;
use App\Services\GymOwnerAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GymOwnerAccountController extends Controller
{
    public function show(GymOwnerAccountService $service): JsonResponse
    {
        return response()->json(['data' => $service->current()]);
    }

    public function store(StoreGymOwnerAccountRequest $request, GymOwnerAccountService $service): JsonResponse
    {
        return response()->json(['data' => $service->create($request->validated(), $request->user(), $request)], 201);
    }

    public function update(UpdateGymOwnerAccountRequest $request, GymOwnerAccountService $service): JsonResponse
    {
        return response()->json(['data' => $service->update($request->validated(), $request->user(), $request)]);
    }

    public function resetPassword(GymOwnerAccountActionRequest $request, GymOwnerAccountService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->sendResetLink((string) $request->validated('reason'), $request->user(), $request),
            'message' => 'A secure password setup link has been queued for delivery.',
        ]);
    }

    public function temporaryPassword(GymOwnerAccountActionRequest $request, GymOwnerAccountService $service): JsonResponse
    {
        $result = $service->generateTemporaryPassword((string) $request->validated('reason'), $request->user(), $request);

        return response()->json([
            'data' => $result['account'],
            // This plaintext value is generated for this response only and is
            // never persisted, logged or recoverable after the response closes.
            'meta' => ['temporary_password' => $result['temporary_password']],
        ])->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
