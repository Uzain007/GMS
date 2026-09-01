<?php

namespace App\Jobs;

use App\Enums\BillingInterval;
use App\Enums\ImportStatus;
use App\Enums\MembershipStatus;
use App\Models\Gym;
use App\Models\Member;
use App\Models\MemberImport;
use App\Models\MembershipPlan;
use App\Services\AuditService;
use App\Services\MemberCodeService;
use App\Services\MemberImportPreviewService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProcessMemberImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 900;

    public function __construct(public readonly string $gymId, public readonly string $importId) {}

    public function handle(TenantContext $context, MemberImportPreviewService $preview, AuditService $audit): void
    {
        $gym = Gym::query()->findOrFail($this->gymId);
        // Queue workers are long-lived, so every job re-establishes and clears
        // its own tenant/RLS context rather than trusting worker history.
        $context->run($gym, fn () => $this->processImport($preview, $audit));
    }

    public function failed(Throwable $exception): void
    {
        $gym = Gym::query()->find($this->gymId);
        if (! $gym) return;
        app(TenantContext::class)->run($gym, function () use ($exception): void {
            MemberImport::query()->whereKey($this->importId)->update([
                'status' => ImportStatus::Failed->value, 'completed_at' => now(),
                'errors' => [['line' => null, 'category' => 'processing', 'message' => Str::limit($exception->getMessage(), 500)]],
            ]);
        });
    }

    private function processImport(MemberImportPreviewService $preview, AuditService $audit): void
    {
        $import = MemberImport::query()->findOrFail($this->importId);
        if ($import->status !== ImportStatus::Queued || ! $import->confirmed_at) {
            throw new RuntimeException('The member import was not explicitly confirmed.');
        }
        $import->update(['status' => ImportStatus::Processing, 'started_at' => now()]);
        $analysis = $preview->analyse($import, true);
        if ($analysis['summary']['invalid_rows'] > 0) {
            throw new RuntimeException('The confirmed file no longer passes import validation.');
        }

        $success = 0; $failure = 0;
        foreach (array_chunk($analysis['rows'], 500) as $batch) {
            [$inserted, $duplicates] = $this->flush($batch, $import);
            $success += $inserted; $failure += $duplicates;
            $import->update(['processed_rows' => $success + $failure, 'success_rows' => $success, 'failure_rows' => $failure]);
        }

        $import->update([
            'status' => ImportStatus::Completed, 'processed_rows' => $success + $failure,
            'success_rows' => $success, 'failure_rows' => $failure, 'completed_at' => now(),
        ]);
        $audit->record('member_import.completed', $import, $import->requestedBy, after: [
            'total_rows' => $import->total_rows, 'success_rows' => $success, 'failure_rows' => $failure,
        ]);
    }

    /** @param list<array<string,mixed>> $batch @return array{int,int} */
    private function flush(array $batch, MemberImport $import): array
    {
        return DB::transaction(function () use ($batch, $import): array {
            $now = now(); $membershipRequests = []; $memberRows = [];
            foreach ($batch as $row) {
                $planId = $row['_plan_id'] ?? null; unset($row['_plan_id']);
                $row += [
                    'gym_id' => app(TenantContext::class)->id(), 'user_id' => null,
                    'archived_at' => null, 'metadata' => null, 'created_at' => $now, 'updated_at' => $now,
                ];
                $memberRows[] = $row;
                if ($planId) $membershipRequests[(string) $row['id']] = (string) $planId;
            }

            // Codes are allocated under a tenant advisory lock and inserts stay
            // in bounded batches; PostgreSQL RLS still checks every written row.
            $memberRows = app(MemberCodeService::class)->assignToImportRows($memberRows);
            $inserted = DB::table('members')->insertOrIgnore($memberRows);
            $insertedIds = Member::query()->whereIn('id', array_column($memberRows, 'id'))->pluck('id')->all();
            if ($membershipRequests && $insertedIds) {
                $plans = MembershipPlan::query()->whereIn('id', array_values($membershipRequests))->get()->keyBy('id');
                $membershipRows = [];
                foreach ($insertedIds as $memberId) {
                    $planId = $membershipRequests[(string) $memberId] ?? null;
                    $plan = $planId ? $plans->get($planId) : null;
                    if (! $plan) continue;
                    $member = collect($memberRows)->firstWhere('id', $memberId);
                    $startsAt = CarbonImmutable::parse($member['joined_at'] ?: today());
                    $membershipRows[] = [
                        'id' => (string) Str::uuid(), 'gym_id' => app(TenantContext::class)->id(),
                        'member_id' => $memberId, 'plan_id' => $plan->id,
                        'branch_id' => $plan->branch_id ?: $member['home_branch_id'],
                        'created_by' => $import->requested_by, 'status' => MembershipStatus::Active->value,
                        'starts_at' => $startsAt->toDateString(),
                        'ends_at' => $plan->duration_days ? $startsAt->addDays($plan->duration_days)->toDateString() : null,
                        'next_billing_at' => $this->nextBillingAt($plan, $startsAt)?->toDateString(),
                        'price_amount_minor' => $plan->price_amount_minor, 'currency' => $plan->currency->value,
                        'joining_fee_minor' => $plan->joining_fee_minor, 'billing_interval' => $plan->billing_interval->value,
                        'interval_count' => $plan->interval_count, 'auto_renew' => true,
                        'terms_snapshot' => $plan->terms ? json_encode($plan->terms, JSON_THROW_ON_ERROR) : null,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                if ($membershipRows) DB::table('memberships')->insert($membershipRows);
            }
            return [$inserted, count($memberRows) - $inserted];
        });
    }

    private function nextBillingAt(MembershipPlan $plan, CarbonImmutable $startsAt): ?CarbonImmutable
    {
        if ($plan->billing_interval === BillingInterval::OneTime) return null;
        if ($plan->trial_days > 0) return $startsAt->addDays($plan->trial_days);
        return match ($plan->billing_interval) {
            BillingInterval::Weekly => $startsAt->addWeeks($plan->interval_count),
            BillingInterval::Monthly => $startsAt->addMonths($plan->interval_count),
            BillingInterval::Quarterly => $startsAt->addMonths(3 * $plan->interval_count),
            BillingInterval::Yearly => $startsAt->addYears($plan->interval_count),
            BillingInterval::OneTime => null,
        };
    }
}
