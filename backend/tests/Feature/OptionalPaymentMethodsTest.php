<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\MemberStatus;
use App\Enums\PaymentGatewayStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\GymBranch;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\User;
use App\Services\PaymentService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OptionalPaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_and_cash_payment_work_when_stripe_is_not_configured(): void
    {
        $this->disableStripe();
        [$owner, $memberUser, $gym, $branch, $member, $membership, $invoice] = $this->contract('CASH');
        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];

        $this->getJson("/api/v1/gyms/{$gym->id}/payment-gateways/stripe", $headers)
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.provider_configured', false)
            ->assertJsonPath('meta.checkout_available', false);

        $payment = $this->postJson("/api/v1/gyms/{$gym->id}/payments", [
            'member_id' => $member->id,
            'membership_id' => $membership['id'],
            'invoice_id' => $invoice['id'],
            'branch_id' => $branch->id,
            'method' => 'cash',
            'amount_minor' => $invoice['due_amount_minor'],
            'currency' => Currency::GBP->value,
            'idempotency_key' => 'optional-cash-payment',
        ], $headers)->assertCreated()->assertJsonPath('data.status', 'paid')->json('data');

        app(TenantContext::class)->run($gym, function () use ($payment, $invoice): void {
            $this->assertSame(0, \App\Models\Invoice::query()->findOrFail($invoice['id'])->due_amount_minor);
            $this->assertDatabaseHas('audit_logs', [
                'gym_id' => $payment['gym_id'],
                'event' => 'payment.created',
                'auditable_id' => $payment['id'],
            ]);
        });

        // The linked member can still see cash history even though no online
        // provider exists for this tenant.
        Sanctum::actingAs($memberUser);
        $this->getJson("/api/v1/gyms/{$gym->id}/member/payments", $headers)
            ->assertOk()->assertJsonPath('data.0.status', 'paid');
    }

    public function test_member_bank_transfer_receipt_is_private_and_requires_admin_review(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        [$owner, $memberUser, $gym, $branch, $member, $membership, $invoice] = $this->contract('BANK');
        $headers = ['X-Gym-ID' => $gym->id, 'Accept' => 'application/json'];

        Sanctum::actingAs($memberUser);
        $response = $this->post("/api/v1/gyms/{$gym->id}/member/payments", [
            'invoice_id' => $invoice['id'],
            'method' => 'bank_transfer',
            'idempotency_key' => 'member-bank-transfer-001',
            'bank_reference' => 'BANK-REFERENCE-2048',
            'receipt' => UploadedFile::fake()->create('bank-receipt.pdf', 80, 'application/pdf'),
        ], $headers)->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.member_id', $member->id)
            ->assertJsonPath('data.membership_id', $membership['id'])
            ->assertJsonPath('data.invoice_id', $invoice['id'])
            ->assertJsonPath('data.bank_transfer_receipt.bank_reference', 'BANK-REFERENCE-2048');

        $payment = $response->json('data');
        $receipt = app(TenantContext::class)->run($gym, fn () => Payment::query()
            ->with('bankTransferReceipt')->findOrFail($payment['id'])->bankTransferReceipt);
        $this->assertNotNull($receipt);
        $this->assertStringStartsWith("gyms/{$gym->id}/payments/{$payment['id']}/bank-transfer/", $receipt->storage_path);
        Storage::disk('local')->assertExists($receipt->storage_path);

        $receiptResponse = $this->get("/api/v1/gyms/{$gym->id}/member/payments/{$payment['id']}/receipt", $headers)
            ->assertOk();
        $this->assertStringContainsString('private', (string) $receiptResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $receiptResponse->headers->get('Cache-Control'));

        app(TenantContext::class)->run($gym, function () use ($invoice): void {
            $this->assertSame($invoice['due_amount_minor'], \App\Models\Invoice::query()->findOrFail($invoice['id'])->due_amount_minor);
        });

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/gyms/{$gym->id}/payments/{$payment['id']}/bank-transfer-review", [
            'decision' => 'approve',
            'reason' => 'Receipt and bank statement match.',
        ], ['X-Gym-ID' => $gym->id])->assertOk()->assertJsonPath('data.status', 'paid');

        app(TenantContext::class)->run($gym, function () use ($invoice, $payment): void {
            $this->assertSame(0, \App\Models\Invoice::query()->findOrFail($invoice['id'])->due_amount_minor);
            $this->assertDatabaseHas('audit_logs', [
                'gym_id' => $payment['gym_id'],
                'event' => 'payment.bank_transfer_approved',
                'auditable_id' => $payment['id'],
            ]);
        });

        $this->postJson("/api/v1/gyms/{$gym->id}/payments/{$payment['id']}/refunds", [
            'amount_minor' => $payment['amount_minor'],
            'reason' => 'Approved transfer returned to member.',
        ], ['X-Gym-ID' => $gym->id])->assertOk();
        app(TenantContext::class)->run($gym, fn () => $this->assertSame(
            PaymentStatus::Refunded,
            Payment::query()->findOrFail($payment['id'])->status,
        ));

        [$otherOwner, , $otherGym] = $this->tenant('OTHER');
        if (DB::connection()->getDriverName() === 'pgsql') {
            app(TenantContext::class)->run($otherGym, fn () => $this->assertSame(
                0,
                DB::table('bank_transfer_receipts')->where('id', $receipt->id)->count(),
            ));
        }
        Sanctum::actingAs($otherOwner);
        $this->get("/api/v1/gyms/{$otherGym->id}/payments/{$payment['id']}/receipt", [
            'X-Gym-ID' => $otherGym->id,
            'Accept' => 'application/json',
        ])->assertNotFound();
    }

    public function test_bank_transfer_can_be_rejected_without_settling_invoice(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        [$owner, $memberUser, $gym, , , , $invoice] = $this->contract('REJECT');
        Sanctum::actingAs($memberUser);
        $payment = $this->post("/api/v1/gyms/{$gym->id}/member/payments", [
            'invoice_id' => $invoice['id'],
            'method' => 'bank_transfer',
            'idempotency_key' => 'member-bank-transfer-rejected',
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 40, 'application/pdf'),
        ], ['X-Gym-ID' => $gym->id, 'Accept' => 'application/json'])->assertCreated()->json('data');

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/gyms/{$gym->id}/payments/{$payment['id']}/bank-transfer-review", [
            'decision' => 'reject',
            'reason' => 'Reference is not present on the bank statement.',
        ], ['X-Gym-ID' => $gym->id])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.bank_transfer_receipt.review_reason', 'Reference is not present on the bank statement.');

        app(TenantContext::class)->run($gym, fn () => $this->assertSame(
            $invoice['due_amount_minor'],
            \App\Models\Invoice::query()->findOrFail($invoice['id'])->due_amount_minor,
        ));
    }

    public function test_member_stripe_checkout_is_available_only_for_an_active_configured_gateway(): void
    {
        [$owner, $memberUser, $gym, , , , $invoice] = $this->contract('STRIPE');
        $this->configureStripe();
        app(TenantContext::class)->run($gym, fn () => PaymentGatewayAccount::query()->create([
            'provider' => PaymentProvider::Stripe,
            'provider_account_id' => 'acct_optional_stripe',
            'status' => PaymentGatewayStatus::Active,
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'country_code' => 'GB',
            'default_currency' => Currency::GBP,
        ]));
        Http::fake([
            'https://api.stripe.test/v1/checkout/sessions' => Http::response([
                'id' => 'cs_optional_payment',
                'url' => 'https://checkout.stripe.test/cs_optional_payment',
            ]),
        ]);

        Sanctum::actingAs($memberUser);
        $headers = ['X-Gym-ID' => $gym->id];
        $this->getJson("/api/v1/gyms/{$gym->id}/member/payment-options", $headers)
            ->assertOk()->assertJsonPath('data.stripe_available', true);
        $payment = $this->postJson("/api/v1/gyms/{$gym->id}/member/payments", [
            'invoice_id' => $invoice['id'],
            'method' => 'online_card',
            'idempotency_key' => 'member-stripe-payment-001',
        ], $headers)->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('meta.checkout_url', 'https://checkout.stripe.test/cs_optional_payment')
            ->json('data');

        app(TenantContext::class)->run($gym, function () use ($payment, $invoice): void {
            app(PaymentService::class)->markCheckoutSucceeded($payment['id'], 'pi_optional_payment');
            $this->assertSame(PaymentStatus::Paid, Payment::query()->findOrFail($payment['id'])->status);
            $this->assertSame(0, \App\Models\Invoice::query()->findOrFail($invoice['id'])->due_amount_minor);
        });
        Http::assertSent(fn ($request) => $request->hasHeader('Stripe-Account', 'acct_optional_stripe'));
    }

    /** @return array{User, User, Gym, GymBranch, Member, array<string,mixed>, array<string,mixed>} */
    private function contract(string $suffix): array
    {
        [$owner, $memberUser, $gym, $branch] = $this->tenant($suffix);
        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $plan = $this->postJson("/api/v1/gyms/{$gym->id}/membership-plans", [
            'branch_id' => $branch->id,
            'name' => "Optional Payments {$suffix}",
            'code' => "OPTIONAL-{$suffix}",
            'billing_interval' => 'monthly',
            'price_amount_minor' => 6500,
            'currency' => Currency::GBP->value,
            'status' => 'active',
        ], $headers)->assertCreated()->json('data');
        $memberData = $this->postJson("/api/v1/gyms/{$gym->id}/members", [
            'home_branch_id' => $branch->id,
            'first_name' => 'Payment',
            'last_name' => $suffix,
            'email' => strtolower($suffix).'@payments.example.test',
            'phone' => '+44 7700 900502',
            'status' => MemberStatus::Active->value,
        ], $headers)->assertCreated()->json('data');
        $member = app(TenantContext::class)->run($gym, function () use ($memberData, $memberUser): Member {
            $member = Member::query()->findOrFail($memberData['id']);
            $member->update(['user_id' => $memberUser->id]);
            return $member->fresh();
        });
        $membership = $this->postJson("/api/v1/gyms/{$gym->id}/memberships", [
            'member_id' => $member->id,
            'plan_id' => $plan['id'],
            'branch_id' => $branch->id,
            'starts_at' => today()->toDateString(),
            'status' => 'active',
        ], $headers)->assertCreated()->json('data');
        $invoice = $this->postJson("/api/v1/gyms/{$gym->id}/invoices", [
            'member_id' => $member->id,
            'membership_id' => $membership['id'],
            'branch_id' => $branch->id,
            'currency' => Currency::GBP->value,
            'items' => [[
                'description' => "Membership {$suffix}",
                'quantity' => 1,
                'unit_amount_minor' => 6500,
            ]],
        ], $headers)->assertCreated()->json('data');

        return [$owner, $memberUser, $gym, $branch, $member, $membership, $invoice];
    }

    /** @return array{User, User, Gym, GymBranch} */
    private function tenant(string $suffix): array
    {
        $owner = User::factory()->create();
        $memberUser = User::factory()->create();
        $gym = Gym::factory()->create([
            'name' => "Optional Payment Gym {$suffix}",
            'base_currency' => Currency::GBP,
        ]);
        app(TenantContext::class)->run($gym, function () use ($gym, $owner, $memberUser): void {
            $gym->users()->attach($owner, ['role' => UserRole::GymOwner->value, 'status' => 'active']);
            $gym->users()->attach($memberUser, ['role' => UserRole::Member->value, 'status' => 'active']);
        });
        $branch = app(TenantContext::class)->run($gym, fn () => GymBranch::query()->create([
            'name' => "Branch {$suffix}",
            'code' => "BR-{$suffix}",
            'status' => 'active',
            'is_primary' => true,
        ]));

        return [$owner, $memberUser, $gym, $branch];
    }

    private function disableStripe(): void
    {
        config([
            'services.stripe.secret' => null,
            'services.stripe.webhook_secret' => null,
            'services.stripe.connect_refresh_url' => null,
            'services.stripe.connect_return_url' => null,
            'services.stripe.checkout_success_url' => null,
            'services.stripe.checkout_cancel_url' => null,
        ]);
    }

    private function configureStripe(): void
    {
        config([
            'services.stripe.secret' => 'optional-stripe-secret',
            'services.stripe.webhook_secret' => 'optional-stripe-webhook',
            'services.stripe.api_url' => 'https://api.stripe.test',
            'services.stripe.connect_refresh_url' => 'https://app.ironcore.test/payments?stripe=refresh',
            'services.stripe.connect_return_url' => 'https://app.ironcore.test/payments?stripe=return',
            'services.stripe.checkout_success_url' => 'https://app.ironcore.test/payments?checkout=success',
            'services.stripe.checkout_cancel_url' => 'https://app.ironcore.test/payments?checkout=cancelled',
        ]);
    }
}
