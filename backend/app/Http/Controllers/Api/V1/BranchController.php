<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BranchStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Http\Resources\GymBranchResource;
use App\Models\GymBranch;
use App\Services\AuditService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $query = GymBranch::query()->orderByDesc('is_primary')->orderBy('name');
        if (request()->filled('status')) {
            $query->where('status', request('status'));
        }

        // Page size is capped so a tenant cannot create unbounded database work.
        return GymBranchResource::collection($query->paginate($this->pageSize()));
    }

    public function store(StoreBranchRequest $request, AuditService $audit): GymBranchResource
    {
        $branch = DB::transaction(function () use ($request, $audit): GymBranch {
            $data = $request->validated();
            // Set the application default explicitly so the just-created model
            // and its tenant-safe response agree with PostgreSQL immediately.
            $data['status'] ??= BranchStatus::Active->value;
            if ($data['is_primary'] ?? false) {
                GymBranch::query()->update(['is_primary' => false]);
            }

            $branch = GymBranch::query()->create($data);
            $audit->record('branch.created', $branch, $request->user(), after: $branch->toArray(), request: $request);
            return $branch;
        });

        return new GymBranchResource($branch);
    }

    public function show(string $branch): GymBranchResource
    {
        // Resolve after tenant middleware; implicit binding would run before context.
        return new GymBranchResource(GymBranch::query()->findOrFail($branch));
    }

    public function update(UpdateBranchRequest $request, string $branch, AuditService $audit): GymBranchResource
    {
        $model = GymBranch::query()->findOrFail($branch);
        $before = $model->toArray();
        $data = $request->safe()->except('reason');

        $fresh = DB::transaction(function () use ($model, $data, $audit, $request, $before): GymBranch {
            if (($data['is_primary'] ?? false) === true) {
                GymBranch::query()->where('id', '!=', $model->getKey())->update(['is_primary' => false]);
            }
            $model->update($data);
            $fresh = $model->fresh();
            $audit->record('branch.updated', $fresh, $request->user(), $before, $fresh->toArray(), (string) $request->string('reason'), $request);
            return $fresh;
        });

        return new GymBranchResource($fresh);
    }

    private function pageSize(): int
    {
        return min(max((int) request('per_page', 25), 1), 100);
    }
}
