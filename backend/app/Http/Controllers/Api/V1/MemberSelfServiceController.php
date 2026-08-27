<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccessCredentialStatus;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IssueAccessCredentialRequest;
use App\Http\Requests\StoreMemberPaymentRequest;
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
use App\Services\PaymentService;
use App\Services\StripeGatewayService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            Payment::query()->with(['refunds', 'bankTransferReceipt'])->where('member_id', $member->getKey())
                ->orderByDesc('paid_at')->paginate($this->pageSize($request, 25))
        );
    }

    public function paymentOptions(Request $request, StripeGatewayService $stripe): JsonResponse
    {
        $this->memberFor($request);
        return response()->json(['data' => [
            'stripe_configured' => $stripe->memberPaymentsConfigured(),
            'stripe_available' => $stripe->checkoutAvailable(),
            'bank_transfer_available' => true,
            'cash_available_at_gym' => true,
        ]]);
    }

    public function storePayment(StoreMemberPaymentRequest $request, PaymentService $service): JsonResponse
    {
        $member = $this->memberFor($request);
        $invoice = Invoice::query()->findOrFail($request->validated('invoice_id'));
        if ($invoice->member_id !== $member->getKey() || ! $invoice->membership_id) {
            throw ValidationException::withMessages([
                'invoice_id' => ['Choose an open membership invoice that belongs to your account.'],
            ]);
        }
        if ($invoice->due_amount_minor <= 0) {
            throw ValidationException::withMessages(['invoice_id' => ['This invoice has no outstanding balance.']]);
        }

        $validated = $request->safe()->except('receipt');
        $result = $service->create([
            'member_id' => $member->getKey(),
            'membership_id' => $invoice->membership_id,
            'invoice_id' => $invoice->getKey(),
            'branch_id' => $invoice->branch_id,
            'method' => $validated['method'],
            // The invoice is the authoritative amount/currency boundary for
            // member-submitted payments; neither value comes from the browser.
            'amount_minor' => $invoice->due_amount_minor,
            'currency' => $invoice->currency->value,
            'idempotency_key' => $validated['idempotency_key'],
            'bank_reference' => $validated['bank_reference'] ?? null,
            'notes' => 'Submitted through member self-service.',
        ], $request->user(), $request, $request->file('receipt'));

        return response()->json([
            'data' => (new PaymentResource($result['payment']))->resolve($request),
            'meta' => [
                'checkout_url' => $result['checkout_url'],
                'idempotency_reused' => $result['reused'],
            ],
        ], $result['reused'] ? 200 : 201);
    }

    public function paymentReceipt(Request $request, string $payment): StreamedResponse
    {
        $member = $this->memberFor($request);
        $record = Payment::query()->with('bankTransferReceipt')
            ->where('member_id', $member->getKey())
            ->findOrFail($payment);
        abort_unless($record->bankTransferReceipt, 404);
        return PaymentController::streamReceipt($record->bankTransferReceipt);
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

    public function credential(Request $request, AttendanceService $attendance): JsonResponse
    {
        $member = $this->memberFor($request);
        $credential = MemberAccessCredential::query()->where('member_id', $member->getKey())
            ->where('status', AccessCredentialStatus::Active->value)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()->first();

        $data = $credential ? (new MemberSelfCredentialResource($credential))->resolve($request) : null;
        if ($credential && $data && $plaintext = $attendance->plaintextFor($credential)) {
            // Only the linked member receives the reconstructable opaque QR.
            // The database retains its digest, not bearer plaintext.
            $data['credential'] = $plaintext;
        }

        return response()->json(['data' => $data]);
    }

    public function ensureCredential(IssueAccessCredentialRequest $request, AttendanceService $attendance): JsonResponse
    {
        $result = $attendance->issueCredential(
            $this->memberFor($request), $request->validated(), $request->user(), $request,
        );
        $data = (new MemberSelfCredentialResource($result['credential']))->resolve($request);
        $data['credential'] = $result['plaintext'];
        return response()->json(['data' => $data], $result['created'] ? 201 : 200);
    }

    public function rotateCredential(IssueAccessCredentialRequest $request, AttendanceService $attendance): JsonResponse
    {
        $result = $attendance->rotateCredential(
            $this->memberFor($request), $request->validated(), $request->user(), $request,
        );
        $data = (new MemberSelfCredentialResource($result['credential']))->resolve($request);
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
