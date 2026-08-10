<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly StripeGatewayService $stripe,
    ) {}

    /** @return array{payment: Payment, checkout_url: ?string, reused: bool} */
    public function create(array $data, User $actor, Request $request): array
    {
        $existing = Payment::query()->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return ['payment' => $existing->load('refunds'), 'checkout_url' => null, 'reused' => true];
        }

        $method = PaymentMethod::from($data['method']);
        $payment = DB::transaction(function () use ($data, $actor, $request, $method): Payment {
            $member = Member::query()->findOrFail($data['member_id']);
            $membership = isset($data['membership_id'])
                ? Membership::query()->findOrFail($data['membership_id'])
                : null;
            $invoice = isset($data['invoice_id'])
                ? Invoice::query()->lockForUpdate()->findOrFail($data['invoice_id'])
                : null;

            $this->validateRelationships($member, $membership, $invoice, $data);
            $online = $method->isOnline();
            $payment = Payment::query()->create([
                'member_id' => $member->getKey(),
                'membership_id' => $membership?->getKey(),
                'invoice_id' => $invoice?->getKey(),
                'branch_id' => $data['branch_id'] ?? $membership?->branch_id ?? $member->home_branch_id,
                'recorded_by' => $actor->getKey(),
                'receipt_number' => 'PAY-'.Str::upper((string) Str::ulid()),
                'provider' => $online ? PaymentProvider::Stripe : PaymentProvider::Manual,
                'method' => $method,
                'status' => $online ? PaymentStatus::Pending : PaymentStatus::Succeeded,
                'amount_minor' => (int) $data['amount_minor'],
                'refunded_amount_minor' => 0,
                'currency' => $data['currency'],
                'idempotency_key' => $data['idempotency_key'],
                'paid_at' => $online ? null : ($data['paid_at'] ?? now()),
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            if (! $online && $invoice) {
                $this->applyPaymentToInvoice($invoice, $payment->amount_minor);
            }
            $this->audit->record('payment.created', $payment, $actor, after: $payment->toArray(), request: $request);
            return $payment;
        });

        if (! $method->isOnline()) {
            return ['payment' => $payment->load('refunds'), 'checkout_url' => null, 'reused' => false];
        }

        try {
            $checkout = $this->stripe->createCheckout($payment);
            $payment->update(['provider_checkout_id' => $checkout['checkout_id']]);
            return ['payment' => $payment->fresh()->load('refunds'), 'checkout_url' => $checkout['checkout_url'], 'reused' => false];
        } catch (Throwable $exception) {
            $payment->update([
                'status' => PaymentStatus::Failed,
                'failed_at' => now(),
                'failure_code' => 'checkout_creation_failed',
                'failure_message' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            throw $exception;
        }
    }

    public function refund(Payment $payment, array $data, User $actor, Request $request): PaymentRefund
    {
        $refund = DB::transaction(function () use ($payment, $data, $actor): PaymentRefund {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());
            if (! in_array($locked->status, [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded], true)) {
                throw ValidationException::withMessages(['payment' => ['Only a settled payment can be refunded.']]);
            }

            $remaining = $locked->amount_minor - $locked->refunded_amount_minor;
            if ((int) $data['amount_minor'] > $remaining) {
                throw ValidationException::withMessages(['amount_minor' => ['The refund exceeds the remaining settled amount.']]);
            }

            return PaymentRefund::query()->create([
                'payment_id' => $locked->getKey(),
                'recorded_by' => $actor->getKey(),
                'status' => $locked->provider === PaymentProvider::Stripe ? RefundStatus::Pending : RefundStatus::Succeeded,
                'amount_minor' => (int) $data['amount_minor'],
                'currency' => $locked->currency,
                'reason' => $data['reason'],
                'refunded_at' => $locked->provider === PaymentProvider::Manual ? now() : null,
            ]);
        });

        if ($payment->provider === PaymentProvider::Stripe) {
            try {
                $provider = $this->stripe->createRefund($payment, $refund);
                $refund->update([
                    'status' => RefundStatus::Succeeded,
                    'provider_refund_id' => $provider['refund_id'],
                    'refunded_at' => now(),
                ]);
            } catch (Throwable $exception) {
                $refund->update([
                    'status' => RefundStatus::Failed,
                    'failure_code' => 'provider_refund_failed',
                    'failure_message' => mb_substr($exception->getMessage(), 0, 1000),
                ]);
                throw $exception;
            }
        }

        $this->finalizeRefund($refund);
        $this->audit->record(
            'payment.refunded',
            $payment->fresh(),
            $actor,
            before: ['refunded_amount_minor' => $payment->refunded_amount_minor],
            after: ['refund_id' => $refund->getKey(), 'amount_minor' => $refund->amount_minor],
            reason: $data['reason'],
            request: $request,
        );

        return $refund->fresh();
    }

    public function markCheckoutSucceeded(string $paymentId, ?string $providerPaymentId): Payment
    {
        return DB::transaction(function () use ($paymentId, $providerPaymentId): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
            if ($payment->status === PaymentStatus::Succeeded) {
                return $payment;
            }
            if ($payment->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages(['payment' => ['The payment is not awaiting settlement.']]);
            }

            $payment->update([
                'status' => PaymentStatus::Succeeded,
                'provider_payment_id' => $providerPaymentId,
                'paid_at' => now(),
                'failed_at' => null,
                'failure_code' => null,
                'failure_message' => null,
            ]);
            if ($payment->invoice_id) {
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
                $this->applyPaymentToInvoice($invoice, $payment->amount_minor);
            }
            $fresh = $payment->fresh();
            $this->audit->record('payment.succeeded', $fresh, null, after: $fresh->toArray());
            return $fresh;
        });
    }

    public function markCheckoutFailed(string $paymentId, string $code, string $message): Payment
    {
        $payment = Payment::query()->findOrFail($paymentId);
        if ($payment->status === PaymentStatus::Pending) {
            $payment->update([
                'status' => PaymentStatus::Failed,
                'failed_at' => now(),
                'failure_code' => mb_substr($code, 0, 100),
                'failure_message' => mb_substr($message, 0, 1000),
            ]);
        }
        return $payment->fresh();
    }

    /** @return array{gross_minor: int, refunded_minor: int, net_minor: int, pending_minor: int, outstanding_minor: int, currency: string} */
    public function summary(string $currency): array
    {
        $settled = [PaymentStatus::Succeeded->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value];
        $gross = (int) Payment::query()->where('currency', $currency)->whereIn('status', $settled)->sum('amount_minor');
        $refunded = (int) PaymentRefund::query()->where('currency', $currency)->where('status', RefundStatus::Succeeded->value)->sum('amount_minor');
        $pending = (int) Payment::query()->where('currency', $currency)->where('status', PaymentStatus::Pending->value)->sum('amount_minor');
        $outstanding = (int) Invoice::query()->where('currency', $currency)->where('status', InvoiceStatus::Open->value)->sum('due_amount_minor');

        return [
            'gross_minor' => $gross,
            'refunded_minor' => $refunded,
            'net_minor' => max(0, $gross - $refunded),
            'pending_minor' => $pending,
            'outstanding_minor' => $outstanding,
            'currency' => $currency,
        ];
    }

    private function validateRelationships(Member $member, ?Membership $membership, ?Invoice $invoice, array $data): void
    {
        if ($membership && $membership->member_id !== $member->getKey()) {
            throw ValidationException::withMessages(['membership_id' => ['The membership does not belong to the selected member.']]);
        }
        if ($invoice && $invoice->member_id !== $member->getKey()) {
            throw ValidationException::withMessages(['invoice_id' => ['The invoice does not belong to the selected member.']]);
        }
        if ($invoice && $invoice->status !== InvoiceStatus::Open) {
            throw ValidationException::withMessages(['invoice_id' => ['Only an open invoice can receive a payment.']]);
        }
        if ($invoice && $invoice->currency->value !== $data['currency']) {
            throw ValidationException::withMessages(['currency' => ['The payment currency must match the invoice.']]);
        }
        if ($invoice && (int) $data['amount_minor'] > $invoice->due_amount_minor) {
            throw ValidationException::withMessages(['amount_minor' => ['The payment exceeds the invoice balance.']]);
        }
    }

    private function applyPaymentToInvoice(Invoice $invoice, int $amount): void
    {
        $paid = min($invoice->total_amount_minor, $invoice->paid_amount_minor + $amount);
        $due = max(0, $invoice->total_amount_minor - $paid);
        $invoice->update([
            'paid_amount_minor' => $paid,
            'due_amount_minor' => $due,
            'status' => $due === 0 ? InvoiceStatus::Paid : InvoiceStatus::Open,
            'paid_at' => $due === 0 ? now() : null,
        ]);
    }

    private function finalizeRefund(PaymentRefund $refund): void
    {
        DB::transaction(function () use ($refund): void {
            $payment = Payment::query()->lockForUpdate()->findOrFail($refund->payment_id);
            $refunded = min($payment->amount_minor, $payment->refunded_amount_minor + $refund->amount_minor);
            $payment->update([
                'refunded_amount_minor' => $refunded,
                'status' => $refunded === $payment->amount_minor
                    ? PaymentStatus::Refunded
                    : PaymentStatus::PartiallyRefunded,
            ]);

            if ($payment->invoice_id) {
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
                $paid = max(0, $invoice->paid_amount_minor - $refund->amount_minor);
                $invoice->update([
                    'paid_amount_minor' => $paid,
                    'due_amount_minor' => $invoice->total_amount_minor - $paid,
                    'status' => InvoiceStatus::Open,
                    'paid_at' => null,
                ]);
            }
        });
    }
}
