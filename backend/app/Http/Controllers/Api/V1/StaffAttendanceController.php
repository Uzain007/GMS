<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffAttendanceRecordResource;
use App\Models\StaffAttendanceRecord;
use App\Services\StaffAttendanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffAttendanceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return StaffAttendanceRecordResource::collection(
            StaffAttendanceRecord::query()->with(['staffProfile.user', 'branch'])->latest('checked_in_at')->paginate(100)
        );
    }

    public function checkIn(Request $request, StaffAttendanceService $service): StaffAttendanceRecordResource
    {
        $data = $request->validate([
            'staff_profile_id' => ['required', 'uuid'],
            'branch_id' => ['required', 'uuid'],
        ]);
        return new StaffAttendanceRecordResource($service->checkIn($data['staff_profile_id'], $data['branch_id'], $request->user(), $request));
    }

    public function checkOut(Request $request, string $attendance, StaffAttendanceService $service): StaffAttendanceRecordResource
    {
        return new StaffAttendanceRecordResource($service->checkOut($attendance, $request->user(), $request));
    }
}
