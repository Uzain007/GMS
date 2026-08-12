<?php

namespace App\Services;

use App\Enums\ClassSessionStatus;
use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\MemberStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportService
{
    private const CACHE_SECONDS = 60;

    private const REPORT_VERSION = 'v1';

    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string, mixed> */
    public function overview(string $fromDate, string $toDate, Currency $currency): array
    {
        $gym = $this->context->gym();
        $timezone = $gym->timezone;
        $fromLocal = CarbonImmutable::parse($fromDate, $timezone)->startOfDay();
        $toLocal = CarbonImmutable::parse($toDate, $timezone)->startOfDay();
        $days = $fromLocal->diffInDays($toLocal) + 1;

        // Half-open UTC windows avoid end-of-day precision bugs while keeping
        // the selected dates meaningful in each gym's own timezone.
        $from = $fromLocal->utc();
        $toExclusive = $toLocal->addDay()->utc();
        $previousFrom = $fromLocal->subDays($days)->utc();
        $previousToExclusive = $from;

        $filterHash = hash('sha256', implode('|', [
            self::REPORT_VERSION, $fromDate, $toDate, $currency->value, $timezone,
        ]));
        $cacheKey = "ironcore:gym:{$gym->id}:reports:overview:{$filterHash}";

        // Cache population happens only after tenant + role middleware, and the
        // immutable gym identifier is part of every key to prevent cache bleed.
        return Cache::remember($cacheKey, now()->addSeconds(self::CACHE_SECONDS), fn (): array => $this->build(
            (string) $gym->id,
            $fromDate,
            $toDate,
            $timezone,
            $currency,
            $from,
            $toExclusive,
            $previousFrom,
            $previousToExclusive,
            $days,
        ));
    }

    /** @return array<string, mixed> */
    private function build(
        string $gymId,
        string $fromDate,
        string $toDate,
        string $timezone,
        Currency $currency,
        CarbonImmutable $from,
        CarbonImmutable $toExclusive,
        CarbonImmutable $previousFrom,
        CarbonImmutable $previousToExclusive,
        int $days,
    ): array {
        $current = $this->periodSummary($gymId, $currency, $from, $toExclusive);
        $previous = $this->periodSummary($gymId, $currency, $previousFrom, $previousToExclusive);

        $activeMembers = Member::query()
            ->where('gym_id', $gymId)
            ->where('status', MemberStatus::Active->value)
            ->count();
        $outstandingMinor = (int) Invoice::query()
            ->where('gym_id', $gymId)
            ->where('currency', $currency->value)
            ->where('status', InvoiceStatus::Open->value)
            ->sum('due_amount_minor');

        return [
            'period' => [
                'from' => $fromDate,
                'to' => $toDate,
                'days' => $days,
                'timezone' => $timezone,
                'currency' => $currency->value,
            ],
            'summary' => [
                'active_members' => $activeMembers,
                'new_members' => $current['new_members'],
                'new_members_change_bps' => $this->changeBasisPoints($current['new_members'], $previous['new_members']),
                'net_revenue_minor' => $current['net_revenue_minor'],
                'net_revenue_change_bps' => $this->changeBasisPoints($current['net_revenue_minor'], $previous['net_revenue_minor']),
                'outstanding_minor' => $outstandingMinor,
                'attendance_visits' => $current['attendance_visits'],
                'attendance_change_bps' => $this->changeBasisPoints($current['attendance_visits'], $previous['attendance_visits']),
                'class_utilization_bps' => $current['class_utilization_bps'],
                'class_utilization_change_bps' => $this->changeBasisPoints($current['class_utilization_bps'], $previous['class_utilization_bps']),
                'membership_cancellations' => $current['membership_cancellations'],
            ],
            'daily' => $this->dailySeries($gymId, $currency, $from, $toExclusive, $fromDate, $days, $timezone),
            'member_status' => $this->memberStatusDistribution($gymId),
            'payment_methods' => $this->paymentMethodMix($gymId, $currency, $from, $toExclusive),
            'class_performance' => [
                'sessions' => $current['class_sessions'],
                'capacity' => $current['class_capacity'],
                'booked' => $current['class_booked'],
                'attended' => $current['class_attended'],
                'waitlisted' => $current['class_waitlisted'],
                'utilization_bps' => $current['class_utilization_bps'],
            ],
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'cache_ttl_seconds' => self::CACHE_SECONDS,
                'report_version' => self::REPORT_VERSION,
            ],
        ];
    }

    /** @return array<string, int> */
    private function periodSummary(string $gymId, Currency $currency, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $settled = [
            PaymentStatus::Succeeded->value,
            PaymentStatus::PartiallyRefunded->value,
            PaymentStatus::Refunded->value,
        ];
        $newMembers = Member::query()
            ->where('gym_id', $gymId)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->count();
        $grossMinor = (int) Payment::query()
            ->where('gym_id', $gymId)
            ->where('currency', $currency->value)
            ->whereIn('status', $settled)
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $toExclusive)
            ->sum('amount_minor');
        $refundedMinor = (int) PaymentRefund::query()
            ->where('gym_id', $gymId)
            ->where('currency', $currency->value)
            ->where('status', RefundStatus::Succeeded->value)
            ->where('refunded_at', '>=', $from)
            ->where('refunded_at', '<', $toExclusive)
            ->sum('amount_minor');
        $visits = AttendanceRecord::query()
            ->where('gym_id', $gymId)
            ->where('checked_in_at', '>=', $from)
            ->where('checked_in_at', '<', $toExclusive)
            ->count();
        $cancellations = Membership::query()
            ->where('gym_id', $gymId)
            ->where('cancelled_at', '>=', $from)
            ->where('cancelled_at', '<', $toExclusive)
            ->count();

        $classes = ClassSession::query()
            ->where('gym_id', $gymId)
            ->where('status', '!=', ClassSessionStatus::Cancelled->value)
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $toExclusive)
            ->toBase()
            ->selectRaw('COUNT(*) AS sessions')
            ->selectRaw('COALESCE(SUM(capacity), 0) AS capacity')
            ->selectRaw('COALESCE(SUM(booked_count), 0) AS booked')
            ->selectRaw('COALESCE(SUM(attended_count), 0) AS attended')
            ->selectRaw('COALESCE(SUM(waitlist_count), 0) AS waitlisted')
            ->first();
        $capacity = (int) ($classes?->capacity ?? 0);
        $attended = (int) ($classes?->attended ?? 0);

        return [
            'new_members' => $newMembers,
            'gross_revenue_minor' => $grossMinor,
            'refunded_minor' => $refundedMinor,
            'net_revenue_minor' => $grossMinor - $refundedMinor,
            'attendance_visits' => $visits,
            'membership_cancellations' => $cancellations,
            'class_sessions' => (int) ($classes?->sessions ?? 0),
            'class_capacity' => $capacity,
            'class_booked' => (int) ($classes?->booked ?? 0),
            'class_attended' => $attended,
            'class_waitlisted' => (int) ($classes?->waitlisted ?? 0),
            'class_utilization_bps' => $capacity > 0 ? (int) round(($attended * 10000) / $capacity) : 0,
        ];
    }

    /** @return list<array<string, int|string>> */
    private function dailySeries(
        string $gymId,
        Currency $currency,
        CarbonImmutable $from,
        CarbonImmutable $toExclusive,
        string $fromDate,
        int $days,
        string $timezone,
    ): array {
        $settled = [PaymentStatus::Succeeded->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value];
        $members = $this->dailyAggregate(
            Member::query()->where('gym_id', $gymId)->where('created_at', '>=', $from)->where('created_at', '<', $toExclusive),
            'created_at',
            'COUNT(*)',
            $timezone,
        );
        $attendance = $this->dailyAggregate(
            AttendanceRecord::query()->where('gym_id', $gymId)->where('checked_in_at', '>=', $from)->where('checked_in_at', '<', $toExclusive),
            'checked_in_at',
            'COUNT(*)',
            $timezone,
        );
        $gross = $this->dailyAggregate(
            Payment::query()->where('gym_id', $gymId)->where('currency', $currency->value)->whereIn('status', $settled)->where('paid_at', '>=', $from)->where('paid_at', '<', $toExclusive),
            'paid_at',
            'COALESCE(SUM(amount_minor), 0)',
            $timezone,
        );
        $refunds = $this->dailyAggregate(
            PaymentRefund::query()->where('gym_id', $gymId)->where('currency', $currency->value)->where('status', RefundStatus::Succeeded->value)->where('refunded_at', '>=', $from)->where('refunded_at', '<', $toExclusive),
            'refunded_at',
            'COALESCE(SUM(amount_minor), 0)',
            $timezone,
        );

        $start = CarbonImmutable::parse($fromDate, $timezone)->startOfDay();
        $series = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->addDays($offset)->toDateString();
            $grossMinor = $gross[$date] ?? 0;
            $refundedMinor = $refunds[$date] ?? 0;
            $series[] = [
                'date' => $date,
                'new_members' => $members[$date] ?? 0,
                'attendance_visits' => $attendance[$date] ?? 0,
                'gross_revenue_minor' => $grossMinor,
                'refunded_minor' => $refundedMinor,
                'net_revenue_minor' => $grossMinor - $refundedMinor,
            ];
        }

        return $series;
    }

    /** @return array<string, int> */
    private function dailyAggregate(Builder $query, string $column, string $aggregate, string $timezone): array
    {
        [$dateExpression, $bindings] = $this->localDateExpression($column, $timezone);

        // PostgreSQL groups in the gym timezone without loading raw event rows;
        // SQLite's expression exists only so local feature tests stay portable.
        return $query->toBase()
            ->selectRaw("{$dateExpression} AS report_date, {$aggregate} AS aggregate_value", $bindings)
            // Group by the selected alias so PostgreSQL does not receive two
            // different placeholders for the same timezone expression.
            ->groupBy('report_date')
            ->pluck('aggregate_value', 'report_date')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    /** @return array{string, list<string>} */
    private function localDateExpression(string $column, string $timezone): array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return ["DATE({$column} AT TIME ZONE ?)", [$timezone]];
        }

        return ["DATE({$column})", []];
    }

    /** @return list<array{status: string, count: int}> */
    private function memberStatusDistribution(string $gymId): array
    {
        return Member::query()
            ->where('gym_id', $gymId)
            ->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn (object $row): array => ['status' => (string) $row->status, 'count' => (int) $row->aggregate_count])
            ->values()
            ->all();
    }

    /** @return list<array{method: string, count: int, net_minor: int}> */
    private function paymentMethodMix(string $gymId, Currency $currency, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $settled = [PaymentStatus::Succeeded->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value];

        return Payment::query()
            ->where('gym_id', $gymId)
            ->where('currency', $currency->value)
            ->whereIn('status', $settled)
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $toExclusive)
            ->toBase()
            ->select('method')
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->selectRaw('COALESCE(SUM(amount_minor - refunded_amount_minor), 0) AS net_minor')
            ->groupBy('method')
            ->orderByDesc('net_minor')
            ->get()
            ->map(fn (object $row): array => [
                'method' => (string) $row->method,
                'count' => (int) $row->aggregate_count,
                'net_minor' => (int) $row->net_minor,
            ])
            ->values()
            ->all();
    }

    private function changeBasisPoints(int $current, int $previous): ?int
    {
        if ($previous === 0) {
            return $current === 0 ? 0 : null;
        }

        return (int) round((($current - $previous) * 10000) / abs($previous));
    }
}
