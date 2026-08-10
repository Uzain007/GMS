<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StaffStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStaffProfileRequest;
use App\Http\Resources\StaffProfileResource;
use App\Models\StaffProfile;
use App\Services\AuditService;
use App\Services\StaffInvitationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class StaffProfileController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $query = $this->staffQuery()->with('user')->orderBy('users_name_sort');
        if (request()->filled('status')) {
            $query->where('staff_profiles.status', request('status'));
        }

        return StaffProfileResource::collection(
            $query->paginate(min(max((int) request('per_page', 25), 1), 100))
        );
    }

    public function show(string $staff): StaffProfileResource
    {
        return new StaffProfileResource($this->staffQuery()->with('user')->findOrFail($staff));
    }

    public function update(
        UpdateStaffProfileRequest $request,
        string $staff,
        AuditService $audit,
        StaffInvitationService $roleGuard,
    ): StaffProfileResource {
        $profile = $this->staffQuery()->with('user')->findOrFail($staff);
        // The current tenant role is selected with both gym/user keys; managers
        // cannot bypass hierarchy checks by omitting `role` from the payload.
        $roleGuard->ensureProfileCanBeManaged($request->user(), (string) $profile->tenant_role);
        $before = array_merge($profile->toArray(), ['role' => $profile->tenant_role]);
        $data = $request->safe()->except(['reason', 'role']);
        if ($request->filled('role')) {
            // Reuse invitation privilege rules so managers cannot promote peers.
            $roleGuard->ensureRoleCanBeGranted($request->user(), (string) $request->string('role'));
        }

        $fresh = DB::transaction(function () use ($request, $profile, $data, $audit, $before): StaffProfile {
            $profile->update($data);

            $pivot = [];
            if ($request->filled('role')) {
                $pivot['role'] = (string) $request->string('role');
            }
            if ($request->filled('status')) {
                $status = StaffStatus::from((string) $request->string('status'));
                $pivot['status'] = $status === StaffStatus::Active ? 'active' : $status->value;
            }
            if ($pivot) {
                // Both IDs are included so the role update cannot reach another tenant.
                DB::table('gym_user')
                    ->where('gym_id', $profile->gym_id)
                    ->where('user_id', $profile->user_id)
                    ->update(array_merge($pivot, ['updated_at' => now()]));
            }
            $fresh = $this->staffQuery()->with('user')->findOrFail($profile->getKey());
            $audit->record(
                'staff.updated',
                $fresh,
                $request->user(),
                $before,
                array_merge($fresh->toArray(), ['role' => $fresh->tenant_role]),
                (string) $request->string('reason'),
                $request,
            );
            return $fresh;
        });

        return new StaffProfileResource($fresh);
    }

    private function staffQuery(): Builder
    {
        // One tenant-scoped join avoids an N+1 role query for every staff row.
        return StaffProfile::query()
            ->select([
                'staff_profiles.*',
                'gym_user.role as tenant_role',
                'users.name as users_name_sort',
            ])
            ->join('gym_user', function (JoinClause $join): void {
                $join->on('gym_user.gym_id', '=', 'staff_profiles.gym_id')
                    ->on('gym_user.user_id', '=', 'staff_profiles.user_id');
            })
            ->join('users', 'users.id', '=', 'staff_profiles.user_id');
    }
}
