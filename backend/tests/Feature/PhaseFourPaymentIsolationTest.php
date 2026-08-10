<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\MemberStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Payment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseFourPaymentIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_totals_are_server_calculated_and_cash_settlement_is_atomic(): void
    {
        [$owner, $gym, $member] = $this->tenant(UserRole::GymOwner);
        Sanctum::actingAs($owner);

        $invoiceId = $this->postJson("/api/v1/gyms/{$gym->id}/invoices", [
            'member_id' => $member->id,
            'currency' => Currency::GBP->value,
            'items' => [[
                'description' => 'Monthly membership',
                'quantity' => 2,
                'unit_amount_minor' => 2500,
                'tax_amount_minor' => 100,
                // A malicious client total is ignored because it is not accepted.
                'total_amount_minor' => 1,
            ]],
        ], ['X-Gym-ID' => $gym->id])
            ->assertSuccessful()
            ->assertJsonPath('data.subtotal_amount_minor', 5000)
            ->assertJsonPath('data.total_amount_minor', 5100)
            ->json('data.id');

        $this->postJson("/api/v1/gyms/{$gym->id}/payments", [
            'member_id' => $member->id,
            'invoice_id' => $invoiceId,
            'method' => 'cash',
            'amount_minor' => 5100,
            'currency' => Currency::GBP->value,
            'idempotency_key' => 'cash-test-001',
        ], ['X-Gym-ID' => $gym->id])
            ->assertCreated()
            ->assertJsonPath('data.status', PaymentStatus::Succeeded->value);

        app(TenantContext::class)->run($gym, function () use ($invoiceId): void {
            $invoice = Invoice::query()->findOrFail($invoiceId);
            $this->assertSame(0, $invoice->due_amount_minor);
            $this->assertSame('paid', $invoice->status->value);
        });
    }

    public function test_cross_tenant_payment_ids_fail_closed_and_manager_cannot_refund(): void
    {
        [$owner, $allowedGym, $allowedMember] = $this->tenant(UserRole::GymOwner);
        [, $blockedGym, $blockedMember] = $this->tenant(UserRole::GymOwner);
        $blockedPayment = app(TenantContext::class)->run($blockedGym, fn () => Payment::query()->create([
            'member_id' => $blockedMember->id,
            'recorded_by' => $owner->id,
            'receipt_number' => 'PAY-BLOCKED',
            'provider' => 'manual',
            'method' => 'cash',
            'status' => 'succeeded',
            'amount_minor' => 1000,
            'currency' => Currency::GBP,
            'idempotency_key' => 'blocked-payment',
            'paid_at' => now(),
        ]));
        Sanctum::actingAs($owner);

        // Another tenant's UUID remains indistinguishable from a missing record.
        $this->getJson("/api/v1/gyms/{$allowedGym->id}/payments/{$blockedPayment->id}", [
            'X-Gym-ID' => $allowedGym->id,
        ])->assertNotFound();

        $manager = User::factory()->create();
        $this->attachRole($allowedGym, $manager, UserRole::GymManager);
        $payment = app(TenantContext::class)->run($allowedGym, fn () => Payment::query()->create([
            'member_id' => $allowedMember->id,
            'recorded_by' => $owner->id,
            'receipt_number' => 'PAY-ALLOWED',
            'provider' => 'manual',
            'method' => 'cash',
            'status' => 'succeeded',
            'amount_minor' => 1000,
            'currency' => Currency::GBP,
            'idempotency_key' => 'allowed-payment',
            'paid_at' => now(),
        ]));
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/gyms/{$allowedGym->id}/payments/{$payment->id}/refunds", [
            'amount_minor' => 500,
            'reason' => 'Member requested cancellation',
        ], ['X-Gym-ID' => $allowedGym->id])->assertForbidden();
    }

    /** @return array{User, Gym, Member} */
    private function tenant(UserRole $role): array
    {
        $user = User::factory()->create();
        $gym = Gym::factory()->create();
        $this->attachRole($gym, $user, $role);
        $member = app(TenantContext::class)->run($gym, fn () => Member::query()->create([
            'member_number' => 'MBR-'.str()->ulid(),
            'first_name' => 'Finance',
            'last_name' => 'Member',
            'status' => MemberStatus::Active,
            'joined_at' => now(),
        ]));
        return [$user, $gym, $member];
    }

    private function attachRole(Gym $gym, User $user, UserRole $role): void
    {
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($user, [
            'role' => $role->value,
            'status' => 'active',
        ]));
    }
}
