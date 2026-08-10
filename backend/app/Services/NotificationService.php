<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Jobs\SendNotificationDelivery;
use App\Models\Member;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Notifications\EmailNotificationAdapter;
use App\Services\Notifications\PushNotificationAdapter;
use App\Services\Notifications\SmsNotificationAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class NotificationService
{
    public function __construct(
        private readonly EmailNotificationAdapter $email,
        private readonly SmsNotificationAdapter $sms,
        private readonly PushNotificationAdapter $push,
    ) {}

    public function queueWorkoutAssigned(WorkoutPlan $plan, User $actor): void
    {
        $member = $plan->relationLoaded('member') ? $plan->member : Member::query()->findOrFail($plan->member_id);
        $preference = NotificationPreference::query()->where('member_id', $member->getKey())->first();
        if (($preference?->workout_reminders_enabled ?? true) && ($preference?->email_enabled ?? true) && $member->email) {
            $this->queue(
                $member, $actor, NotificationChannel::Email, $member->email,
                'workout_plan_assigned',
                [
                    'subject' => 'Your new workout plan is ready',
                    'body' => "{$plan->title} is now available in your IronCore coaching workspace.",
                    'data' => ['workout_plan_id' => $plan->getKey()],
                ],
                "workout-plan:{$plan->getKey()}:assigned:email",
                $preference,
            );
        }
    }

    public function queue(
        Member $member,
        ?User $actor,
        NotificationChannel $channel,
        string $destination,
        string $templateKey,
        array $variables,
        string $idempotencyKey,
        ?NotificationPreference $preference = null,
    ): NotificationDelivery {
        $scheduledAt = $this->nextAllowedAt($preference);
        // Tenant idempotency prevents repeated domain events from producing
        // duplicate deliveries for the same member action.
        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'member_id' => $member->getKey(), 'triggered_by' => $actor?->getKey(),
                'channel' => $channel, 'template_key' => $templateKey,
                'destination' => $destination, 'variables' => $this->safeVariables($variables),
                'status' => NotificationDeliveryStatus::Queued, 'scheduled_at' => $scheduledAt,
            ],
        );

        if ($delivery->wasRecentlyCreated) {
            SendNotificationDelivery::dispatch($delivery->gym_id, $delivery->getKey())
                ->delay($scheduledAt)->onQueue('notifications');
        }
        return $delivery;
    }

    public function deliver(string $deliveryId): void
    {
        $delivery = DB::transaction(function () use ($deliveryId): ?NotificationDelivery {
            $delivery = NotificationDelivery::query()->lockForUpdate()->findOrFail($deliveryId);
            if (! in_array($delivery->status, [NotificationDeliveryStatus::Queued, NotificationDeliveryStatus::Failed], true) || $delivery->attempts >= 3) {
                return null;
            }
            $preference = NotificationPreference::query()->where('member_id', $delivery->member_id)->first();
            if (! $this->channelEnabled($delivery->channel, $preference)) {
                $delivery->update(['status' => NotificationDeliveryStatus::Suppressed, 'failure_code' => 'preference_disabled']);
                return null;
            }
            $delivery->update([
                'status' => NotificationDeliveryStatus::Sending,
                'attempts' => $delivery->attempts + 1,
                'failure_code' => null, 'failure_message' => null,
            ]);
            return $delivery->fresh();
        });

        if (! $delivery) {
            return;
        }

        try {
            $providerId = match ($delivery->channel) {
                NotificationChannel::Email => $this->email->send($delivery),
                NotificationChannel::Sms => $this->sms->send($delivery),
                NotificationChannel::Push => $this->push->send($delivery),
            };
            $delivery->update(['status' => NotificationDeliveryStatus::Sent, 'provider_message_id' => $providerId, 'sent_at' => now()]);
        } catch (Throwable $exception) {
            // Persist a bounded operational message; destination and provider
            // credentials never enter logs or failure evidence.
            $delivery->update([
                'status' => NotificationDeliveryStatus::Failed,
                'failure_code' => 'adapter_error',
                'failure_message' => 'The notification provider rejected the delivery.',
            ]);
            throw $exception;
        }
    }

    public function markPermanentlyFailed(string $deliveryId, Throwable $exception): void
    {
        NotificationDelivery::query()->whereKey($deliveryId)->update([
            'status' => NotificationDeliveryStatus::Failed->value,
            'failure_code' => 'queue_failed',
            'failure_message' => 'The notification queue exhausted its retry policy.',
        ]);
    }

    private function channelEnabled(NotificationChannel $channel, ?NotificationPreference $preference): bool
    {
        return match ($channel) {
            NotificationChannel::Email => $preference?->email_enabled ?? true,
            NotificationChannel::Sms => $preference?->sms_enabled ?? false,
            NotificationChannel::Push => $preference?->push_enabled ?? false,
        };
    }

    private function nextAllowedAt(?NotificationPreference $preference): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        if (! $preference?->quiet_hours_start || ! $preference?->quiet_hours_end) {
            return $now;
        }
        $local = $now->setTimezone($preference->timezone);
        $start = CarbonImmutable::parse($local->toDateString().' '.$preference->quiet_hours_start, $preference->timezone);
        $end = CarbonImmutable::parse($local->toDateString().' '.$preference->quiet_hours_end, $preference->timezone);
        if ($start->lessThan($end) && $local->betweenIncluded($start, $end)) {
            return $end->utc();
        }
        if ($start->greaterThan($end)) {
            if ($local->greaterThanOrEqualTo($start)) {
                return $end->addDay()->utc();
            }
            if ($local->lessThanOrEqualTo($end)) {
                return $end->utc();
            }
        }
        return $now;
    }

    private function safeVariables(array $variables): array
    {
        return [
            'subject' => Str::limit((string) ($variables['subject'] ?? ''), 160),
            'body' => Str::limit((string) ($variables['body'] ?? ''), 2000),
            // Only bounded server-authored navigation metadata is queued.
            'data' => array_slice((array) ($variables['data'] ?? []), 0, 10, true),
        ];
    }
}
