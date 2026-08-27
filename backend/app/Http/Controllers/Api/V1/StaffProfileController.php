<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StaffStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrainerRequest;
use App\Http\Requests\UpdateOwnStaffProfileRequest;
use App\Http\Requests\UpdateStaffProfileRequest;
use App\Http\Requests\UpdateStaffProfileImageRequest;
use App\Http\Resources\StaffProfileResource;
use App\Models\StaffProfile;
use App\Services\AuditService;
use App\Services\StaffInvitationService;
use App\Services\TrainerLifecycleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffProfileController extends Controller
{
    public function me(Request $request): StaffProfileResource
    {
        return new StaffProfileResource(
            $this->staffQuery()->with('user')
                ->where('staff_profiles.user_id', $request->user()->getKey())
                ->firstOrFail()
        );
    }

    public function updateMe(
        UpdateOwnStaffProfileRequest $request,
        AuditService $audit,
    ): StaffProfileResource {
        $profile = $this->staffQuery()->with('user')
            ->where('staff_profiles.user_id', $request->user()->getKey())
            ->firstOrFail();
        $before = $profile->makeHidden(['profile_image_disk', 'profile_image_path'])->toArray();
        $data = $request->safe()->except('reason');
        if (isset($data['display_name'])) {
            $data['display_name'] = trim($data['display_name']);
        }
        if (isset($data['contact_email'])) {
            $data['contact_email'] = mb_strtolower(trim($data['contact_email']));
        }
        if (isset($data['phone'])) {
            $data['phone'] = trim($data['phone']);
        }

        $fresh = DB::transaction(function () use ($profile, $data, $before, $request, $audit): StaffProfile {
            $profile->update($data);
            $fresh = $this->staffQuery()->with('user')->findOrFail($profile->getKey());
            $audit->record(
                'staff.profile.self_updated',
                $fresh,
                $request->user(),
                $before,
                $fresh->makeHidden(['profile_image_disk', 'profile_image_path'])->toArray(),
                (string) $request->string('reason'),
                $request,
            );
            return $fresh;
        });

        return new StaffProfileResource($fresh);
    }

    public function index(): AnonymousResourceCollection
    {
        $query = $this->staffQuery()->with('user')->orderBy('staff_name_sort');
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

    public function store(StoreTrainerRequest $request, TrainerLifecycleService $service): JsonResponse
    {
        $result = $service->create(
            $request->safe()->except('profile_image'),
            $request->user(),
            $request,
            $request->file('profile_image'),
        );

        return response()->json([
            'data' => (new StaffProfileResource($result['profile']))->resolve($request),
            // This plaintext setup secret is returned once and is never audited.
            'meta' => [
                'account_setup_token' => $result['setup_token'],
                'existing_account' => $result['existing_account'],
            ],
        ], 201);
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
        $before = array_merge($profile->makeHidden(['profile_image_disk', 'profile_image_path'])->toArray(), ['role' => $profile->tenant_role]);
        $data = $request->safe()->except(['reason', 'role']);
        if (isset($data['display_name'])) {
            $data['display_name'] = trim($data['display_name']);
        }
        if (isset($data['contact_email'])) {
            $data['contact_email'] = mb_strtolower(trim($data['contact_email']));
        }
        if (isset($data['phone'])) {
            $data['phone'] = trim($data['phone']);
        }
        if ($request->filled('role')) {
            // Reuse invitation privilege rules so managers cannot promote peers.
            $roleGuard->ensureRoleCanBeGranted($request->user(), (string) $request->string('role'));
        }

        $fresh = DB::transaction(function () use ($request, $profile, $data, $audit, $before): StaffProfile {
            $profile->update($data);

            if ($request->exists('home_branch_id')) {
                $branchId = $request->input('home_branch_id');
                // Explicit tenant key in the pivot keeps branch replacement
                // inside the gym selected by middleware and PostgreSQL RLS.
                $profile->branches()->sync($branchId ? [
                    $branchId => ['gym_id' => $profile->gym_id, 'is_primary' => true],
                ] : []);
            }

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
                array_merge($fresh->makeHidden(['profile_image_disk', 'profile_image_path'])->toArray(), ['role' => $fresh->tenant_role]),
                (string) $request->string('reason'),
                $request,
            );
            return $fresh;
        });

        return new StaffProfileResource($fresh);
    }

    public function image(string $staff): StreamedResponse
    {
        $profile = $this->staffQuery()->findOrFail($staff);
        abort_unless($profile->profile_image_disk && $profile->profile_image_path, 404);
        $disk = Storage::disk($profile->profile_image_disk);
        abort_unless($disk->exists($profile->profile_image_path), 404);

        return response()->stream(function () use ($disk, $profile): void {
            $stream = $disk->readStream($profile->profile_image_path);
            abort_unless(is_resource($stream), 404);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $profile->profile_image_mime ?: 'application/octet-stream',
            'Content-Length' => (string) $profile->profile_image_size,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function replaceImage(
        UpdateStaffProfileImageRequest $request,
        string $staff,
        TrainerLifecycleService $service,
    ): StaffProfileResource {
        $profile = $this->staffQuery()->with('user')->findOrFail($staff);
        return new StaffProfileResource($service->replaceImage(
            $profile,
            $request->file('profile_image'),
            $request->user(),
            (string) $request->string('reason'),
            $request,
        ));
    }

    public function removeImage(Request $request, string $staff, TrainerLifecycleService $service): StaffProfileResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $profile = $this->staffQuery()->with('user')->findOrFail($staff);
        return new StaffProfileResource($service->removeImage($profile, $request->user(), $data['reason'], $request));
    }

    public function destroy(Request $request, string $staff, TrainerLifecycleService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $profile = $this->staffQuery()->with('user')->findOrFail($staff);
        $service->delete($profile, $request->user(), $data['reason'], $request);
        return response()->json(status: 204);
    }

    private function staffQuery(): Builder
    {
        // One tenant-scoped join avoids an N+1 role query for every staff row.
        return StaffProfile::query()
            ->select([
                'staff_profiles.*',
                'gym_user.role as tenant_role',
                DB::raw('COALESCE(staff_profiles.display_name, users.name) as staff_name_sort'),
            ])
            ->join('gym_user', function (JoinClause $join): void {
                $join->on('gym_user.gym_id', '=', 'staff_profiles.gym_id')
                    ->on('gym_user.user_id', '=', 'staff_profiles.user_id');
            })
            ->join('users', 'users.id', '=', 'staff_profiles.user_id');
    }
}
