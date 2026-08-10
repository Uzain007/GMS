<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationPreferenceRequest;
use App\Http\Resources\NotificationDeliveryResource;
use App\Http\Resources\NotificationPreferenceResource;
use App\Models\Member;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Services\AuditService;
use App\Services\TrainingAccessService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function preference(Request $request, TenantContext $tenant, TrainingAccessService $access): NotificationPreferenceResource
    {
        $role = $request->user()->roleForGym($tenant->id());
        $memberId = $role === UserRole::Member ? null : $request->query('member_id');
        $member = $access->memberForActor($request->user(), $memberId);
        $preference = NotificationPreference::query()->where('member_id', $member->getKey())->first();
        if (! $preference) {
            // Defaults are returned without a GET-side write.
            $preference = (new NotificationPreference)->forceFill([
                'gym_id' => $tenant->id(), 'member_id' => $member->getKey(),
                'email_enabled' => true, 'sms_enabled' => false, 'push_enabled' => false,
                'class_reminders_enabled' => true, 'workout_reminders_enabled' => true,
                'payment_reminders_enabled' => true, 'marketing_enabled' => false,
                'timezone' => $tenant->gym()->timezone,
            ]);
        }
        return new NotificationPreferenceResource($preference);
    }

    public function updatePreference(
        UpdateNotificationPreferenceRequest $request,
        TenantContext $tenant,
        AuditService $audit,
    ): NotificationPreferenceResource {
        // Route middleware restricts mutation to linked member self-service.
        $member = Member::query()->where('user_id', $request->user()->getKey())->firstOrFail();
        $preference = NotificationPreference::query()->firstOrNew(['member_id' => $member->getKey()]);
        $before = $preference->exists ? $preference->toArray() : [];
        $preference->fill(['timezone' => $tenant->gym()->timezone, ...$request->validated()])->save();
        $audit->record('notification_preference.updated', $preference, $request->user(), $before, $preference->toArray(), request: $request);
        return new NotificationPreferenceResource($preference);
    }

    public function deliveries(Request $request, TenantContext $tenant): AnonymousResourceCollection
    {
        $query = NotificationDelivery::query()->orderByDesc('created_at')->orderByDesc('id');
        if ($request->user()->roleForGym($tenant->id()) === UserRole::Member) {
            $member = Member::query()->where('user_id', $request->user()->getKey())->firstOrFail();
            $query->where('member_id', $member->getKey());
        } elseif ($request->filled('member_id')) {
            $query->where('member_id', $request->input('member_id'));
        }
        return NotificationDeliveryResource::collection($query->cursorPaginate(min(max((int) $request->input('per_page', 50), 1), 100)));
    }
}
