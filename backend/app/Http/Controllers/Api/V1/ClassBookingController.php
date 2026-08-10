<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelClassBookingRequest;
use App\Http\Requests\StoreClassBookingRequest;
use App\Http\Resources\ClassBookingResource;
use App\Models\ClassBooking;
use App\Models\ClassSession;
use App\Models\Member;
use App\Services\ClassBookingService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClassBookingController extends Controller
{
    public function index(Request $request, TenantContext $tenant): AnonymousResourceCollection
    {
        $query = ClassBooking::query()->with(['member', 'session'])
            ->orderByDesc('booked_at');
        $role = $request->user()->roleForGym($tenant->id());
        if ($role === UserRole::Member) {
            // Member self-service is derived from the authenticated user link;
            // a query-string member UUID never grants broader roster access.
            $member = Member::query()->where('user_id', $request->user()->getKey())->firstOrFail();
            $query->where('member_id', $member->getKey());
        } elseif ($role === UserRole::Trainer) {
            $trainer = \App\Models\StaffProfile::query()
                ->where('user_id', $request->user()->getKey())->firstOrFail();
            $query->whereHas('session', fn ($session) => $session
                ->where('trainer_staff_profile_id', $trainer->getKey()));
        } else {
            foreach (['class_session_id', 'member_id', 'status'] as $filter) {
                if ($request->filled($filter)) {
                    $query->where($filter, $request->input($filter));
                }
            }
        }
        return ClassBookingResource::collection(
            $query->paginate(min(max((int) $request->input('per_page', 50), 1), 100))
        );
    }

    public function sessionBookings(
        Request $request,
        string $session,
        ClassBookingService $service,
    ): AnonymousResourceCollection {
        $model = ClassSession::query()->findOrFail($session);
        $service->assertRosterActor($model, $request->user());
        return ClassBookingResource::collection(
            ClassBooking::query()->with(['member', 'session'])
                ->where('class_session_id', $model->getKey())
                ->orderByRaw("CASE status WHEN 'booked' THEN 0 WHEN 'attended' THEN 1 WHEN 'waitlisted' THEN 2 ELSE 3 END")
                ->orderBy('waitlist_sequence')->orderBy('booked_at')
                ->paginate(min(max((int) $request->input('per_page', 100), 1), 100))
        );
    }

    public function store(
        StoreClassBookingRequest $request,
        string $session,
        ClassBookingService $service,
    ): ClassBookingResource {
        return new ClassBookingResource($service->book(
            $session,
            $request->validated('member_id'),
            $request->user(),
            $request,
        ));
    }

    public function cancel(
        CancelClassBookingRequest $request,
        string $booking,
        ClassBookingService $service,
    ): ClassBookingResource {
        return new ClassBookingResource($service->cancel(
            $booking,
            $request->validated('reason'),
            $request->user(),
            $request,
        ));
    }

    public function attend(Request $request, string $booking, ClassBookingService $service): ClassBookingResource
    {
        return new ClassBookingResource($service->attend($booking, $request->user(), $request));
    }
}
