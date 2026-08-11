<?php

namespace App\Jobs;

use App\Enums\MemberExportStatus;
use App\Models\Gym;
use App\Models\Member;
use App\Models\MemberDataExport;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GenerateMemberDataExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 900;

    public function __construct(
        public readonly string $gymId,
        public readonly string $exportId,
    ) {}

    public function handle(TenantContext $context): void
    {
        $gym = Gym::query()->findOrFail($this->gymId);

        // Long-lived workers must establish and clear both Eloquent and RLS
        // tenant state for every export job.
        $context->run($gym, fn () => $this->generate());
    }

    public function failed(Throwable $exception): void
    {
        $gym = Gym::query()->find($this->gymId);
        if (! $gym) {
            return;
        }

        app(TenantContext::class)->run($gym, function () use ($exception): void {
            MemberDataExport::query()->whereKey($this->exportId)->update([
                'status' => MemberExportStatus::Failed->value,
                'failure_reason' => Str::limit($exception->getMessage(), 500),
                'completed_at' => now(),
            ]);
        });
    }

    private function generate(): void
    {
        $export = MemberDataExport::query()->findOrFail($this->exportId);
        if (in_array($export->status, [MemberExportStatus::Completed, MemberExportStatus::Expired], true)) {
            return;
        }
        $export->update(['status' => MemberExportStatus::Processing, 'started_at' => now()]);
        $member = Member::query()->findOrFail($export->member_id);

        // php://temp spills to disk after its small memory threshold, while each
        // cursor keeps high-volume attendance/payment history out of PHP memory.
        $stream = fopen('php://temp', 'w+b');
        if (! is_resource($stream)) {
            throw new RuntimeException('The member export stream could not be opened.');
        }

        fwrite($stream, '{');
        $this->writeValue($stream, 'schema', 'ironcore.member-export.v1');
        $this->writeValue($stream, 'generated_at', now()->toIso8601String());
        $this->writeValue($stream, 'gym_id', $this->gymId);
        $this->writeValue($stream, 'member', $member->toArray());
        foreach ([
            'memberships' => ['memberships', ['*']],
            'invoices' => ['invoices', ['*']],
            'payments' => ['payments', ['*']],
            'attendance' => ['attendance_records', ['*']],
            'class_bookings' => ['class_bookings', ['*']],
            'trainer_assignments' => ['trainer_member_assignments', ['*']],
            'workout_plans' => ['workout_plans', ['*']],
            'workout_sessions' => ['workout_sessions', ['*']],
            'progress_measurements' => ['member_progress_measurements', ['*']],
            'notification_preferences' => ['notification_preferences', ['*']],
            // Encrypted destinations remain excluded from the generated document.
            'notification_deliveries' => ['notification_deliveries', ['id', 'channel', 'template_key', 'status', 'attempts', 'scheduled_at', 'sent_at', 'created_at']],
        ] as $key => [$table, $columns]) {
            $this->writeRows($stream, $key, $table, $member->id, $columns);
        }
        fseek($stream, -1, SEEK_CUR);
        fwrite($stream, "\n}\n");

        $size = ftell($stream);
        rewind($stream);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        $digest = hash_final($hash);
        rewind($stream);
        $disk = (string) config('filesystems.default');
        $path = "gyms/{$this->gymId}/exports/members/{$export->id}.json";
        if (! Storage::disk($disk)->put($path, $stream, ['visibility' => 'private'])) {
            fclose($stream);
            throw new RuntimeException('The member export could not be stored.');
        }
        fclose($stream);

        $export->update([
            'status' => MemberExportStatus::Completed,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'content_sha256' => $digest,
            'size_bytes' => $size,
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
            'failure_reason' => null,
        ]);

        // A tenant-bound delayed job removes the private object after the
        // seven-day retrieval window without requiring a cross-tenant scan.
        PurgeMemberDataExport::dispatch($this->gymId, $export->id)->delay($export->expires_at);
    }

    /** @param resource $stream */
    private function writeValue($stream, string $key, mixed $value): void
    {
        fwrite($stream, "\n".json_encode($key, JSON_THROW_ON_ERROR).':'.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).',');
    }

    /** @param resource $stream @param array<int, string> $columns */
    private function writeRows($stream, string $key, string $table, string $memberId, array $columns): void
    {
        fwrite($stream, "\n".json_encode($key, JSON_THROW_ON_ERROR).':[');
        $first = true;
        // Explicit gym_id predicates remain mandatory even while forced RLS is active.
        $rows = DB::table($table)
            ->select($columns)
            ->where('gym_id', $this->gymId)
            ->where('member_id', $memberId)
            ->orderBy('created_at')
            ->cursor();
        foreach ($rows as $row) {
            fwrite($stream, ($first ? '' : ',').json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $first = false;
        }
        fwrite($stream, '],');
    }
}
