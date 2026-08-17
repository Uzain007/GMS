#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Enums\GymStatus;
use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\Member;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

if (getenv('CI') !== 'true'
    || getenv('IRONCORE_RUNTIME_GATE') !== 'true'
    || getenv('IRONCORE_LOAD_GATE') !== 'true') {
    fwrite(STDERR, "Refusing to create load fixtures outside the explicit CI load gate.\n");
    exit(64);
}

$githubEnvironment = getenv('GITHUB_ENV');
if (! is_string($githubEnvironment) || $githubEnvironment === '') {
    fwrite(STDERR, "The CI environment handoff file is unavailable.\n");
    exit(64);
}

$root = dirname(__DIR__, 2);
require $root.'/backend/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $application */
$application = require $root.'/backend/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$gym = Gym::query()->create([
    'name' => 'IronCore Synthetic Load Gym',
    'slug' => 'ironcore-synthetic-load-gym',
    'base_currency' => Currency::GBP,
    'country_code' => 'GB',
    'timezone' => 'Europe/London',
    'status' => GymStatus::Active,
    'settings' => ['fixture' => 'ci-load-gate'],
]);
$blockedGym = Gym::query()->create([
    'name' => 'IronCore Synthetic Isolation Gym',
    'slug' => 'ironcore-synthetic-isolation-gym',
    'base_currency' => Currency::GBP,
    'country_code' => 'GB',
    'timezone' => 'Europe/London',
    'status' => GymStatus::Active,
    'settings' => ['fixture' => 'ci-load-gate'],
]);

$tokens = [];
$tenant = $application->make(TenantContext::class);
$tenant->run($gym, function () use ($gym, &$tokens): void {
    // Multiple disposable operators preserve the real per-user/per-gym report
    // throttle while still generating concurrent pressure on one cached tenant.
    for ($index = 1; $index <= 16; $index++) {
        $user = User::query()->create([
            'name' => "Synthetic Load Operator {$index}",
            'email' => "load-operator-{$index}@example.test",
            'email_verified_at' => now(),
            'password' => Hash::make(bin2hex(random_bytes(24))),
        ]);
        $gym->users()->attach($user, [
            'role' => UserRole::GymOwner->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $tokens[] = $user->createToken(
            "ci-load-operator-{$index}",
            ['app:use'],
            now()->addMinutes(10),
        )->plainTextToken;
    }

    $members = [];
    for ($index = 1; $index <= 500; $index++) {
        $members[] = [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'gym_id' => (string) $gym->getKey(),
            'member_number' => sprintf('LOAD-%04d', $index),
            'first_name' => 'Synthetic',
            'last_name' => "Member {$index}",
            'status' => MemberStatus::Active->value,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    Member::query()->insert($members);
});

$tokenJson = json_encode($tokens, JSON_THROW_ON_ERROR);
$environment = implode("\n", [
    'IRONCORE_API_URL=http://127.0.0.1:8000',
    'IRONCORE_GYM_ID='.(string) $gym->getKey(),
    'IRONCORE_BLOCKED_GYM_ID='.(string) $blockedGym->getKey(),
    'IRONCORE_ACCESS_TOKENS='.$tokenJson,
    'IRONCORE_REPORT_FROM='.now($gym->timezone)->toDateString(),
    'IRONCORE_REPORT_TO='.now($gym->timezone)->toDateString(),
    '',
]);

if (file_put_contents($githubEnvironment, $environment, FILE_APPEND | LOCK_EX) === false) {
    throw new RuntimeException('Unable to hand the synthetic fixture to the CI runner.');
}

fwrite(STDOUT, "Prepared one 500-member synthetic tenant, one isolated tenant and 16 expiring CI operators.\n");
