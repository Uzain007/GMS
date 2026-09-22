<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\GymStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\SaasBillingInvoice;
use App\Models\SaasBillingNotification;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\SaasPaymentApprovalReversal;
use App\Models\SaasSubscriptionPayment;
use App\Models\User;
use App\Jobs\SendSaasBillingReminder;
use App\Services\AutomatedSaasBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompleteSaasBillingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.default' => 'local', 'platform_billing.bank_transfer' => [
            'account_name' => 'IronCore Test Platform',
            'bank_name' => 'Test Bank',
            'account_number_or_iban' => 'GB00TEST00000000000000',
            'routing_details' => '00-00-00',
            'payment_instructions' => 'Use the SaaS invoice number.',
        ]]);
        Storage::fake('local');
    }

    public function test_owner_catalogue_shows_only_published_plans_and_all_active_intervals(): void
    {
        [$owner, $gym] = $this->tenant(UserRole::GymOwner);
        [$active] = $this->plan('visible', 'active', ['cash', 'bank_transfer'], true);
        $this->plan('draft-hidden', 'draft', ['cash']);
        $this->plan('archived-hidden', 'archived', ['cash']);
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/gyms/{$gym->id}/saas-plans", ['X-Gym-ID' => $gym->id])
            ->assertSuccessful()
            ->assertJsonPath('data.0.code', 'visible')
            ->assertJsonCount(2, 'data.0.prices')
            ->assertJsonMissing(['code' => 'draft-hidden'])
            ->assertJsonMissing(['code' => 'archived-hidden']);

        $this->assertSame('active', $active->fresh()->status->value);
    }

    public function test_cash_and_bank_submissions_capture_correct_evidence_and_review_is_idempotent(): void
    {
        [$owner, $gym] = $this->tenant(UserRole::GymOwner);
        [, $price] = $this->plan('manual', 'active', ['cash', 'bank_transfer']);
        Sanctum::actingAs($owner);

        $prepared = $this->postJson("/api/v1/gyms/{$gym->id}/saas-subscription/manual-invoice", [
            'saas_plan_price_id' => $price->id,
            'method' => 'cash',
            'idempotency_key' => 'prepare-cash-invoice-0001',
        ], ['X-Gym-ID' => $gym->id])->assertCreated()
            ->assertJsonPath('data.status', 'due')
            ->assertJsonPath('data.amount_due_minor', 7900)
            ->json('data');
        $this->postJson("/api/v1/gyms/{$gym->id}/saas-subscription/manual-invoice", [
            'saas_plan_price_id' => $price->id,
            'method' => 'cash',
            'idempotency_key' => 'prepare-cash-invoice-0001',
        ], ['X-Gym-ID' => $gym->id])->assertSuccessful()->assertJsonPath('data.id', $prepared['id']);

        $cash = $this->postJson("/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments", [
            'saas_billing_invoice_id' => $prepared['id'],
            'method' => 'cash',
            'payment_date' => today()->toDateString(),
            'idempotency_key' => 'complete-cash-payment-0001',
            'notes' => 'Collected at IronCore billing desk.',
        ], ['X-Gym-ID' => $gym->id])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.reference', null)
            ->assertJsonPath('data.notes', 'Collected at IronCore billing desk.')
            ->assertJsonPath('data.invoice.number', $prepared['number'])
            ->json('data');

        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $review = [
            'decision' => 'approve',
            'reason' => 'Cash received and independently verified.',
        ];
        $url = "/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments/{$cash['id']}/review";
        $this->patchJson($url, $review, ['X-Gym-ID' => $gym->id])->assertSuccessful()->assertJsonPath('data.status', 'paid');
        $this->patchJson($url, $review, ['X-Gym-ID' => $gym->id])->assertSuccessful()->assertJsonPath('data.status', 'paid');
        app(TenantContext::class)->run($gym, function (): void {
            $this->assertSame(1, GymSubscription::query()->count());
            $this->assertSame(1, SaasBillingInvoice::query()->count());
            $this->assertSame(1, AuditLog::query()->where('event', 'saas.subscription_payment.approved')->count());
        });

        [$otherOwner, $otherGym] = $this->tenant(UserRole::GymOwner);
        Sanctum::actingAs($otherOwner);
        $this->post("/api/v1/gyms/{$otherGym->id}/saas-subscription/manual-payments", [
            'saas_plan_price_id' => $price->id,
            'method' => 'bank_transfer',
            'payment_date' => today()->toDateString(),
            'idempotency_key' => 'complete-bank-payment-0001',
            'reference' => 'IRONCORE-BANK-001',
            'notes' => 'Reference confirmed by owner.',
            'receipt' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json', 'X-Gym-ID' => $otherGym->id])
            ->assertCreated()->assertJsonPath('data.has_receipt', true);
    }

    public function test_super_admin_can_publish_one_idempotent_invoice_that_only_its_owner_can_see(): void
    {
        [$ownerA, $gymA] = $this->tenant(UserRole::GymOwner);
        [$ownerB, $gymB] = $this->tenant(UserRole::GymOwner);
        [, $price] = $this->plan('invoice-plan', 'active', ['cash', 'bank_transfer']);
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $payload = [
            'saas_plan_price_id' => $price->id,
            'amount_minor' => $price->amount_minor,
            'currency' => 'GBP',
            'due_date' => today()->addDay()->toDateString(),
            'idempotency_key' => 'individual-saas-invoice-0001',
            'reason' => 'Published for the selected gym billing cycle.',
        ];
        $created = $this->postJson("/api/v1/gyms/{$gymA->id}/saas-billing-invoices", $payload, ['X-Gym-ID' => $gymA->id])
            ->assertCreated()->assertJsonPath('data.status', 'upcoming')->json('data');
        $this->postJson("/api/v1/gyms/{$gymA->id}/saas-billing-invoices", $payload, ['X-Gym-ID' => $gymA->id])
            ->assertSuccessful()->assertJsonPath('data.id', $created['id']);
        app(TenantContext::class)->run($gymA, fn () => $this->assertSame(1, SaasBillingInvoice::query()->count()));

        Sanctum::actingAs($ownerA);
        $this->getJson("/api/v1/gyms/{$gymA->id}/saas-billing-invoices", ['X-Gym-ID' => $gymA->id])
            ->assertSuccessful()->assertJsonFragment(['id' => $created['id']]);
        Sanctum::actingAs($ownerB);
        $this->getJson("/api/v1/gyms/{$gymB->id}/saas-billing-invoices", ['X-Gym-ID' => $gymB->id])
            ->assertSuccessful()->assertJsonMissing(['id' => $created['id']]);
        $this->postJson("/api/v1/gyms/{$gymB->id}/saas-billing-invoices", $payload, ['X-Gym-ID' => $gymB->id])->assertForbidden();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/gyms/{$gymA->id}/saas-billing-invoices/{$created['id']}/void", [
            'reason' => 'Void the unaccepted invoice during lifecycle verification.',
        ], ['X-Gym-ID' => $gymA->id])->assertSuccessful()->assertJsonPath('data.status', 'void');
        Sanctum::actingAs($ownerA);
        $this->getJson("/api/v1/gyms/{$gymA->id}/saas-subscription", ['X-Gym-ID' => $gymA->id])
            ->assertSuccessful()->assertJsonPath('data', null);
        $this->postJson("/api/v1/gyms/{$gymA->id}/saas-subscription/manual-invoice", [
            'saas_plan_price_id' => $price->id,
            'method' => 'cash',
            'idempotency_key' => 'replacement-manual-invoice-0001',
        ], ['X-Gym-ID' => $gymA->id])->assertCreated()->assertJsonPath('data.status', 'due');
    }

    public function test_overdue_reminder_is_daily_timezone_safe_and_platform_queue_is_super_admin_only(): void
    {
        Queue::fake();
        [$owner, $gym] = $this->tenant(UserRole::GymOwner);
        $gym->update(['timezone' => 'Asia/Karachi', 'status' => GymStatus::Active]);
        [, $price] = $this->plan('daily-reminder', 'active', ['cash']);
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $payload = [
            'saas_plan_price_id' => $price->id,
            'amount_minor' => 7900,
            'currency' => 'GBP',
            'due_date' => today()->toDateString(),
            'idempotency_key' => 'daily-reminder-invoice-0001',
            'reason' => 'Create billing lifecycle verification invoice.',
        ];
        $invoice = $this->postJson("/api/v1/gyms/{$gym->id}/saas-billing-invoices", $payload, ['X-Gym-ID' => $gym->id])
            ->assertCreated()->json('data');
        $this->travel(1)->day();
        app(TenantContext::class)->run($gym, fn () => app(AutomatedSaasBillingService::class)->processTenant($gym));
        app(TenantContext::class)->run($gym, fn () => app(AutomatedSaasBillingService::class)->processTenant($gym));
        app(TenantContext::class)->run($gym, fn () => $this->assertSame(1, SaasBillingNotification::query()->where('saas_billing_invoice_id', $invoice['id'])->count()));
        Queue::assertPushed(SendSaasBillingReminder::class, 1);
        $this->travel(1)->day();
        app(TenantContext::class)->run($gym, fn () => app(AutomatedSaasBillingService::class)->processTenant($gym));
        app(TenantContext::class)->run($gym, fn () => $this->assertSame(2, SaasBillingNotification::query()->where('saas_billing_invoice_id', $invoice['id'])->count()));
        Queue::assertPushed(SendSaasBillingReminder::class, 2);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/platform/billing')->assertForbidden();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/platform/billing?payment_status=pending')
            ->assertSuccessful()->assertJsonStructure(['data' => ['payments' => ['data', 'meta']]]);
    }

    public function test_mistaken_manual_payment_approval_is_reversed_once_without_becoming_a_refund(): void
    {
        [$owner, $gym] = $this->tenant(UserRole::GymOwner);
        [, $price] = $this->plan('approval-reversal', 'active', ['cash']);
        Sanctum::actingAs($owner);
        $invoice = $this->postJson("/api/v1/gyms/{$gym->id}/saas-subscription/manual-invoice", [
            'saas_plan_price_id' => $price->id,
            'method' => 'cash',
            'idempotency_key' => 'approval-reversal-invoice-0001',
        ], ['X-Gym-ID' => $gym->id])->assertCreated()->json('data');
        $payment = $this->postJson("/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments", [
            'saas_billing_invoice_id' => $invoice['id'],
            'method' => 'cash',
            'payment_date' => today()->toDateString(),
            'idempotency_key' => 'approval-reversal-payment-0001',
        ], ['X-Gym-ID' => $gym->id])->assertCreated()->json('data');

        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments/{$payment['id']}/review", [
            'decision' => 'approve',
            'reason' => 'Mistaken approval test payment.',
        ], ['X-Gym-ID' => $gym->id])->assertSuccessful()->assertJsonPath('data.status', 'paid');

        $url = "/api/v1/gyms/{$gym->id}/saas-subscription/manual-payments/{$payment['id']}/approval-reversal";
        $this->postJson($url, ['reason' => 'Bank reconciliation confirms that no money was received.'], ['X-Gym-ID' => $gym->id])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'voided')
            ->assertJsonPath('data.approval_reversal.reason', 'Bank reconciliation confirms that no money was received.');
        $this->postJson($url, ['reason' => 'A duplicate reversal attempt must fail.'], ['X-Gym-ID' => $gym->id])->assertUnprocessable();

        app(TenantContext::class)->run($gym, function () use ($gym, $invoice, $payment): void {
            $this->assertDatabaseHas('saas_subscription_payments', [
                'gym_id' => $gym->id, 'id' => $payment['id'], 'status' => 'voided',
                'refunded_amount_minor' => 0,
            ]);
            $this->assertDatabaseHas('saas_billing_invoices', [
                'gym_id' => $gym->id, 'id' => $invoice['id'], 'amount_paid_minor' => 0,
                'amount_remaining_minor' => 7900,
            ]);
            $this->assertSame(1, SaasPaymentApprovalReversal::query()->count());
            $this->assertDatabaseHas('audit_logs', [
                'gym_id' => $gym->id, 'event' => 'saas.subscription_payment.approval_reversed',
            ]);
        });
    }

    public function test_unpaid_manual_invoice_is_voided_and_replaced_without_losing_original_history(): void
    {
        [$owner, $gym] = $this->tenant(UserRole::GymOwner);
        [, $price] = $this->plan('invoice-replacement', 'active', ['cash', 'bank_transfer']);
        Sanctum::actingAs($owner);
        $original = $this->postJson("/api/v1/gyms/{$gym->id}/saas-subscription/manual-invoice", [
            'saas_plan_price_id' => $price->id,
            'method' => 'bank_transfer',
            'idempotency_key' => 'replace-invoice-original-0001',
        ], ['X-Gym-ID' => $gym->id])->assertCreated()->json('data');

        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);
        $payload = [
            'saas_plan_price_id' => $price->id,
            'period_start' => today()->addDay()->toDateString(),
            'due_date' => today()->addDays(3)->toDateString(),
            'idempotency_key' => 'replace-invoice-corrected-0001',
            'reason' => 'Correct the billing dates before the gym submits payment.',
        ];
        $replacement = $this->postJson("/api/v1/gyms/{$gym->id}/saas-billing-invoices/{$original['id']}/replace", $payload, ['X-Gym-ID' => $gym->id])
            ->assertCreated()->assertJsonPath('data.status', 'upcoming')->json('data');
        $this->postJson("/api/v1/gyms/{$gym->id}/saas-billing-invoices/{$original['id']}/replace", $payload, ['X-Gym-ID' => $gym->id])
            ->assertSuccessful()->assertJsonPath('data.id', $replacement['id']);

        app(TenantContext::class)->run($gym, function () use ($gym, $original, $replacement): void {
            $this->assertDatabaseHas('saas_billing_invoices', [
                'gym_id' => $gym->id, 'id' => $original['id'], 'status' => 'void',
            ]);
            $this->assertDatabaseHas('saas_billing_invoices', [
                'gym_id' => $gym->id, 'id' => $replacement['id'], 'status' => 'upcoming',
                'amount_due_minor' => 7900, 'amount_remaining_minor' => 7900,
            ]);
            $this->assertSame(2, SaasBillingInvoice::query()->count());
            $this->assertDatabaseHas('audit_logs', ['gym_id' => $gym->id, 'event' => 'saas.invoice.replaced']);
            $this->assertDatabaseHas('audit_logs', ['gym_id' => $gym->id, 'event' => 'saas.invoice.replacement_created']);
        });
    }

    public function test_only_unused_draft_saas_plans_can_be_permanently_deleted(): void
    {
        [$draft] = $this->plan('unused-draft', 'draft', ['cash']);
        [$published] = $this->plan('published-plan', 'active', ['cash']);
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/platform/saas-plans/{$draft->id}", [
            'reason' => 'Remove an unused duplicate draft before publication.',
        ])->assertNoContent();
        $this->assertDatabaseMissing('saas_plans', ['id' => $draft->id]);
        $this->deleteJson("/api/v1/platform/saas-plans/{$published->id}", [
            'reason' => 'Published plans must be archived instead.',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('saas_plans', ['id' => $published->id, 'status' => 'active']);
    }

    /** @return array{User,Gym} */
    private function tenant(UserRole $role): array
    {
        $user = User::factory()->create();
        $gym = Gym::factory()->create(['base_currency' => Currency::GBP]);
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($user, ['role' => $role->value, 'status' => 'active']));

        return [$user, $gym];
    }

    /** @return array{SaasPlan,SaasPlanPrice} */
    private function plan(string $code, string $status, array $methods, bool $yearly = false): array
    {
        $plan = SaasPlan::query()->create([
            'code' => $code,
            'name' => 'IronCore '.ucwords(str_replace('-', ' ', $code)),
            'status' => $status,
            'feature_limits' => ['members' => 500, 'branches' => 1, 'staff' => 10, 'advanced_reports' => true, 'priority_support' => false],
            'payment_methods' => $methods,
        ]);
        $price = SaasPlanPrice::query()->create([
            'saas_plan_id' => $plan->id,
            'currency' => Currency::GBP,
            'billing_interval' => 'monthly',
            'amount_minor' => 7900,
            'trial_days' => 14,
            'active' => true,
        ]);
        if ($yearly) {
            SaasPlanPrice::query()->create([
                'saas_plan_id' => $plan->id,
                'currency' => Currency::GBP,
                'billing_interval' => 'yearly',
                'amount_minor' => 79000,
                'trial_days' => 14,
                'active' => true,
            ]);
        }

        return [$plan, $price];
    }
}
