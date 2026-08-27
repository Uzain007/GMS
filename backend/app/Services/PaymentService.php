<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\BankTransferReceipt;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
    public function create(array $data, User $actor, Request $request, ?UploadedFile $receipt = null): array
    {
        $existing = Payment::query()->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return ['payment' => $existing->load(['refunds', 'bankTransferReceipt']), 'checkout_url' => null, 'reused' => true];
        }

        $method = PaymentMethod::from($data['method']);
        if ($method->isOnline()) {
            // Check capability before creating a ledger row. An unconfigured
            // optional provider must not create a misleading rejected payment.
            $this->stripe->assertCheckoutAvailable();
        }

        $storedReceipt = null;
        try {
            $payment = DB::transaction(function () use ($data, $actor, $request, $method, $receipt, &$storedReceipt): Payment {
                $member = Member::query()->findOrFail($data['member_id']);
                $membership = isset($data['membership_id'])
                    ? Membership::query()->findOrFail($data['membership_id'])
                    : null;
                $invoice = isset($data['invoice_id'])
                    ? Invoice::query()->lockForUpdate()->findOrFail($data['invoice_id'])
                    : null;

                $this->validateRelationships($member, $membership, $invoice, $data);
                if ($method === PaymentMethod::BankTransfer && (! $membership || ! $receipt)) {
                    throw ValidationException::withMessages([
                        'receipt' => ['A bank-transfer receipt and linked membership are required.'],
                    ]);
                }

                $pending = $method->isOnline() || $method === PaymentMethod::BankTransfer;
                $payment = Payment::query()->create([
                    'member_id' => $member->getKey(),
                    'membership_id' => $membership?->getKey(),
                    'invoice_id' => $invoice?->getKey(),
                    'branch_id' => $data['branch_id'] ?? $membership?->branch_id ?? $member->home_branch_id,
                    'recorded_by' => $actor->getKey(),
                    'receipt_number' => 'PAY-'.Str::upper((string) Str::ulid()),
                    'provider' => $method->isOnline() ? PaymentProvider::Stripe : PaymentProvider::Manual,
                    'method' => $method,
                    'status' => $pending ? PaymentStatus::Pending : PaymentStatus::Paid,
                    'amount_minor' => (int) $data['amount_minor'],
                    'refunded_amount_minor' => 0,
                    'currency' => $data['currency'],
                    'idempotency_key' => $data['idempotency_key'],
                    'paid_at' => $pending ? null : ($data['paid_at'] ?? now()),
                    'notes' => $data['notes'] ?? null,
                    'metadata' => $data['metadata'] ?? null,
                ]);

                if ($method === PaymentMethod::BankTransfer && $receipt) {
                    $storedReceipt = $this->storeBankTransferReceipt(
                        $payment, $member, $membership, $invoice, $actor, $receipt, $data['bank_reference'] ?? null,
                    );
                    $this->audit->record(
                        'payment.bank_transfer_submitted',
                        $payment,
                        $actor,
                        after: [
                            'payment_id' => $payment->getKey(),
                            'receipt_id' => $storedReceipt['model']->getKey(),
                            'amount_minor' => $payment->amount_minor,
                            'currency' => $payment->currency->value,
                        ],
                        request: $request,
                    );
                }

                if (! $pending && $invoice) {
                    $this->applyPaymentToInvoice($invoice, $payment->amount_minor);
                }
                $this->audit->record('payment.created', $payment, $actor, after: $payment->toArray(), request: $request);
                return $payment;
            });
        } catch (Throwable $exception) {
            if ($storedReceipt) {
                Storage::disk($storedReceipt['disk'])->delete($storedReceipt['path']);
            }
            throw $exception;
        }

        if (! $method->isOnline()) {
            return ['payment' => $payment->load(['refunds', 'bankTransferReceipt']), 'checkout_url' => null, 'reused' => false];
        }

        try {
            $checkout = $this->stripe->createCheckout($payment);
            $payment->update(['provider_checkout_id' => $checkout['checkout_id']]);
            return ['payment' => $payment->fresh()->load(['refunds', 'bankTransferReceipt']), 'checkout_url' => $checkout['checkout_url'], 'reused' => false];
        } catch (Throwable $exception) {
            $payment->update([
                'status' => PaymentStatus::Rejected,
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
            if (! in_array($locked->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
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
            if ($payment->status === PaymentStatus::Paid) {
                return $payment;
            }
            if ($payment->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages(['payment' => ['The payment is not awaiting settlement.']]);
            }

            $payment->update([
                'status' => PaymentStatus::Paid,
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
            $this->audit->record('payment.paid', $fresh, null, after: $fresh->toArray());
            return $fresh;
        });
    }

    public function markCheckoutFailed(string $paymentId, string $code, string $message): Payment
    {
        $payment = Payment::query()->findOrFail($paymentId);
        if ($payment->status === PaymentStatus::Pending) {
            $payment->update([
                'status' => PaymentStatus::Rejected,
                'failed_at' => now(),
                'failure_code' => mb_substr($code, 0, 100),
                'failure_message' => mb_substr($message, 0, 1000),
            ]);
        }
        return $payment->fresh();
    }

    public function reviewBankTransfer(Payment $payment, array $data, User $actor, Request $request): Payment
    {
        return DB::transaction(function () use ($payment, $data, $actor, $request): Payment {
            $locked = Payment::query()->with('bankTransferReceipt')->lockForUpdate()->findOrFail($payment->getKey());
            if ($locked->method !== PaymentMethod::BankTransfer || ! $locked->bankTransferReceipt) {
                throw ValidationException::withMessages(['payment' => ['This payment has no bank-transfer receipt to review.']]);
            }
            if ($locked->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages(['payment' => ['Only a pending bank transfer can be reviewed.']]);
            }

            $before = ['status' => $locked->status->value, 'paid_at' => $locked->paid_at?->toIso8601String()];
            $approved = $data['decision'] === 'approve';
            if ($approved && $locked->invoice_id) {
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($locked->invoice_id);
                if ($invoice->status !== InvoiceStatus::Open || $invoice->due_amount_minor < $locked->amount_minor) {
                    throw ValidationException::withMessages([
                        'payment' => ['The linked invoice no longer has enough outstanding balance for this transfer.'],
                    ]);
                }
                $this->applyPaymentToInvoice($invoice, $locked->amount_minor);
            }

            $locked->update($approved ? [
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'failed_at' => null,
                'failure_code' => null,
                'failure_message' => null,
            ] : [
                'status' => PaymentStatus::Rejected,
                'paid_at' => null,
                'failed_at' => now(),
                'failure_code' => 'bank_transfer_rejected',
                'failure_message' => 'The submitted bank transfer was rejected by the gym.',
            ]);
            $locked->bankTransferReceipt->update([
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_reason' => $data['reason'],
            ]);

            $fresh = $locked->fresh()->load(['refunds', 'bankTransferReceipt']);
            $this->audit->record(
                $approved ? 'payment.bank_transfer_approved' : 'payment.bank_transfer_rejected',
                $fresh,
                $actor,
                before: $before,
                after: ['status' => $fresh->status->value, 'reviewed_at' => $fresh->bankTransferReceipt?->reviewed_at?->toIso8601String()],
                reason: $data['reason'],
                request: $request,
            );

            return $fresh;
        });
    }

    /** @return array{gross_minor: int, refunded_minor: int, net_minor: int, pending_minor: int, outstanding_minor: int, currency: string} */
    public function summary(string $currency): array
    {
        $settled = [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value];
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

    /** @return array{model: BankTransferReceipt, disk: string, path: string} */
    private function storeBankTransferReceipt(
        Payment $payment,
        Member $member,
        Membership $membership,
        ?Invoice $invoice,
        User $actor,
        UploadedFile $receipt,
        ?string $bankReference,
    ): array {
        $disk = (string) config('filesystems.default');
        $extension = $receipt->guessExtension() ?: 'bin';
        $directory = "gyms/{$payment->gym_id}/payments/{$payment->getKey()}/bank-transfer";
        $path = Storage::disk($disk)->putFileAs(
            $directory,
            $receipt,
            Str::uuid().'.'.$extension,
            ['visibility' => 'private'],
        );
        if (! $path) {
            throw ValidationException::withMessages(['receipt' => ['The bank-transfer receipt could not be stored.']]);
        }

        try {
            $model = BankTransferReceipt::query()->create([
                'payment_id' => $payment->getKey(),
                'member_id' => $member->getKey(),
                'membership_id' => $membership->getKey(),
                'invoice_id' => $invoice?->getKey(),
                'submitted_by' => $actor->getKey(),
                'bank_reference' => filled($bankReference) ? trim((string) $bankReference) : null,
                'storage_disk' => $disk,
                'storage_path' => $path,
                'original_name' => Str::limit(basename($receipt->getClientOriginalName()), 240, ''),
                'mime_type' => (string) $receipt->getMimeType(),
                'size_bytes' => (int) $receipt->getSize(),
                'content_sha256' => hash_file('sha256', $receipt->getRealPath()),
            ]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }

        return ['model' => $model, 'disk' => $disk, 'path' => $path];
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
