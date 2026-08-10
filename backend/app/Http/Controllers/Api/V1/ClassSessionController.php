<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClassSessionRequest;
use App\Http\Requests\UpdateClassSessionRequest;
use App\Http\Resources\ClassSessionResource;
use App\Models\ClassSession;
use App\Services\ClassBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ClassSessionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $from = CarbonImmutable::parse((string) $request->input('from', now()->startOfDay()->toIso8601String()));
        $to = CarbonImmutable::parse((string) $request->input('to', now()->addDays(30)->endOfDay()->toIso8601String()));
        if ($to->isBefore($from) || $from->diffInDays($to) > 92) {
            throw ValidationException::withMessages(['to' => ['Class schedule ranges must be ordered and no longer than 92 days.']]);
        }

        $query = ClassSession::query()->with(['branch', 'trainer.user'])
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('starts_at')->orderBy('id');
        foreach (['branch_id', 'trainer_staff_profile_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        return ClassSessionResource::collection(
            $query->paginate(min(max((int) $request->input('per_page', 50), 1), 100))
        );
    }

    public function store(StoreClassSessionRequest $request, ClassBookingService $service): ClassSessionResource
    {
        return new ClassSessionResource($service->createSession($request->validated(), $request->user(), $request));
    }

    public function update(
        UpdateClassSessionRequest $request,
        string $session,
        ClassBookingService $service,
    ): ClassSessionResource {
        return new ClassSessionResource($service->updateSession($session, $request->validated(), $request->user(), $request));
    }
}
