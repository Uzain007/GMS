<?php

namespace App\Services;

use App\Models\Gym;
use App\Tenancy\TenantContext;

class AutomatedAttendanceCheckoutService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AttendanceService $members,
        private readonly StaffAttendanceService $staff,
    ) {}

    /** @return array{gyms:int,members:int,staff:int} */
    public function runAll(): array
    {
        $totals = ['gyms' => 0, 'members' => 0, 'staff' => 0];
        Gym::query()->whereNotIn('status', ['cancelled'])->orderBy('id')->each(function (Gym $gym) use (&$totals): void {
            $result = $this->tenant->run($gym, fn () => [
                'members' => $this->members->closeStaleOpenRecords(),
                'staff' => $this->staff->closeExpired(),
            ]);
            $totals['gyms']++;
            $totals['members'] += $result['members'];
            $totals['staff'] += $result['staff'];
        });
        return $totals;
    }
}
