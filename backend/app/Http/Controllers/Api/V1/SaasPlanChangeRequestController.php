<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaasPlanChangeRequest;
use App\Http\Resources\SaasPlanChangeRequestResource;
use App\Models\SaasPlanChangeRequest;
use App\Models\SaasPlanPrice;
use App\Services\SaasPlanChangeRequestService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SaasPlanChangeRequestController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SaasPlanChangeRequestResource::collection(SaasPlanChangeRequest::query()
            ->with(['currentPlan', 'currentPrice', 'requestedPlan', 'requestedPrice', 'requestedBy:id,name,email', 'reviewedBy:id,name'])
            ->latest('requested_at')->paginate(25));
    }

    public function store(StoreSaasPlanChangeRequest $request, SaasPlanChangeRequestService $service, TenantContext $tenant): SaasPlanChangeRequestResource
    {
        $price = SaasPlanPrice::query()->with('plan')->findOrFail($request->validated('saas_plan_price_id'));
        return new SaasPlanChangeRequestResource($service->create($tenant->gym(), $price, $request->user(), $request->validated('reason'), $request));
    }

    public function cancel(Request $request, string $change, SaasPlanChangeRequestService $service): SaasPlanChangeRequestResource
    {
        return new SaasPlanChangeRequestResource($service->cancel(SaasPlanChangeRequest::query()->findOrFail($change), $request->user(), $request));
    }
}
