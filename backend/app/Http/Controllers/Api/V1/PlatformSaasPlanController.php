<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaasPlanPriceRequest;
use App\Http\Requests\StoreSaasPlanRequest;
use App\Http\Requests\UpdateSaasPlanRequest;
use App\Http\Resources\SaasPlanPriceResource;
use App\Http\Resources\SaasPlanResource;
use App\Models\SaasPlan;
use App\Services\SaasBillingService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlatformSaasPlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SaasPlanResource::collection(
            SaasPlan::query()->with(['prices' => fn ($query) => $query->latest()])
                ->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function store(StoreSaasPlanRequest $request, SaasBillingService $billing): SaasPlanResource
    {
        return new SaasPlanResource($billing->createPlan($request->validated(), $request->user(), $request));
    }

    public function update(UpdateSaasPlanRequest $request, SaasPlan $plan, SaasBillingService $billing): SaasPlanResource
    {
        return new SaasPlanResource($billing->updatePlan($plan, $request->validated(), $request->user(), $request));
    }

    public function storePrice(
        StoreSaasPlanPriceRequest $request,
        SaasPlan $plan,
        SaasBillingService $billing,
    ): SaasPlanPriceResource {
        return new SaasPlanPriceResource($billing->addPrice($plan, $request->validated(), $request->user(), $request));
    }
}
