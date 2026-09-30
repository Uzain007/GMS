<?php

namespace App\Services;

use App\Enums\StaffStatus;
use App\Enums\UserRole;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\TenantClock;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TrainerLifecycleService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditService $audit,
        private readonly StaffInvitationService $roleGuard,
    ) {}

    /** @return array{profile: StaffProfile, setup_token: ?string, existing_account: bool} */
    public function create(array $data, User $actor, Request $request, ?UploadedFile $image = null): array
    {
        $role = UserRole::from($data['role']);
        $this->roleGuard->ensureRoleCanBeGranted($actor, $role->value);
        $email = mb_strtolower($data['email']);
        $storedImage = null;

        try {
            return DB::transaction(function () use ($data, $actor, $request, $image, $email, $role, &$storedImage): array {
                $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
                $existingAccount = $user !== null;

                if ($user?->isSuperAdmin()) {
                    throw ValidationException::withMessages([
                        'email' => ['A platform administrator account cannot be added as gym staff.'],
                    ]);
                }

                if ($user) {
                    $alreadyLinked = DB::table('gym_user')
                        ->where('gym_id', $this->tenant->id())
                        ->where('user_id', $user->getKey())
                        ->exists();
                    if ($alreadyLinked) {
                        throw ValidationException::withMessages([
                            'email' => ['This account already has a role in this gym.'],
                        ]);
                    }
                } else {
                    // A random unusable-by-humans password keeps the account closed
                    // until the one-time Laravel reset token is used for setup.
                    $user = User::query()->create([
                        'name' => $data['name'],
                        'email' => $email,
                        'password' => Str::random(64),
                    ]);
                }

                $now = now();
                DB::table('gym_user')->insert([
                    'gym_id' => $this->tenant->id(),
                    'user_id' => $user->getKey(),
                    'role' => $role->value,
                    'status' => $data['status'],
                    'joined_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $profile = StaffProfile::query()->create([
                    'user_id' => $user->getKey(),
                    'home_branch_id' => $data['home_branch_id'],
                    'display_name' => $data['name'],
                    'contact_email' => $email,
                    'phone' => $data['phone'],
                    'employee_number' => $data['employee_number'] ?? $this->nextEmployeeNumber(),
                    'job_title' => $data['job_title'] ?? match ($role) {
                        UserRole::GymManager => 'Gym Manager',
                        UserRole::Receptionist => 'Receptionist',
                        default => 'Trainer',
                    },
                    'status' => StaffStatus::from($data['status']),
                    'hired_at' => $now->toDateString(),
                ]);

                // Repeating gym_id in the pivot preserves the composite tenant
                // foreign-key boundary used by PostgreSQL RLS and branch queries.
                DB::table('staff_profile_branch')->insert([
                    'gym_id' => $this->tenant->id(),
                    'staff_profile_id' => $profile->getKey(),
                    'branch_id' => $data['home_branch_id'],
                    'is_primary' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($image) {
                    $storedImage = $this->storeImageObject($profile, $image);
                    $profile->update($storedImage);
                }

                $profile->setAttribute('tenant_role', $role->value);
                $this->audit->record(
                    'staff.employee_created',
                    $profile,
                    $actor,
                    after: $this->auditSnapshot($profile),
                    request: $request,
                );

                // Existing users keep their password; only a brand-new identity
                // receives one setup secret, and its stored form remains hashed.
                $setupToken = $existingAccount ? null : Password::broker()->createToken($user);

                return [
                    'profile' => $profile->load('user'),
                    'setup_token' => $setupToken,
                    'existing_account' => $existingAccount,
                ];
            });
        } catch (\Throwable $exception) {
            if ($storedImage) {
                Storage::disk($storedImage['profile_image_disk'])->delete($storedImage['profile_image_path']);
            }
            throw $exception;
        }
    }

    public function replaceImage(StaffProfile $profile, UploadedFile $image, User $actor, string $reason, Request $request): StaffProfile
    {
        $this->roleGuard->ensureProfileCanBeManaged($actor, (string) $profile->tenant_role);
        $previous = $this->imageLocator($profile);
        $stored = $this->storeImageObject($profile, $image);

        try {
            $fresh = DB::transaction(function () use ($profile, $stored, $actor, $reason, $request): StaffProfile {
                $before = $this->auditSnapshot($profile);
                $profile->update($stored);
                $fresh = $profile->fresh('user');
                $fresh->setAttribute('tenant_role', $profile->tenant_role);
                $this->audit->record('staff.profile_image_updated', $fresh, $actor, $before, $this->auditSnapshot($fresh), $reason, $request);

                return $fresh;
            });
        } catch (\Throwable $exception) {
            Storage::disk($stored['profile_image_disk'])->delete($stored['profile_image_path']);
            throw $exception;
        }

        $this->deleteImageObject($previous);

        return $fresh;
    }

    public function removeImage(StaffProfile $profile, User $actor, string $reason, Request $request): StaffProfile
    {
        $this->roleGuard->ensureProfileCanBeManaged($actor, (string) $profile->tenant_role);
        $previous = $this->imageLocator($profile);

        $fresh = DB::transaction(function () use ($profile, $actor, $reason, $request): StaffProfile {
            $before = $this->auditSnapshot($profile);
            $profile->update([
                'profile_image_disk' => null,
                'profile_image_path' => null,
                'profile_image_mime' => null,
                'profile_image_size' => null,
            ]);
            $fresh = $profile->fresh('user');
            $fresh->setAttribute('tenant_role', $profile->tenant_role);
            $this->audit->record('staff.profile_image_removed', $fresh, $actor, $before, $this->auditSnapshot($fresh), $reason, $request);

            return $fresh;
        });

        $this->deleteImageObject($previous);

        return $fresh;
    }

    public function terminate(StaffProfile $profile, User $actor, string $reason, Request $request): StaffProfile
    {
        $this->roleGuard->ensureProfileCanBeManaged($actor, (string) $profile->tenant_role);
        if ((string) $profile->user_id === (string) $actor->getKey()) {
            throw ValidationException::withMessages([
                'staff' => ['You cannot remove your own gym access.'],
            ]);
        }

        return DB::transaction(function () use ($profile, $actor, $reason, $request): StaffProfile {
            $locked = StaffProfile::query()->with('user')->lockForUpdate()->findOrFail($profile->getKey());
            if ($locked->status === StaffStatus::Inactive) {
                throw ValidationException::withMessages([
                    'staff' => ['This employee is already inactive.'],
                ]);
            }

            $before = $this->auditSnapshot($profile);
            $locked->update([
                'status' => StaffStatus::Inactive,
                'terminated_at' => TenantClock::businessDate(),
                'permissions' => [],
            ]);

            // Revoke only this tenant assignment. Platform-wide tokens and
            // sessions may still be valid for a separate gym membership, while
            // tenant middleware immediately rejects this inactive pivot.
            DB::table('gym_user')
                ->where('gym_id', $this->tenant->id())
                ->where('user_id', $locked->user_id)
                ->update(['status' => 'inactive', 'updated_at' => now()]);

            $fresh = $locked->fresh('user');
            $fresh->setAttribute('tenant_role', $profile->tenant_role);
            $this->audit->record(
                'staff.terminated',
                $fresh,
                $actor,
                $before,
                $this->auditSnapshot($fresh),
                $reason,
                $request,
            );

            return $fresh;
        });
    }
    /** @return array{profile_image_disk: string, profile_image_path: string, profile_image_mime: string, profile_image_size: int} */
    private function storeImageObject(StaffProfile $profile, UploadedFile $image): array
    {
        $disk = (string) config('filesystems.default');
        $extension = $image->guessExtension() ?: 'jpg';
        $directory = "gyms/{$this->tenant->id()}/staff/{$profile->getKey()}/profile-image";
        $path = Storage::disk($disk)->putFileAs($directory, $image, Str::uuid().'.'.$extension);
        if (! $path) {
            throw ValidationException::withMessages(['profile_image' => ['The profile image could not be stored.']]);
        }

        return [
            'profile_image_disk' => $disk,
            'profile_image_path' => $path,
            'profile_image_mime' => (string) $image->getMimeType(),
            'profile_image_size' => (int) $image->getSize(),
        ];
    }

    /** @return array{disk: string, path: string}|null */
    private function imageLocator(StaffProfile $profile): ?array
    {
        if (! $profile->profile_image_disk || ! $profile->profile_image_path) {
            return null;
        }

        return ['disk' => $profile->profile_image_disk, 'path' => $profile->profile_image_path];
    }

    /** @param array{disk: string, path: string}|null $locator */
    private function deleteImageObject(?array $locator): void
    {
        if ($locator) {
            Storage::disk($locator['disk'])->delete($locator['path']);
        }
    }

    private function nextEmployeeNumber(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $number = 'TRN-'.Str::upper(Str::random(10));
            if (! StaffProfile::query()->where('employee_number', $number)->exists()) {
                return $number;
            }
        }
        throw new \RuntimeException('Unable to allocate a unique trainer number.');
    }

    private function auditSnapshot(StaffProfile $profile): array
    {
        return [
            'id' => $profile->getKey(),
            'user_id' => $profile->user_id,
            'role' => $profile->tenant_role ?? UserRole::Trainer->value,
            'display_name' => $profile->professionalName(),
            'contact_email' => $profile->professionalEmail(),
            'phone' => $profile->phone,
            'home_branch_id' => $profile->home_branch_id,
            'employee_number' => $profile->employee_number,
            'status' => $profile->status->value,
            'has_profile_image' => $profile->profile_image_path !== null,
        ];
    }
}
