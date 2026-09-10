<?php

namespace App\Services;

use App\Enums\GymStatus;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\GymBranch;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\SaasBillingInvoice;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class PlatformInsightsService
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array<string,mixed> */
    public function billing(array $filters): array
    {
        $gyms = $this->gyms($filters['gym_id'] ?? null);
        $subscriptions = collect();
        $invoices = collect();

        foreach ($gyms as $gym) {
            $this->tenant->run($gym, function () use ($gym, $filters, $subscriptions, $invoices): void {
                $subscription = GymSubscription::query()->latest()->first();
                $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
                $gymMatches = $search === '' || str_contains(mb_strtolower($gym->name), $search);
                $subscriptionMatches = $subscription
                    && (empty($filters['plan_id']) || $subscription->saas_plan_id === $filters['plan_id'])
                    && (empty($filters['currency']) || $subscription->currency->value === $filters['currency'])
                    && (empty($filters['status']) || $subscription->status->value === $filters['status'])
                    && ($gymMatches || str_contains(mb_strtolower($subscription->plan_name_snapshot), $search));
                if ($subscriptionMatches) {
                    $subscriptions->push($this->subscriptionRow($gym, $subscription));
                }
                $query = SaasBillingInvoice::query()->with('subscription')->orderByDesc('due_at')->orderByDesc('created_at');
                if ($search !== '' && ! $gymMatches) {
                    $like = '%'.addcslashes($search, '%_\\').'%';
                    $query->where(fn ($q) => $q->whereRaw('LOWER(number) LIKE ?', [$like])
                        ->orWhereHas('subscription', fn ($subscriptionQuery) => $subscriptionQuery->whereRaw('LOWER(plan_name_snapshot) LIKE ?', [$like])));
                }
                if (! empty($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
                if (! empty($filters['currency'])) {
                    $query->where('currency', $filters['currency']);
                }
                if (! empty($filters['plan_id'])) {
                    $query->whereHas('subscription', fn ($q) => $q->where('saas_plan_id', $filters['plan_id']));
                }
                if (! empty($filters['from'])) {
                    $query->whereDate('due_at', '>=', $filters['from']);
                }
                if (! empty($filters['to'])) {
                    $query->whereDate('due_at', '<=', $filters['to']);
                }
                foreach ($query->get() as $invoice) {
                    $invoices->push($this->invoiceRow($gym, $invoice));
                }
            });
        }

        $now = now();
        $paidGymIds = $invoices->where('status', 'paid')->pluck('gym_id')->unique();
        $money = static fn (Collection $rows, string $field): array => $rows
            ->groupBy('currency')->map(fn (Collection $group) => (int) $group->sum($field))->all();
        $mrr = $subscriptions->whereIn('status', ['active', 'past_due'])->groupBy('currency')->map(
            fn (Collection $group): int => (int) $group->sum(fn (array $row): int => $row['billing_interval'] === 'yearly'
                ? intdiv($row['amount_minor'], 12)
                : $row['amount_minor'])
        )->all();

        return [
            'metrics' => [
                'total_gyms' => $gyms->count(),
                'active_gyms' => $gyms->where('status', GymStatus::Active->value)->count(),
                'trial_gyms' => $gyms->where('status', GymStatus::Trial->value)->count(),
                'paid_gyms' => $paidGymIds->count(),
                'unpaid_gyms' => $subscriptions->whereIn('status', ['incomplete', 'unpaid'])->count(),
                'past_due_gyms' => $subscriptions->where('status', 'past_due')->count(),
                'billing_suspended_gyms' => $subscriptions->whereNotNull('billing_restricted_at')->count(),
                'cancelled_archived_gyms' => $gyms->where('status', GymStatus::Cancelled->value)->count(),
                'mrr_by_currency' => $mrr,
                'revenue_this_month_by_currency' => $money($invoices->where('status', 'paid')->filter(fn (array $row) => $row['paid_at'] && CarbonImmutable::parse($row['paid_at'])->isSameMonth($now)), 'amount_paid_minor'),
                'outstanding_by_currency' => $money($invoices->whereNotIn('status', ['paid', 'void', 'cancelled']), 'amount_remaining_minor'),
                'overdue_by_currency' => $money($invoices->where('status', 'past_due'), 'amount_remaining_minor'),
                'upcoming_renewals' => $subscriptions->filter(fn (array $row) => $row['next_billing_at'] && CarbonImmutable::parse($row['next_billing_at'])->between($now, $now->copy()->addDays(30)))->count(),
                'trial_conversions' => $gyms->where('status', GymStatus::Active->value)->whereIn('id', $paidGymIds)->count(),
            ],
            'subscriptions' => $subscriptions->values()->all(),
            'invoices' => $invoices->values()->all(),
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function members(array $filters, ?int $offset = null, ?int $limit = null): array
    {
        $rows = collect();
        $total = 0;
        $remainingOffset = max(0, $offset ?? 0);
        $remainingLimit = $limit;
        foreach ($this->gyms($filters['gym_id'] ?? null) as $gym) {
            $this->tenant->run($gym, function () use ($gym, $filters, $rows, &$total, &$remainingOffset, &$remainingLimit, $limit): void {
                $base = $this->memberQuery($filters);
                $gymCount = (clone $base)->count();
                $total += $gymCount;
                if ($remainingLimit !== null && ($remainingLimit < 1 || $remainingOffset >= $gymCount)) {
                    $remainingOffset = max(0, $remainingOffset - $gymCount);
                    return;
                }

                $query = $base->with([
                    'homeBranch:id,gym_id,name',
                    'memberships' => fn ($q) => $q->with('plan:id,name')->latest('starts_at')->limit(1),
                ])->orderBy('last_name')->orderBy('first_name')->orderBy('id');
                if ($limit !== null) {
                    $query->offset($remainingOffset)->limit($remainingLimit);
                }
                $members = $query->get();
                foreach ($members as $member) {
                    $membership = $member->memberships->first();
                    $rows->push([
                        'id' => $member->id,
                        'gym_id' => $gym->id,
                        'gym_name' => $gym->name,
                        'name' => trim($member->first_name.' '.$member->last_name),
                        'email' => $member->email,
                        'phone' => $member->phone,
                        'member_code' => $member->member_code,
                        'status' => $member->status->value,
                        'branch' => $member->homeBranch ? ['id' => $member->homeBranch->id, 'name' => $member->homeBranch->name] : null,
                        'membership_status' => $membership?->status?->value,
                        'plan' => $membership?->plan ? ['id' => $membership->plan->id, 'name' => $membership->plan->name] : null,
                        'joined_at' => $member->joined_at?->toDateString(),
                    ]);
                }
                if ($remainingLimit !== null) {
                    // Consume only rows fetched for this tenant; the aggregate collection
                    // already contains earlier gyms from the same platform page.
                    $remainingLimit = max(0, $remainingLimit - $members->count());
                    $remainingOffset = 0;
                }
            });
        }
        return ['rows' => $rows->values()->all(), 'total' => $total];
    }

    private function memberQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = Member::query();
        if (! empty($filters['search'])) {
            $search = mb_strtolower(trim($filters['search']));
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(first_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                ->orWhere('phone', 'like', $like)
                ->orWhere('member_code', '=', $search));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['branch_id'])) {
            $query->where('home_branch_id', $filters['branch_id']);
        }
        if (! empty($filters['membership_status'])) {
            $query->whereHas('memberships', fn ($q) => $q->where('status', $filters['membership_status']));
        }
        if (! empty($filters['plan_id'])) {
            $query->whereHas('memberships', fn ($q) => $q->where('plan_id', $filters['plan_id']));
        }
        return $query;
    }

    /** @return array{plans:list<array{id:string,name:string,gym_id:string,gym_name:string}>,branches:list<array{id:string,name:string,gym_id:string,gym_name:string}>} */
    public function memberFacets(?string $gymId): array
    {
        $plans = collect();
        $branches = collect();
        foreach ($this->gyms($gymId) as $gym) {
            $this->tenant->run($gym, function () use ($gym, $plans, $branches): void {
                MembershipPlan::query()->orderBy('name')->get(['id', 'name'])->each(
                    fn (MembershipPlan $plan) => $plans->push(['id' => $plan->id, 'name' => $plan->name, 'gym_id' => $gym->id, 'gym_name' => $gym->name]),
                );
                GymBranch::query()->orderBy('name')->get(['id', 'name'])->each(
                    fn (GymBranch $branch) => $branches->push(['id' => $branch->id, 'name' => $branch->name, 'gym_id' => $gym->id, 'gym_name' => $gym->name]),
                );
            });
        }
        return ['plans' => $plans->values()->all(), 'branches' => $branches->values()->all()];
    }

    /** @return array<string,mixed> */
    public function analytics(array $filters): array
    {
        $from = CarbonImmutable::parse($filters['from'] ?? now()->subMonths(11)->startOfMonth())->startOfDay();
        $to = CarbonImmutable::parse($filters['to'] ?? now()->endOfMonth())->endOfDay();
        $months = collect();
        for ($cursor = $from->startOfMonth(); $cursor->lte($to); $cursor = $cursor->addMonth()) {
            $months->put($cursor->format('Y-m'), [
                'month' => $cursor->format('Y-m'), 'new_gyms' => 0, 'total_gyms' => 0,
                'active_gyms' => 0, 'members_added' => 0, 'revenue_by_currency' => [],
            ]);
        }

        $gyms = Gym::query()->whereDate('created_at', '<=', $to)->orderBy('created_at')->get();
        foreach ($months as $key => $row) {
            $end = CarbonImmutable::createFromFormat('Y-m-d', $key.'-01')->endOfMonth();
            $row['new_gyms'] = $gyms->filter(fn (Gym $gym) => $gym->created_at?->format('Y-m') === $key)->count();
            $row['total_gyms'] = $gyms->filter(fn (Gym $gym) => $gym->created_at?->lte($end))->count();
            $row['active_gyms'] = $gyms->filter(fn (Gym $gym) => $gym->created_at?->lte($end) && $gym->status === GymStatus::Active)->count();
            $months->put($key, $row);
        }

        $planDistribution = collect();
        $revenueByPlan = collect();
        $invoiceStatuses = collect();
        foreach ($gyms as $gym) {
            $this->tenant->run($gym, function () use ($from, $to, $months, $planDistribution, $revenueByPlan, $invoiceStatuses): void {
                Member::query()->whereBetween('created_at', [$from, $to])->select('created_at')->each(function (Member $member) use ($months): void {
                    $key = $member->created_at->format('Y-m');
                    if ($months->has($key)) {
                        $row = $months->get($key); $row['members_added']++; $months->put($key, $row);
                    }
                });
                SaasBillingInvoice::query()->with('subscription')->whereBetween('created_at', [$from, $to])->each(function (SaasBillingInvoice $invoice) use ($invoiceStatuses): void {
                    $invoiceStatuses[$invoice->status->value] = ($invoiceStatuses[$invoice->status->value] ?? 0) + 1;
                });
                SaasBillingInvoice::query()->with('subscription')->where('status', 'paid')->whereBetween('paid_at', [$from, $to])->each(function (SaasBillingInvoice $invoice) use ($months, $revenueByPlan): void {
                    $key = $invoice->paid_at->format('Y-m');
                    if ($months->has($key)) {
                        $row = $months->get($key);
                        $currency = $invoice->currency->value;
                        $row['revenue_by_currency'][$currency] = ($row['revenue_by_currency'][$currency] ?? 0) + $invoice->amount_paid_minor;
                        $months->put($key, $row);
                    }
                    $plan = $invoice->subscription?->plan_name_snapshot ?? 'Unknown plan';
                    $planRow = $revenueByPlan->get($plan, []);
                    $planRow[$invoice->currency->value] = ($planRow[$invoice->currency->value] ?? 0) + $invoice->amount_paid_minor;
                    $revenueByPlan->put($plan, $planRow);
                });
                $subscription = GymSubscription::query()->latest()->first();
                if ($subscription) {
                    $planDistribution[$subscription->plan_name_snapshot] = ($planDistribution[$subscription->plan_name_snapshot] ?? 0) + 1;
                }
            });
        }

        $billing = $this->billing([]);
        $timeline = $months->values();
        $current = $timeline->last() ?? ['new_gyms' => 0, 'members_added' => 0, 'revenue_by_currency' => []];
        $previous = $timeline->count() > 1 ? $timeline->get($timeline->count() - 2) : ['new_gyms' => 0, 'members_added' => 0, 'revenue_by_currency' => []];
        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'timeline' => $timeline->all(),
            'plan_distribution' => $planDistribution->map(fn ($count, $plan) => ['plan' => $plan, 'gyms' => $count])->values()->all(),
            'revenue_by_plan' => $revenueByPlan->map(fn ($currencies, $plan) => ['plan' => $plan, 'revenue_by_currency' => $currencies])->values()->all(),
            'invoice_statuses' => $invoiceStatuses->map(fn ($count, $status) => ['status' => $status, 'count' => $count])->values()->all(),
            'comparison' => ['current_month' => $current, 'previous_month' => $previous],
            'billing_metrics' => $billing['metrics'],
        ];
    }

    private function gyms(?string $gymId): Collection
    {
        return Gym::query()->when($gymId, fn ($query) => $query->whereKey($gymId))->orderBy('name')->get();
    }

    /** @return array<string,mixed> */
    private function subscriptionRow(Gym $gym, GymSubscription $subscription): array
    {
        return [
            'id' => $subscription->id, 'gym_id' => $gym->id, 'gym_name' => $gym->name,
            'plan_id' => $subscription->saas_plan_id, 'plan_name' => $subscription->plan_name_snapshot,
            'status' => $subscription->status->value, 'currency' => $subscription->currency->value,
            'amount_minor' => $subscription->amount_minor, 'billing_interval' => $subscription->billing_interval,
            'next_billing_at' => $subscription->next_billing_at?->toIso8601String(),
            'grace_period_days' => $subscription->grace_period_days,
            'billing_restricted_at' => $subscription->billing_restricted_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function invoiceRow(Gym $gym, SaasBillingInvoice $invoice): array
    {
        return [
            'id' => $invoice->id, 'gym_id' => $gym->id, 'gym_name' => $gym->name,
            'subscription_id' => $invoice->gym_subscription_id,
            'plan_name' => $invoice->subscription?->plan_name_snapshot,
            'number' => $invoice->number, 'status' => $invoice->status->value,
            'currency' => $invoice->currency->value, 'amount_due_minor' => $invoice->amount_due_minor,
            'amount_paid_minor' => $invoice->amount_paid_minor, 'amount_remaining_minor' => $invoice->amount_remaining_minor,
            'period_start' => $invoice->period_start?->toIso8601String(), 'period_end' => $invoice->period_end?->toIso8601String(),
            'due_at' => $invoice->due_at?->toIso8601String(), 'grace_ends_at' => $invoice->grace_ends_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
        ];
    }
}
