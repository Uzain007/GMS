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
use App\Services\AuditService;
use App\Services\MemberCodeService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MemberController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $query = Member::query()->orderByDesc('created_at');

        if (request()->filled('status')) {
            $query->where('status', request('status'));
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
        if (array_key_exists('email', $data) && $data['email']) {
            $data['email'] = mb_strtolower($data['email']);
        }
        if (($data['status'] ?? null) === MemberStatus::Archived->value) {
            $data['archived_at'] = now();
        }

        $fresh = DB::transaction(function () use ($model, $data, $audit, $request, $before): Member {
            $model->update($data);
            $fresh = $model->fresh();
            $audit->record('member.updated', $fresh, $request->user(), $before, $fresh->toArray(), (string) $request->string('reason'), $request);
            return $fresh;
        });

        return new MemberResource($fresh);
    }

    private function pageSize(): int
    {
        return min(max((int) request('per_page', 25), 1), 100);
    }
}
