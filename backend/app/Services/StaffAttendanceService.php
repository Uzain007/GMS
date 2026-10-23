<?php

namespace App\Services;

use App\Enums\AttendanceMethod;
use App\Enums\AttendanceStatus;
use App\Enums\BranchStatus;
use App\Enums\CheckoutType;
use App\Enums\StaffStatus;
use App\Models\GymBranch;
use App\Models\StaffAttendanceRecord;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffAttendanceService
{
    private const MAX_OPEN_HOURS = 12;

    public function __construct(private readonly AuditService $audit) {}

    public function checkIn(string $staffId, string $branchId, User $actor, Request $request): StaffAttendanceRecord
    {
        return DB::transaction(function () use ($staffId, $branchId, $actor, $request): StaffAttendanceRecord {
            $staff = StaffProfile::query()->lockForUpdate()->findOrFail($staffId);
            $branch = GymBranch::query()->findOrFail($branchId);
            if ($staff->status !== StaffStatus::Active || $branch->status !== BranchStatus::Active) {
                throw ValidationException::withMessages(['staff' => ['Only active employees at an active branch can check in.']]);
            }
            if (StaffAttendanceRecord::query()->where('staff_profile_id', $staff->id)
                ->where('status', AttendanceStatus::CheckedIn->value)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['staff' => ['This employee is already checked in.']]);
            }
            $record = StaffAttendanceRecord::query()->create([
                'staff_profile_id' => $staff->id,
                'branch_id' => $branch->id,
                'checked_in_by' => $actor->id,
                'method' => AttendanceMethod::Manual,
                'status' => AttendanceStatus::CheckedIn,
                'checked_in_at' => now(),
            ]);
            $this->audit->record('attendance.employee_checked_in', $record, $actor, after: [
                'staff_profile_id' => $staff->id, 'branch_id' => $branch->id,
                'checked_in_at' => $record->checked_in_at?->toIso8601String(),
            ], request: $request);
            return $record->load(['staffProfile.user', 'branch']);
        });
    }

    public function checkOut(string $id, User $actor, Request $request): StaffAttendanceRecord
    {
        return DB::transaction(function () use ($id, $actor, $request): StaffAttendanceRecord {
            $record = StaffAttendanceRecord::query()->lockForUpdate()->findOrFail($id);
            if ($record->status !== AttendanceStatus::CheckedIn) {
                throw ValidationException::withMessages(['attendance' => ['This employee attendance record is already closed.']]);
            }
            $record->update([
                'status' => AttendanceStatus::CheckedOut,
                'checked_out_by' => $actor->id,
                'checked_out_at' => now(),
                'checkout_type' => CheckoutType::Manual,
            ]);
            $this->audit->record('attendance.employee_checked_out', $record, $actor, after: [
                'staff_profile_id' => $record->staff_profile_id,
                'checked_out_at' => $record->checked_out_at?->toIso8601String(),
                'checkout_type' => CheckoutType::Manual->value,
            ], request: $request);
            return $record->load(['staffProfile.user', 'branch']);
        });
    }

    public function closeExpired(): int
    {
        return DB::transaction(function (): int {
            $records = StaffAttendanceRecord::query()->where('status', AttendanceStatus::CheckedIn->value)
                ->where('checked_in_at', '<=', now()->subHours(self::MAX_OPEN_HOURS))
                ->lockForUpdate()->get();
            foreach ($records as $record) {
                $checkoutAt = $record->checked_in_at->addHours(self::MAX_OPEN_HOURS);
                $record->update([
                    'status' => AttendanceStatus::CheckedOut,
                    'checked_out_by' => null,
                    'checked_out_at' => $checkoutAt,
                    'checkout_type' => CheckoutType::Automatic,
                ]);
                $this->audit->record('attendance.employee_auto_checked_out', $record->fresh(), null,
                    before: ['status' => AttendanceStatus::CheckedIn->value],
                    after: ['status' => AttendanceStatus::CheckedOut->value, 'checked_out_at' => $checkoutAt->toIso8601String(), 'checkout_type' => CheckoutType::Automatic->value],
                    reason: 'The employee session reached the twelve-hour automatic checkout limit.');
            }
            return $records->count();
        });
    }
}
