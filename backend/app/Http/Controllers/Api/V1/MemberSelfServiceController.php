<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccessCredentialStatus;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IssueAccessCredentialRequest;
use App\Http\Requests\UpdateMemberSelfRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\MemberSelfCredentialResource;
use App\Http\Resources\MemberSelfResource;
use App\Http\Resources\MembershipResource;
use App\Http\Resources\PaymentResource;
use App\Models\AttendanceRecord;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\MemberAccessCredential;
use App\Models\Membership;
use App\Models\Payment;
use App\Services\AttendanceService;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MemberSelfServiceController extends Controller
{
    public function show(Request $request): MemberSelfResource
    {
        return new MemberSelfResource($this->memberFor($request));
    }

    public function update(UpdateMemberSelfRequest $request, AuditService $audit): MemberSelfResource
    {
        $member = $this->memberFor($request);
        $before = $member->toArray();
        $data = $request->validated();
        if (array_key_exists('email', $data) && $data['email']) {
            $data['email'] = mb_strtolower($data['email']);
        }

        $fresh = DB::transaction(function () use ($member, $data, $before, $request, $audit): Member {
            $member->update($data);
            $fresh = $member->fresh();
            $audit->record(
                'member.profile.self_updated', $fresh, $request->user(),
                $before, $fresh->toArray(), 'Member self-service profile update', $request,
            );
            return $fresh;
        });

        return new MemberSelfResource($fresh);
    }

    public function membership(Request $request): JsonResponse
    {
        $member = $this->memberFor($request);
        $membership = Membership::query()->with(['plan', 'branch'])
            ->where('member_id', $member->getKey())
            ->whereIn('status', [
                MembershipStatus::Active->value,
                MembershipStatus::Paused->value,
                MembershipStatus::Pending->value,
            ])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")
            ->latest('starts_at')->first();

        return response()->json([
            'data' => $membership ? (new MembershipResource($membership))->resolve($request) : null,
        ]);
    }

    public function invoices(Request $request): AnonymousResourceCollection
    {
        $member = $this->memberFor($request);
        return InvoiceResource::collection(
            Invoice::query()->with('items')->where('member_id', $member->getKey())
                ->orderByDesc('issued_at')->paginate($this->pageSize($request, 25))
        );
    }

    public function payments(Request $request): AnonymousResourceCollection
    {
        $member = $this->memberFor($request);
        return PaymentResource::collection(
            Payment::query()->with('refunds')->where('member_id', $member->getKey())
                ->orderByDesc('paid_at')->paginate($this->pageSize($request, 25))
        );
    }

    public function attendance(Request $request): AnonymousResourceCollection
    {
        $member = $this->memberFor($request);
        $from = CarbonImmutable::parse((string) $request->input('from', today()->subDays(29)->toDateString()))->startOfDay();
        $to = CarbonImmutable::parse((string) $request->input('to', today()->toDateString()))->endOfDay();
        if ($to->isBefore($from) || $from->diffInDays($to) > 90) {
            throw ValidationException::withMessages([
                'to' => ['Member attendance ranges must be ordered and no longer than 90 days.'],
            ]);
        }

        // The linked member predicate is always present in addition to the
        // global gym scope and PostgreSQL RLS policy.
        return AttendanceRecordResource::collection(
            AttendanceRecord::query()->with('branch')->where('member_id', $member->getKey())
                ->whereBetween('checked_in_at', [$from, $to])
                ->orderByDesc('checked_in_at')->orderByDesc('id')
                ->cursorPaginate($this->pageSize($request, 50))
        );
    }

    public function credential(Request $request): JsonResponse
    {
        $member = $this->memberFor($request);
        $credential = MemberAccessCredential::query()->where('member_id', $member->getKey())
            ->where('status', AccessCredentialStatus::Active->value)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()->first();

        return response()->json([
            'data' => $credential ? (new MemberSelfCredentialResource($credential))->resolve($request) : null,
        ]);
    }

    public function rotateCredential(IssueAccessCredentialRequest $request, AttendanceService $attendance): JsonResponse
    {
        $result = $attendance->issueCredential(
            $this->memberFor($request), $request->validated(), $request->user(), $request,
        );
        $data = (new MemberSelfCredentialResource($result['credential']))->resolve($request);
        // Plaintext exists only in this response; later reads expose safe hint
        // and lifecycle metadata, never the credential or its digest.
        $data['credential'] = $result['plaintext'];
        return response()->json(['data' => $data], 201);
    }

    private function memberFor(Request $request): Member
    {
        // Tenant middleware and forced RLS are already active. Linking by the
        // authenticated user removes client-controlled member scope entirely.
        return Member::query()->where('user_id', $request->user()->getKey())->firstOrFail();
    }

    private function pageSize(Request $request, int $default): int
    {
        return min(max((int) $request->input('per_page', $default), 1), 100);
    }
}
