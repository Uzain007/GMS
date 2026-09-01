<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Jobs\ProcessMemberImport;
use App\Models\Gym;
use App\Models\GymBranch;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MemberImportPreviewService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;
use Tests\TestCase;

class MemberRosterWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.default' => 'local']);
    }

    public function test_invalid_csv_preview_reports_categories_and_cannot_be_confirmed(): void
    {
        [$owner, $gym, $branch, $plan] = $this->tenant('PREVIEW');
        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $csv = implode("\n", [
            'first_name,last_name,email,phone,member_number,branch_code,membership_plan_code,status,date_of_birth,joined_at',
            "Valid,Member,valid@example.test,+44 7700 900111,VALID-1,{$branch->code},{$plan->code},active,1990-01-01,2026-08-28",
            'Missing,,missing@example.test,+44 7700 900112,MISSING-1,,,lead,,',
            'Bad,Email,not-an-email,+44 7700 900113,BAD-EMAIL,,,lead,,',
            'Bad,Phone,phone@example.test,abc,BAD-PHONE,,,lead,,',
            'Bad,Branch,branch@example.test,+44 7700 900114,BAD-BRANCH,UNKNOWN,,lead,,',
            'Bad,Plan,plan@example.test,+44 7700 900115,BAD-PLAN,,UNKNOWN,lead,,',
            'Duplicate,Member,valid@example.test,+44 7700 900116,DUPLICATE-1,,,lead,,',
        ]);

        $preview = $this->post('/api/v1/gyms/'.$gym->id.'/member-imports', [
            'file' => UploadedFile::fake()->createWithContent('members.csv', $csv),
        ], $headers)->assertCreated()->assertJsonPath('data.status', 'previewed')
            ->assertJsonPath('data.preview_summary.total_rows', 7)
            ->assertJsonPath('data.preview_summary.valid_rows', 1)
            ->assertJsonPath('data.preview_summary.invalid_rows', 6)
            ->assertJsonPath('data.preview_summary.duplicate_rows', 1)
            ->assertJsonPath('data.preview_summary.missing_required_fields', 1)
            ->assertJsonPath('data.preview_summary.invalid_email', 1)
            ->assertJsonPath('data.preview_summary.invalid_phone', 1)
            ->assertJsonPath('data.preview_summary.invalid_branch_references', 1)
            ->assertJsonPath('data.preview_summary.invalid_membership_references', 1);

        $this->postJson('/api/v1/gyms/'.$gym->id.'/member-imports/'.$preview->json('data.id').'/confirm', [], $headers)
            ->assertUnprocessable();
        app(TenantContext::class)->run($gym, fn () => $this->assertSame(0, Member::query()->count()));
    }

    public function test_xlsx_preview_confirm_creates_member_and_membership_then_tenant_export_stays_isolated(): void
    {
        Queue::fake();
        [$owner, $gym, $branch, $plan] = $this->tenant('XLSX');
        [, $otherGym] = $this->tenant('OTHER');
        app(TenantContext::class)->run($otherGym, fn () => Member::query()->create([
            'member_number' => 'OTHER-1', 'first_name' => 'Other', 'last_name' => 'Tenant',
            'email' => 'other@example.test', 'phone' => '+44 7700 900999', 'status' => 'active',
        ]));
        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $temporary = tempnam(sys_get_temp_dir(), 'ironcore-xlsx-');
        SimpleXLSXGen::fromArray([
            MemberImportPreviewService::HEADERS,
            ['Excel', 'Member', 'excel@example.test', '+92 300 1234567', 'EXCEL-1', $branch->code, $plan->code, 'active', '1992-04-03', '2026-08-28'],
        ])->saveAs($temporary);

        $preview = $this->post('/api/v1/gyms/'.$gym->id.'/member-imports', [
            'file' => new UploadedFile($temporary, 'members.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], $headers)->assertCreated()->assertJsonPath('data.preview_summary.valid_rows', 1);
        $importId = $preview->json('data.id');
        $this->postJson('/api/v1/gyms/'.$gym->id.'/member-imports/'.$importId.'/confirm', [], $headers)->assertAccepted();
        Queue::assertPushed(ProcessMemberImport::class, fn ($job) => $job->gymId === $gym->id && $job->importId === $importId);

        (new ProcessMemberImport($gym->id, $importId))->handle(app(TenantContext::class), app(MemberImportPreviewService::class), app(AuditService::class));
        app(TenantContext::class)->run($gym, function (): void {
            $member = Member::query()->where('email', 'excel@example.test')->firstOrFail();
            $this->assertMatchesRegularExpression('/^\d{6}$/', $member->member_code);
            $this->assertSame(1, Membership::query()->where('member_id', $member->id)->count());
        });

        $csv = $this->get('/api/v1/gyms/'.$gym->id.'/members-export', $headers)->assertOk()->streamedContent();
        $this->assertStringContainsString('excel@example.test', $csv);
        $this->assertStringNotContainsString('other@example.test', $csv);
    }

    public function test_templates_have_exact_headers_and_platform_export_requires_super_admin(): void
    {
        [$owner, $gym] = $this->tenant('TEMPLATE');
        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];
        $csv = $this->get('/api/v1/gyms/'.$gym->id.'/members-import-template?format=csv', $headers)->assertOk()->streamedContent();
        $this->assertStringContainsString(implode(',', MemberImportPreviewService::HEADERS), $csv);
        $this->assertCount(2, array_filter(preg_split('/\R/', trim($csv))));

        $xlsxBytes = $this->get('/api/v1/gyms/'.$gym->id.'/members-import-template?format=xlsx', $headers)->assertOk()->getContent();
        $book = SimpleXLSX::parseData($xlsxBytes);
        $this->assertNotFalse($book);
        $this->assertSame(MemberImportPreviewService::HEADERS, $book->rows()[0]);
        $this->assertCount(2, $book->rows());

        $this->get('/api/v1/platform/members/export')->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['platform_role' => UserRole::SuperAdmin]));
        $global = $this->get('/api/v1/platform/members/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('gym_id,gym_name', $global);
    }

    /** @return array{User,Gym,GymBranch,MembershipPlan} */
    private function tenant(string $suffix): array
    {
        $owner = User::factory()->create();
        $gym = Gym::factory()->create(['name' => "Roster {$suffix}", 'base_currency' => Currency::GBP]);
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($owner, ['role' => UserRole::GymOwner->value, 'status' => 'active']));
        [$branch, $plan] = app(TenantContext::class)->run($gym, function () use ($suffix): array {
            $branch = GymBranch::query()->create(['name' => "Branch {$suffix}", 'code' => "BR-{$suffix}", 'status' => 'active']);
            $plan = MembershipPlan::query()->create([
                'branch_id' => $branch->id, 'name' => "Plan {$suffix}", 'code' => "PLAN-{$suffix}",
                'billing_interval' => 'monthly', 'interval_count' => 1, 'price_amount_minor' => 5000,
                'currency' => Currency::GBP, 'joining_fee_minor' => 0, 'trial_days' => 0, 'status' => 'active',
            ]);
            return [$branch, $plan];
        });
        return [$owner, $gym, $branch, $plan];
    }
}
