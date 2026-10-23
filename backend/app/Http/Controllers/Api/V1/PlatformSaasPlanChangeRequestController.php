<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaasPlanChangeRequestResource;
use App\Models\Gym;
use App\Models\SaasPlanChangeRequest;
use App\Services\SaasPlanChangeRequestService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PlatformSaasPlanChangeRequestController extends Controller
{
    public function index(Request $request, TenantContext $tenant): array
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $rows = new Collection();
        Gym::query()->orderBy('name')->each(function (Gym $gym) use ($tenant, $rows): void {
            $tenant->run($gym, function () use ($gym, $rows): void {
                SaasPlanChangeRequest::query()->with(['currentPlan', 'currentPrice', 'requestedPlan', 'requestedPrice', 'requestedBy:id,name,email', 'reviewedBy:id,name'])
                    ->latest('requested_at')->get()->each(function ($change) use ($gym, $rows): void {
                        $change->setRelation('gym', $gym); $rows->push($change);
                    });
            });
        });
        return ['data' => SaasPlanChangeRequestResource::collection($rows->sortByDesc('requested_at')->values())->resolve($request)];
    }

    public function review(Request $request, string $gym, string $change, TenantContext $tenant, SaasPlanChangeRequestService $service): SaasPlanChangeRequestResource
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $gymModel = Gym::query()->findOrFail($gym);
        $result = $tenant->run($gymModel, fn () => $service->review($gymModel, $change, $data['decision'], $data['reason'], $request->user(), $request));
        $result->setRelation('gym', $gymModel);
        return new SaasPlanChangeRequestResource($result);
    }
}
