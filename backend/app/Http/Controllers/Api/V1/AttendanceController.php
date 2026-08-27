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
use App\Models\MemberAccessCredential;
use App\Enums\AccessCredentialStatus;
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

    public function credential(
        Request $request,
        string $member,
        AttendanceService $service,
    ): JsonResponse {
        $memberModel = Member::query()->findOrFail($member);
        $credential = MemberAccessCredential::query()
            ->where('member_id', $memberModel->getKey())
            ->where('status', AccessCredentialStatus::Active->value)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->first();

        $plaintext = $credential ? $service->plaintextFor($credential) : null;
        if (! $credential || ! $plaintext) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->credentialData(
            $request, $memberModel, $credential, $plaintext,
        )]);
    }

    public function issueCredential(
        IssueAccessCredentialRequest $request,
        string $member,
        AttendanceService $service,
    ): JsonResponse {
        $memberModel = Member::query()->findOrFail($member);
        $result = $service->issueCredential(
            $memberModel,
            $request->validated(),
            $request->user(),
            $request,
        );
        return response()->json(['data' => $this->credentialData(
            $request, $memberModel, $result['credential'], $result['plaintext'],
        )], $result['created'] ? 201 : 200);
    }

    public function rotateCredential(
        IssueAccessCredentialRequest $request,
        string $member,
        AttendanceService $service,
    ): JsonResponse {
        $memberModel = Member::query()->findOrFail($member);
        $result = $service->rotateCredential(
            $memberModel,
            $request->validated(),
            $request->user(),
            $request,
        );

        return response()->json(['data' => $this->credentialData(
            $request, $memberModel, $result['credential'], $result['plaintext'],
        )], 201);
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

    private function credentialData(
        Request $request,
        Member $member,
        MemberAccessCredential $credential,
        string $plaintext,
    ): array {
        $data = (new MemberAccessCredentialResource($credential))->resolve($request);
        // The stable visible code and opaque QR token come from the same
        // tenant-scoped member/credential pair and never expose internal IDs.
        $data['member_code'] = $member->member_code;
        $data['credential'] = $plaintext;

        return $data;
    }
}
