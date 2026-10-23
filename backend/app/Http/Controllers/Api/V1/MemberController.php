<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberStatus;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MemberAccessCredential;
use App\Enums\AccessCredentialStatus;
use App\Models\GymBranch;
use App\Services\AuditService;
use App\Services\MemberCodeService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MemberController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $query = Member::query()->orderByDesc('created_at');

        if (request()->filled('status')) {
            $query->where('status', request('status'));
        } else {
            // Removed members remain available to the platform historical directory,
            // but no longer appear in the gym's active operational population.
            $query->where('status', '!=', MemberStatus::Deleted->value);
        }
        if (request()->filled('branch_id')) {
            $query->where('home_branch_id', request('branch_id'));
        }
        if ($search = trim((string) request('search'))) {
            $prefix = mb_substr($search, 0, 100).'%';
            // Prefix searches can use tenant-leading member number/name indexes.
            $query->where(fn ($builder) => $builder
                ->where('member_number', 'like', $prefix)
                ->orWhere('member_code', '=', $search)
                ->orWhere('last_name', 'like', $prefix)
                ->orWhere('email', '=', mb_strtolower($search)));
        }

        return MemberResource::collection($query->paginate($this->pageSize()));
    }

    public function store(StoreMemberRequest $request, AuditService $audit, MemberCodeService $memberCodes): MemberResource
    {
        $data = $request->validated();
        $data['member_number'] ??= 'MBR-'.Str::upper((string) Str::ulid());
        $data['email'] = isset($data['email']) ? mb_strtolower($data['email']) : null;
        $data['status'] ??= MemberStatus::Lead->value;

        $member = DB::transaction(function () use ($data, $request, $audit, $memberCodes): Member {
            $data['member_code'] = $memberCodes->generate();
            $member = Member::query()->create($data);
            $audit->record('member.created', $member, $request->user(), after: $member->toArray(), request: $request);
            return $member;
        });

        return new MemberResource($member);
    }

    public function show(string $member): MemberResource
    {
        $model = Member::query()->findOrFail($member);
        // A member profile loads one bounded current-contract summary through
        // the already selected tenant; it never scans or returns membership history.
        $currentMembership = Membership::query()->with(['plan', 'branch'])
            ->where('member_id', $model->getKey())
            ->whereIn('status', [
                MembershipStatus::Active->value,
                MembershipStatus::Paused->value,
                MembershipStatus::Pending->value,
            ])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")
            ->latest('starts_at')
            ->first();
        $model->setRelation('currentMembership', $currentMembership);

        return new MemberResource($model);
    }

    public function update(UpdateMemberRequest $request, string $member, AuditService $audit): MemberResource
    {
        $model = Member::query()->findOrFail($member);
        $before = $model->toArray();
        $data = $request->safe()->except('reason');
        if (isset($data['home_branch_id']) && $data['home_branch_id'] !== $model->home_branch_id
            && ! GymBranch::query()->whereKey($data['home_branch_id'])->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['home_branch_id' => ['Assign members only to an active branch.']]);
        }
        if (array_key_exists('email', $data) && $data['email']) {
            $data['email'] = mb_strtolower($data['email']);
        }
        if (($data['status'] ?? null) === MemberStatus::Archived->value) {
            $data['archived_at'] = now();
        }
        if (isset($data['status'])) {
            $status = MemberStatus::from($data['status']);
            $data['lifecycle_changed_at'] = now();
            $data['lifecycle_reason'] = (string) $request->string('reason');
            if ($status === MemberStatus::Active) {
                $data['archived_at'] = null;
            }
        }

        $fresh = DB::transaction(function () use ($model, $data, $audit, $request, $before): Member {
            $model->update($data);
            if (isset($data['status'])) {
                $active = $data['status'] === MemberStatus::Active->value;
                if ($model->user_id) {
                    DB::table('gym_user')->where('gym_id', $model->gym_id)->where('user_id', $model->user_id)
                        ->update(['status' => $active ? 'active' : $data['status'], 'updated_at' => now()]);
                }
                if (! $active) {
                    MemberAccessCredential::query()->where('member_id', $model->id)
                        ->where('status', AccessCredentialStatus::Active->value)
                        ->update(['status' => AccessCredentialStatus::Revoked->value, 'revoked_at' => now()]);
                }
            }
            $fresh = $model->fresh();
            $event = ! isset($data['status']) || ($before['status'] ?? null) === $fresh->status->value
                ? 'member.updated'
                : match ($fresh->status) {
                    MemberStatus::Suspended => 'member.suspended',
                    MemberStatus::Inactive => 'member.deactivated',
                    MemberStatus::Deleted => 'member.deleted',
                    MemberStatus::Active => 'member.reactivated',
                    default => 'member.updated',
                };
            $audit->record($event, $fresh, $request->user(), $before, $fresh->toArray(), (string) $request->string('reason'), $request);
            return $fresh;
        });

        return new MemberResource($fresh);
    }

    public function destroy(\Illuminate\Http\Request $request, string $member, AuditService $audit): MemberResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $model = Member::query()->findOrFail($member);
        if ($model->status === MemberStatus::Deleted) {
            return new MemberResource($model);
        }
        $before = $model->toArray();
        $fresh = DB::transaction(function () use ($model, $data, $request, $audit, $before): Member {
            $model->update([
                'status' => MemberStatus::Deleted,
                'lifecycle_changed_at' => now(),
                'lifecycle_reason' => $data['reason'],
            ]);
            if ($model->user_id) {
                DB::table('gym_user')->where('gym_id', $model->gym_id)->where('user_id', $model->user_id)
                    ->update(['status' => 'deleted', 'updated_at' => now()]);
            }
            MemberAccessCredential::query()->where('member_id', $model->id)
                ->where('status', AccessCredentialStatus::Active->value)
                ->update(['status' => AccessCredentialStatus::Revoked->value, 'revoked_at' => now()]);
            $fresh = $model->fresh();
            $audit->record('member.deleted', $fresh, $request->user(), $before, $fresh->toArray(), $data['reason'], $request);
            return $fresh;
        });
        return new MemberResource($fresh);
    }

    private function pageSize(): int
    {
        return min(max((int) request('per_page', 25), 1), 100);
    }
}
