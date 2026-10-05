<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GymStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGymRequest;
use App\Http\Requests\UpdateGymRequest;
use App\Http\Requests\DeleteGymRequest;
use App\Http\Resources\GymResource;
use App\Models\Gym;
use App\Models\GymBranch;
use App\Models\SaasPlanPrice;
use App\Services\AuditService;
use App\Services\GymOwnerAccountService;
use App\Services\GymLifecycleService;
use App\Services\GymSubscriptionOnboardingService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GymController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $user = request()->user();
        Gate::authorize('viewAny', Gym::class);
        $query = $user->isSuperAdmin()
            ? Gym::query()
            : $user->gyms()->wherePivot('status', 'active')->getQuery();

        return GymResource::collection(
            $query->orderBy('name')->paginate(min((int) request('per_page', 25), 100))
        );
    }

    public function store(
        StoreGymRequest $request,
        AuditService $audit,
        TenantContext $context,
        GymOwnerAccountService $owners,
        GymSubscriptionOnboardingService $subscriptions,
    ): JsonResponse {
        Gate::authorize('create', Gym::class);
        $data = $request->validated();
        $requestHash = $this->onboardingRequestHash($data);
        $existing = Gym::query()->where('onboarding_idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            $this->assertMatchingOnboardingRequest($existing, $requestHash);

            return $this->onboardingResponse(
                $request,
                $existing,
                $context->run($existing, fn (): ?array => $owners->current()),
                true,
            );
        }

        try {
            [$gym, $ownerAccount, $reused] = DB::transaction(function () use ($data, $requestHash, $request, $audit, $context, $owners, $subscriptions): array {
                // The unique registry key is the concurrency boundary. Checking it
                // again inside the transaction avoids repeat work after a prior
                // request commits between validation and this transaction.
                $existing = Gym::query()
                    ->where('onboarding_idempotency_key', $data['idempotency_key'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    $this->assertMatchingOnboardingRequest($existing, $requestHash);

                    return [
                        $existing,
                        $context->run($existing, fn (): ?array => $owners->current()),
                        true,
                    ];
                }

                $price = SaasPlanPrice::query()->with('plan')->findOrFail($data['subscription']['saas_plan_price_id']);
                $gym = Gym::query()->create([
                    'name' => $data['name'],
                    'legal_name' => $data['legal_name'] ?? null,
                    'slug' => $data['slug'] ?? Str::slug($data['name']).'-'.Str::lower(Str::random(5)),
                    'base_currency' => $data['base_currency'],
                    'country_code' => Str::upper($data['country_code']),
                    'timezone' => $data['timezone'],
                    'status' => GymStatus::Trial,
                    'trial_ends_at' => now()->addDays(14),
                    'onboarding_idempotency_key' => $data['idempotency_key'],
                    'onboarding_request_hash' => $requestHash,
                ]);

                $ownerAccount = $context->run($gym, function () use ($gym, $data, $price, $owners, $subscriptions, $audit, $request): ?array {
                    // RLS requires the newly-created gym context before owner/pivot
                    // or audit writes; the browser's owner fields grant no authority.
                    GymBranch::query()->create([
                        'name' => 'Primary Branch',
                        'code' => 'PRIMARY',
                        'timezone' => $gym->timezone,
                        'status' => 'active',
                        'is_primary' => true,
                    ]);
                    $owner = $data['owner']['create_login_account']
                        ? $owners->create($data['owner'], $request->user(), $request)
                        : null;
                    $subscriptions->create(
                        $gym,
                        $price,
                        $data['subscription']['billing_email'],
                        (int) $data['subscription']['grace_period_days'],
                        $request->user(),
                        $request,
                    );
                    // Subscription synchronization may change the initial gym
                    // status (for example, a zero-day plan is immediately due).
                    $gym->refresh();
                    $audit->record('gym.created', $gym, $request->user(), after: $gym->toArray(), request: $request);

                    return $owner;
                });

                return [$gym, $ownerAccount, false];
            });
        } catch (QueryException $exception) {
            // Concurrent identical requests can both reach INSERT. The registry
            // unique key makes one win; the loser replays that committed result.
            $gym = Gym::query()->where('onboarding_idempotency_key', $data['idempotency_key'])->first();
            if (! $gym) {
                throw $exception;
            }
            $this->assertMatchingOnboardingRequest($gym, $requestHash);
            $ownerAccount = $context->run($gym, fn (): ?array => $owners->current());
            $reused = true;
        }

        return $this->onboardingResponse($request, $gym, $ownerAccount, $reused);
    }

    /** @param array<string, mixed> $data */
    private function onboardingRequestHash(array $data): string
    {
        unset($data['idempotency_key']);

        // HMAC avoids persisting a reversible or offline-guessable fingerprint of
        // the optional temporary owner password while still detecting key misuse.
        return hash_hmac(
            'sha256',
            json_encode($data, JSON_THROW_ON_ERROR),
            (string) config('app.key'),
        );
    }

    private function assertMatchingOnboardingRequest(Gym $gym, string $requestHash): void
    {
        if (! is_string($gym->onboarding_request_hash)
            || ! hash_equals($gym->onboarding_request_hash, $requestHash)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['This request key was already used for different gym onboarding details.'],
            ]);
        }
    }

    /** @param array<string, mixed>|null $ownerAccount */
    private function onboardingResponse(
        StoreGymRequest $request,
        Gym $gym,
        ?array $ownerAccount,
        bool $reused,
    ): JsonResponse {
        return response()->json([
            'data' => (new GymResource($gym))->resolve($request),
            'meta' => [
                'owner_account' => $ownerAccount,
                'idempotency_reused' => $reused,
            ],
        ], $reused ? 200 : 201);
    }

    public function show(TenantContext $context): GymResource
    {
        $gym = $context->gym();
        Gate::authorize('view', $gym);

        return new GymResource($gym);
    }

    public function update(
        UpdateGymRequest $request,
        AuditService $audit,
        TenantContext $context,
    ): GymResource {
        $gym = $context->gym();
        Gate::authorize('update', $gym);
        $before = $gym->toArray();
        $fresh = DB::transaction(function () use ($request, $gym, $audit, $before): Gym {
            $gym->update($request->safe()->except('reason'));
            $fresh = $gym->fresh();
            $audit->record('gym.updated', $fresh, $request->user(), $before, $fresh->toArray(), (string) $request->string('reason'), $request);

            return $fresh;
        });

        return new GymResource($fresh);
    }

    public function destroy(
        DeleteGymRequest $request,
        TenantContext $context,
        GymLifecycleService $lifecycle,
    ): JsonResponse {
        $gym = $context->gym();
        $lifecycle->hardDelete($gym, $request->validated('confirmation'), $request->validated('reason'), $request);

        return response()->json(status: 204);
    }
}
