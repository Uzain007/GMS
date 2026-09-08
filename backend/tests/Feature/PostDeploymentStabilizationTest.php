<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\GymStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaasPlanStatus;
use App\Enums\UserRole;
use App\Jobs\SendAccountInvitation;
use App\Models\AuditLog;
use App\Models\Gym;
use App\Models\Member;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\SaasSubscriptionPayment;
use App\Models\User;
use App\Services\AuditService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostDeploymentStabilizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_saas_corrections_are_append_only_audited_and_exportable(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $gym = Gym::factory()->create();
        $otherGym = Gym::factory()->create();
        $plan = SaasPlan::query()->create([
            'code' => 'stability', 'name' => 'Stability', 'status' => SaasPlanStatus::Active,
            'feature_limits' => [], 'payment_methods' => ['cash', 'bank_transfer'], 'sort_order' => 1,
        ]);
        $price = SaasPlanPrice::query()->create([
            'saas_plan_id' => $plan->id, 'currency' => Currency::GBP, 'billing_interval' => 'monthly',
            'amount_minor' => 7900, 'trial_days' => 0, 'active' => true,
        ]);
        $payment = app(TenantContext::class)->run($gym, fn () => SaasSubscriptionPayment::query()->create([
            'saas_plan_price_id' => $price->id, 'submitted_by' => $admin->id,
            'method' => PaymentMethod::Cash, 'status' => PaymentStatus::Paid,
            'amount_minor' => 7900, 'currency' => Currency::GBP,
            'idempotency_key' => 'stabilization-payment', 'reference' => 'ORIGINAL-REF', 'paid_at' => now(),
        ]));
        auth()->guard('web')->logout();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments/{$payment->id}/corrections", [
            'reference' => 'CORRECTED-REF', 'method' => 'bank_transfer', 'internal_notes' => 'Matched to bank statement.',
            'metadata' => ['statement_line' => '42'], 'reason' => 'Reference corrected from verified bank statement.',
        ], ['X-Gym-ID' => $gym->id])->assertOk()
            ->assertJsonPath('data.reference', 'ORIGINAL-REF')
            ->assertJsonPath('data.effective_reference', 'CORRECTED-REF')
            ->assertJsonPath('data.effective_method', 'bank_transfer')
            ->assertJsonCount(1, 'data.corrections');

        app(TenantContext::class)->run($gym, function () use ($gym, $payment): void {
            // PostgreSQL FORCE RLS must remain active while proving both the
            // immutable ledger row and its tenant-owned correction evidence.
            $this->assertDatabaseHas('saas_subscription_payments', ['id' => $payment->id, 'reference' => 'ORIGINAL-REF', 'method' => 'cash']);
            $this->assertDatabaseHas('saas_payment_corrections', ['gym_id' => $gym->id, 'reference' => 'CORRECTED-REF']);
            $this->assertDatabaseHas('audit_logs', ['gym_id' => $gym->id, 'event' => 'saas.payment.correction_recorded']);
        });
        $this->get("/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments/{$payment->id}/ironcore-receipt", ['X-Gym-ID' => $gym->id])
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get("/api/v1/gyms/{$gym->id}/saas-subscription/payment-report?format=xlsx", ['X-Gym-ID' => $gym->id])
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->postJson("/api/v1/gyms/{$otherGym->id}/saas-subscription/manual-payments/{$payment->id}/corrections", [
            'reason' => 'Attempted cross tenant correction.',
        ], ['X-Gym-ID' => $otherGym->id])->assertNotFound();
    }

    public function test_platform_and_tenant_audit_history_use_the_existing_365_day_log(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $gym = Gym::factory()->create();
        Sanctum::actingAs($admin);
        app(TenantContext::class)->run($gym, fn () => app(AuditService::class)->record(
            'gym.settings.corrected', $gym, $admin, ['currency' => 'GBP'], ['currency' => 'PKR'], 'Owner approved the correction.', request(),
        ));

        $this->getJson('/api/v1/platform/audit-log?search=approved&per_page=25')->assertOk()
            ->assertJsonPath('data.0.action', 'gym.settings.corrected')
            ->assertJsonPath('data.0.role', 'super_admin')
            ->assertJsonPath('data.0.gym.id', $gym->id);
        $this->get('/api/v1/platform/audit-log?format=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $owner = User::factory()->create();
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($owner->id, [
            'role' => UserRole::GymOwner->value,
            'status' => 'active',
        ]));
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/platform/audit-log')->assertForbidden();
        $this->getJson("/api/v1/gyms/{$gym->id}/audit-log", ['X-Gym-ID' => $gym->id])->assertOk()
            ->assertJsonPath('data.0.gym.id', $gym->id);
    }

    public function test_suspended_and_archived_gyms_block_login_and_hard_delete_preserves_real_history(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $owner = User::factory()->create(['email' => 'owner.lifecycle@example.test', 'password' => 'OwnerPassword!234']);
        $gym = Gym::factory()->create(['name' => 'Historical Gym']);
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($owner->id, [
            'role' => UserRole::GymOwner->value,
            'status' => 'active',
        ]));
        app(TenantContext::class)->run($gym, fn () => Member::query()->create([
            'member_number' => 'M-001', 'first_name' => 'Real', 'last_name' => 'Member',
            'email' => 'real.member@example.test', 'phone' => '+44 7700 900000', 'status' => 'active',
        ]));
        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/gyms/{$gym->id}", ['status' => 'suspended', 'reason' => 'Payment and access review required.'], ['X-Gym-ID' => $gym->id])->assertOk();
        auth()->guard('sanctum')->forgetUser(); auth()->guard('web')->logout();
        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'OwnerPassword!234'])->assertUnprocessable();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/gyms/{$gym->id}", ['status' => 'active', 'reason' => 'Review complete and access restored.'], ['X-Gym-ID' => $gym->id])->assertOk();
        auth()->guard('sanctum')->forgetUser(); auth()->guard('web')->logout();
        $this->withHeaders($this->browserHeaders())->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'OwnerPassword!234'])->assertOk();

        auth()->guard('web')->logout();
        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/gyms/{$gym->id}", ['status' => 'cancelled', 'reason' => 'Gym contract ended and data must be retained.'], ['X-Gym-ID' => $gym->id])->assertOk();
        $this->deleteJson("/api/v1/gyms/{$gym->id}", ['confirmation' => $gym->name, 'reason' => 'Requested permanent deletion after closure.'], ['X-Gym-ID' => $gym->id])
            ->assertUnprocessable()->assertJsonValidationErrors('gym');
        $this->assertDatabaseHas('gyms', ['id' => $gym->id, 'status' => GymStatus::Cancelled->value]);
        app(TenantContext::class)->run($gym, function () use ($gym): void {
            $this->assertDatabaseHas('members', ['gym_id' => $gym->id, 'member_number' => 'M-001']);
        });

        $empty = Gym::factory()->create(['name' => 'Disposable Empty Gym', 'status' => GymStatus::Cancelled]);
        $this->deleteJson("/api/v1/gyms/{$empty->id}", ['confirmation' => $empty->name, 'reason' => 'Verified empty test tenant cleanup.'], ['X-Gym-ID' => $empty->id])->assertNoContent();
        $this->assertDatabaseMissing('gyms', ['id' => $empty->id]);
    }

    public function test_invitation_mail_job_uses_shared_mailer_and_keeps_token_out_of_logs(): void
    {
        Mail::shouldReceive('raw')->once();
        config(['app.frontend_url' => 'https://app.ironcore.website']);
        $job = new SendAccountInvitation('member@example.test', 'gym-123', 'Northstar Fitness', str_repeat('a', 64), 'member');
        $job->handle();
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class, $job);
    }

    /** @return array<string, string> */
    private function browserHeaders(): array
    {
        return ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];
    }
}
