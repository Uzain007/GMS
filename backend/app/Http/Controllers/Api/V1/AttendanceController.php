<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IssueAccessCredentialRequest;
use App\Http\Requests\StoreAttendanceCheckInRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\MemberAccessCredentialResource;
use App\Models\AttendanceRecord;
use App\Models\Member;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $from = CarbonImmutable::parse((string) $request->input('from', today()->toDateString()))->startOfDay();
        $to = CarbonImmutable::parse((string) $request->input('to', today()->toDateString()))->endOfDay();
        if ($to->isBefore($from) || $from->diffInDays($to) > 31) {
            throw ValidationException::withMessages(['to' => ['Attendance ranges must be ordered and no longer than 31 days.']]);
        }

        $query = AttendanceRecord::query()->with(['member', 'branch'])
            ->whereBetween('checked_in_at', [$from, $to])
            ->orderByDesc('checked_in_at')->orderByDesc('id');
        foreach (['branch_id', 'member_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        // Cursor pagination avoids increasingly expensive offsets in large
        // tenant attendance histories.
        return AttendanceRecordResource::collection(
            $query->cursorPaginate(min(max((int) $request->input('per_page', 50), 1), 100))
        );
    }

    public function issueCredential(
        IssueAccessCredentialRequest $request,
        string $member,
        AttendanceService $service,
    ): JsonResponse {
        $result = $service->issueCredential(
            Member::query()->findOrFail($member),
            $request->validated(),
            $request->user(),
            $request,
        );
        $data = (new MemberAccessCredentialResource($result['credential']))->resolve($request);
        // Plaintext appears only in this single response and never on the model.
        $data['credential'] = $result['plaintext'];
        return response()->json(['data' => $data], 201);
    }

    public function checkIn(StoreAttendanceCheckInRequest $request, AttendanceService $service): AttendanceRecordResource
    {
        return new AttendanceRecordResource(
            $service->checkIn($request->validated(), $request->user(), $request)
        );
    }

    public function checkOut(Request $request, string $attendance, AttendanceService $service): AttendanceRecordResource
    {
        return new AttendanceRecordResource($service->checkOut($attendance, $request->user(), $request));
    }
}
