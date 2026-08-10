<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GymStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGymRequest;
use App\Http\Requests\UpdateGymRequest;
use App\Http\Resources\GymResource;
use App\Models\Gym;
use App\Models\User;
use App\Services\AuditService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
    ): GymResource
    {
        Gate::authorize('create', Gym::class);
        $gym = DB::transaction(function () use ($request, $audit, $context): Gym {
            $data = $request->validated();
            $gym = Gym::query()->create([
                'name' => $data['name'],
                'legal_name' => $data['legal_name'] ?? null,
                'slug' => $data['slug'] ?? Str::slug($data['name']).'-'.Str::lower(Str::random(5)),
                'base_currency' => $data['base_currency'],
                'country_code' => Str::upper($data['country_code']),
                'timezone' => $data['timezone'],
                'status' => GymStatus::Trial,
                'trial_ends_at' => now()->addDays(14),
            ]);

            $owner = User::query()->firstOrCreate(
                ['email' => mb_strtolower($data['owner']['email'])],
                ['name' => $data['owner']['name'], 'password' => Hash::make(Str::password(32))]
            );
            $context->run($gym, function () use ($gym, $owner, $audit, $request): void {
                // RLS requires the newly-created gym context before pivot/audit writes.
                $gym->users()->syncWithoutDetaching([
                    $owner->id => ['role' => UserRole::GymOwner->value, 'status' => 'active', 'joined_at' => now()],
                ]);
                $audit->record('gym.created', $gym, $request->user(), after: $gym->toArray(), request: $request);
            });

            return $gym;
        });

        return new GymResource($gym);
    }

    public function show(Gym $gym): GymResource
    {
        Gate::authorize('view', $gym);
        return new GymResource($gym);
    }

    public function update(UpdateGymRequest $request, Gym $gym, AuditService $audit): GymResource
    {
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
}
