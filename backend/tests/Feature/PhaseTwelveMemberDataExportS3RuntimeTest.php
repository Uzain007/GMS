<?php

namespace Tests\Feature;

use App\Enums\MemberExportStatus;
use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateMemberDataExport;
use App\Jobs\PurgeMemberDataExport;
use App\Models\Gym;
use App\Models\Member;
use App\Models\MemberDataExport;
use App\Models\User;
use App\Tenancy\TenantContext;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseTwelveMemberDataExportS3RuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_and_expiry_execute_against_s3_compatible_storage(): void
    {
        if (! filter_var(env('IRONCORE_S3_RUNTIME_GATE', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('The S3 integration assertions run only in the explicit CI gate.');
        }

        $this->assertSame('s3', config('filesystems.default'));
        $this->createBucket();
        Queue::fake([PurgeMemberDataExport::class]);

        $owner = User::factory()->create();
        $gym = Gym::factory()->create();
        $context = app(TenantContext::class);
        [$member, $export] = $context->run($gym, function () use ($gym, $owner): array {
            $gym->users()->attach($owner, [
                'role' => UserRole::GymOwner->value,
                'status' => 'active',
            ]);
            $member = Member::query()->create([
                'member_number' => 'S3-RUNTIME-1',
                'first_name' => 'Storage',
                'last_name' => 'Runtime',
                'email' => 's3-runtime@example.test',
                'status' => MemberStatus::Active,
                'joined_at' => now(),
            ]);
            $export = MemberDataExport::query()->create([
                'member_id' => $member->id,
                'requested_by' => $owner->id,
                'status' => MemberExportStatus::Queued,
            ]);

            return [$member, $export];
        });

        // Running the production job here proves the AWS SDK, Flysystem adapter,
        // tenant context and real HTTP object-storage boundary work together.
        (new GenerateMemberDataExport($gym->id, $export->id))->handle($context);

        $export = $context->run($gym, fn () => $export->fresh());
        $expectedPath = "gyms/{$gym->id}/exports/members/{$export->id}.json";
        $this->assertSame(MemberExportStatus::Completed, $export->status);
        $this->assertSame('s3', $export->storage_disk);
        $this->assertSame($expectedPath, $export->storage_path);
        $this->assertTrue(Storage::disk('s3')->exists($expectedPath));
        $this->assertSame('private', Storage::disk('s3')->visibility($expectedPath));

        $bytes = Storage::disk('s3')->get($expectedPath);
        $payload = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ironcore.member-export.v1', $payload['schema']);
        $this->assertSame($gym->id, $payload['gym_id']);
        $this->assertSame($member->id, $payload['member']['id']);
        $this->assertSame(hash('sha256', $bytes), $export->content_sha256);
        $this->assertSame(strlen($bytes), $export->size_bytes);
        Queue::assertPushed(PurgeMemberDataExport::class, fn ($job) =>
            $job->gymId === $gym->id && $job->exportId === $export->id
        );

        $context->run($gym, fn () => $export->update(['expires_at' => now()->subSecond()]));
        (new PurgeMemberDataExport($gym->id, $export->id))->handle($context);

        $export = $context->run($gym, fn () => $export->fresh());
        $this->assertSame(MemberExportStatus::Expired, $export->status);
        $this->assertNull($export->storage_disk);
        $this->assertNull($export->storage_path);
        $this->assertFalse(Storage::disk('s3')->exists($expectedPath));
    }

    private function createBucket(): void
    {
        $disk = config('filesystems.disks.s3');
        $client = new S3Client([
            'version' => 'latest',
            'region' => $disk['region'],
            'endpoint' => $disk['endpoint'],
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $disk['key'], 'secret' => $disk['secret']],
        ]);

        // The workflow service is disposable, so creating exactly one bucket
        // keeps the test deterministic and avoids any production-side effect.
        $client->createBucket(['Bucket' => $disk['bucket']]);
    }
}
