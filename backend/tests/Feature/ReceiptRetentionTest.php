<?php

namespace Tests\Feature;

use App\Models\BankTransferReceipt;
use App\Models\Gym;
use App\Models\GymBranch;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\SaasSubscriptionPayment;
use App\Models\User;
use App\Services\ReceiptRetentionService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceiptRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_files_are_deleted_without_changing_metadata_or_crossing_tenants(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $bankIndex = collect(Schema::getIndexes('bank_transfer_receipts'))->firstWhere('name', 'bank_receipts_retention_idx');
        $saasIndex = collect(Schema::getIndexes('saas_subscription_payments'))->firstWhere('name', 'saas_receipts_retention_idx');
        $this->assertSame(['gym_id', 'file_deleted_at', 'created_at'], $bankIndex['columns']);
        $this->assertSame(['gym_id', 'receipt_deleted_at', 'created_at'], $saasIndex['columns']);
        $price = $this->saasPrice();
        $first = $this->tenantRecords('FIRST', $price, now()->subMonthsNoOverflow(13));
        $second = $this->tenantRecords('SECOND', $price, now()->subMonthsNoOverflow(13));
        $recent = $this->memberReceipt($first, now()->subMonthsNoOverflow(11));

        $memberMetadata = $first['receipt']->only([
            'payment_id', 'member_id', 'membership_id', 'bank_reference',
            'storage_disk', 'storage_path', 'original_name', 'mime_type',
            'size_bytes', 'content_sha256', 'reviewed_at', 'review_reason',
        ]);
        $saasMetadata = $first['saas']->only([
            'saas_plan_price_id', 'method', 'status', 'amount_minor', 'currency',
            'idempotency_key', 'reference', 'payment_date', 'receipt_disk',
            'receipt_path', 'receipt_original_name', 'receipt_mime_type',
            'receipt_size_bytes', 'receipt_sha256', 'reviewed_at', 'review_reason',
        ]);

        $result = app(ReceiptRetentionService::class)->runForGym($first['gym']);
        $this->assertSame(['member_deleted' => 1, 'saas_deleted' => 1, 'failed' => 0], $result);
        Storage::disk('local')->assertMissing($first['receipt']->storage_path);
        Storage::disk('local')->assertMissing($first['saas']->receipt_path);
        Storage::disk('local')->assertExists($recent->storage_path);
        Storage::disk('local')->assertExists($second['receipt']->storage_path);
        Storage::disk('local')->assertExists($second['saas']->receipt_path);

        app(TenantContext::class)->run($first['gym'], function () use ($first, $recent, $memberMetadata, $saasMetadata): void {
            $receipt = BankTransferReceipt::query()->findOrFail($first['receipt']->id);
            $saas = SaasSubscriptionPayment::query()->findOrFail($first['saas']->id);
            $this->assertNotNull($receipt->file_deleted_at);
            $this->assertNotNull($saas->receipt_deleted_at);
            $this->assertNull(BankTransferReceipt::query()->findOrFail($recent->id)->file_deleted_at);
            $this->assertSame($memberMetadata, $receipt->only(array_keys($memberMetadata)));
            $this->assertEquals($saasMetadata, $saas->only(array_keys($saasMetadata)));
            $this->assertDatabaseHas('payments', [
                'id' => $first['payment']->id,
                'amount_minor' => 6500,
                'currency' => 'GBP',
                'status' => 'paid',
            ]);
        });
        app(TenantContext::class)->run($second['gym'], function () use ($second): void {
            $this->assertNull(BankTransferReceipt::query()->findOrFail($second['receipt']->id)->file_deleted_at);
            $this->assertNull(SaasSubscriptionPayment::query()->findOrFail($second['saas']->id)->receipt_deleted_at);
        });
    }

    /** @return array<string, mixed> */
    private function tenantRecords(string $suffix, SaasPlanPrice $price, $createdAt): array
    {
        $user = User::factory()->create();
        $gym = Gym::factory()->create(['name' => "Retention {$suffix}"]);

        return app(TenantContext::class)->run($gym, function () use ($suffix, $price, $createdAt, $user, $gym): array {
            $branch = GymBranch::query()->create([
                'name' => "Branch {$suffix}", 'code' => "BR-{$suffix}", 'status' => 'active', 'is_primary' => true,
            ]);
            $member = Member::query()->create([
                'home_branch_id' => $branch->id, 'member_number' => "MEM-{$suffix}",
                'first_name' => 'Receipt', 'last_name' => $suffix, 'status' => 'active',
            ]);
            $plan = MembershipPlan::query()->create([
                'branch_id' => $branch->id, 'name' => "Plan {$suffix}", 'code' => "PLAN-{$suffix}",
                'billing_interval' => 'monthly', 'price_amount_minor' => 6500,
                'currency' => 'GBP', 'status' => 'active',
            ]);
            $membership = Membership::query()->create([
                'member_id' => $member->id, 'plan_id' => $plan->id, 'branch_id' => $branch->id,
                'created_by' => $user->id, 'status' => 'active', 'starts_at' => today(),
                'price_amount_minor' => 6500, 'currency' => 'GBP', 'billing_interval' => 'monthly',
            ]);
            $payment = Payment::query()->create([
                'member_id' => $member->id, 'membership_id' => $membership->id,
                'branch_id' => $branch->id, 'recorded_by' => $user->id,
                'receipt_number' => "PAY-{$suffix}", 'provider' => 'manual',
                'method' => 'bank_transfer', 'status' => 'paid', 'amount_minor' => 6500,
                'currency' => 'GBP', 'idempotency_key' => "retention-{$suffix}",
            ]);
            $receipt = $this->createReceipt($payment, $member, $membership, $user, "member/{$suffix}.pdf", $createdAt);
            $saas = SaasSubscriptionPayment::query()->create([
                'saas_plan_price_id' => $price->id, 'submitted_by' => $user->id,
                'method' => 'bank_transfer', 'status' => 'paid', 'amount_minor' => 7900,
                'currency' => 'GBP', 'idempotency_key' => "saas-retention-{$suffix}",
                'reference' => "SAAS-{$suffix}", 'payment_date' => today(),
                'receipt_disk' => 'local', 'receipt_path' => "saas/{$suffix}.pdf",
                'receipt_original_name' => 'receipt.pdf', 'receipt_mime_type' => 'application/pdf',
                'receipt_size_bytes' => 8, 'receipt_sha256' => hash('sha256', "saas-{$suffix}"),
            ]);
            $saas->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
            Storage::disk('local')->put($saas->receipt_path, "saas-{$suffix}");

            return compact('gym', 'user', 'branch', 'member', 'membership', 'payment', 'receipt', 'saas');
        });
    }

    private function memberReceipt(array $records, $createdAt): BankTransferReceipt
    {
        return app(TenantContext::class)->run($records['gym'], function () use ($records, $createdAt): BankTransferReceipt {
            $payment = Payment::query()->create([
                'member_id' => $records['member']->id, 'membership_id' => $records['membership']->id,
                'branch_id' => $records['branch']->id, 'recorded_by' => $records['user']->id,
                'receipt_number' => 'PAY-RECENT', 'provider' => 'manual', 'method' => 'bank_transfer',
                'status' => 'paid', 'amount_minor' => 6500, 'currency' => 'GBP',
                'idempotency_key' => 'retention-recent',
            ]);

            return $this->createReceipt(
                $payment, $records['member'], $records['membership'], $records['user'], 'member/recent.pdf', $createdAt,
            );
        });
    }

    private function createReceipt(
        Payment $payment,
        Member $member,
        Membership $membership,
        User $user,
        string $path,
        $createdAt,
    ): BankTransferReceipt {
        $contents = "receipt-{$payment->id}";
        $receipt = BankTransferReceipt::query()->create([
            'payment_id' => $payment->id, 'member_id' => $member->id,
            'membership_id' => $membership->id, 'submitted_by' => $user->id,
            'bank_reference' => 'PRESERVE-ME', 'storage_disk' => 'local', 'storage_path' => $path,
            'original_name' => 'receipt.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents), 'content_sha256' => hash('sha256', $contents),
        ]);
        $receipt->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        Storage::disk('local')->put($path, $contents);

        return $receipt;
    }

    private function saasPrice(): SaasPlanPrice
    {
        $plan = SaasPlan::query()->create([
            'code' => 'retention', 'name' => 'Retention', 'status' => 'active',
            'feature_limits' => [], 'payment_methods' => ['bank_transfer'], 'provider' => 'manual',
        ]);

        return $plan->prices()->create([
            'currency' => 'GBP', 'billing_interval' => 'monthly',
            'amount_minor' => 7900, 'active' => true, 'provider' => 'manual',
        ]);
    }
}
