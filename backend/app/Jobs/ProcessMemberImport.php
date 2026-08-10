<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Enums\MemberStatus;
use App\Models\Gym;
use App\Models\GymBranch;
use App\Models\MemberImport;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProcessMemberImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 900;

    public function __construct(
        public readonly string $gymId,
        public readonly string $importId,
    ) {}

    public function handle(TenantContext $context): void
    {
        $gym = Gym::query()->findOrFail($this->gymId);

        // Queue workers are long-lived, so every job establishes and clears its
        // own RLS/Eloquent tenant context rather than trusting worker history.
        $context->run($gym, fn () => $this->processImport());
    }

    public function failed(Throwable $exception): void
    {
        $gym = Gym::query()->find($this->gymId);
        if (! $gym) {
            return;
        }

        app(TenantContext::class)->run($gym, function () use ($exception): void {
            MemberImport::query()->whereKey($this->importId)->update([
                'status' => ImportStatus::Failed->value,
                'completed_at' => now(),
                // Persist a bounded message, never a stack trace or CSV contents.
                'errors' => [['line' => null, 'message' => Str::limit($exception->getMessage(), 500)]],
            ]);
        });
    }

    private function processImport(): void
    {
        $import = MemberImport::query()->findOrFail($this->importId);
        $import->update(['status' => ImportStatus::Processing, 'started_at' => now()]);

        $stream = Storage::disk($import->storage_disk)->readStream($import->storage_path);
        if (! is_resource($stream)) {
            throw new RuntimeException('The import file could not be opened.');
        }

        try {
            $rawHeader = fgetcsv($stream);
            if (! is_array($rawHeader)) {
                throw new RuntimeException('The import file has no CSV header.');
            }

            $headers = array_map(
                fn ($value) => Str::snake(trim((string) $value, "\xEF\xBB\xBF \t\n\r\0\x0B")),
                $rawHeader,
            );
            foreach (['first_name', 'last_name'] as $required) {
                if (! in_array($required, $headers, true)) {
                    throw new RuntimeException("The CSV header must contain {$required}.");
                }
            }

            // Resolve branch codes once per import instead of querying every row.
            $branchIds = GymBranch::query()->pluck('id', 'code')
                ->mapWithKeys(fn ($id, $code) => [mb_strtoupper((string) $code) => $id])
                ->all();

            $line = 1;
            $processed = 0;
            $success = 0;
            $failure = 0;
            $errors = [];
            $batch = [];

            while (($row = fgetcsv($stream)) !== false) {
                $line++;
                if ($this->isEmptyRow($row)) {
                    continue;
                }

                $processed++;
                $record = array_combine(
                    $headers,
                    array_pad(array_slice($row, 0, count($headers)), count($headers), null),
                );

                try {
                    $batch[] = $this->mapRow($record, $branchIds);
                } catch (RuntimeException $exception) {
                    $failure++;
                    if (count($errors) < 100) {
                        $errors[] = ['line' => $line, 'message' => $exception->getMessage()];
                    }
                }

                if (count($batch) >= 500) {
                    [$inserted, $duplicates] = $this->flush($batch);
                    $success += $inserted;
                    $failure += $duplicates;
                    $batch = [];
                    $this->saveProgress($import, $processed, $success, $failure, $errors);
                }
            }

            if ($batch) {
                [$inserted, $duplicates] = $this->flush($batch);
                $success += $inserted;
                $failure += $duplicates;
            }

            $import->update([
                'status' => ImportStatus::Completed,
                'total_rows' => $processed,
                'processed_rows' => $processed,
                'success_rows' => $success,
                'failure_rows' => $failure,
                'errors' => $errors ?: null,
                'completed_at' => now(),
            ]);
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, string|null> $record @param array<string, string> $branchIds */
    private function mapRow(array $record, array $branchIds): array
    {
        $firstName = trim((string) ($record['first_name'] ?? ''));
        $lastName = trim((string) ($record['last_name'] ?? ''));
        if ($firstName === '' || mb_strlen($firstName) > 100 || $lastName === '' || mb_strlen($lastName) > 100) {
            throw new RuntimeException('First and last names are required and must be at most 100 characters.');
        }

        $email = mb_strtolower(trim((string) ($record['email'] ?? '')));
        if ($email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254)) {
            throw new RuntimeException('Email is invalid.');
        }

        $status = MemberStatus::tryFrom(trim((string) ($record['status'] ?? MemberStatus::Lead->value)));
        if (! $status) {
            throw new RuntimeException('Member status is invalid.');
        }

        $memberNumber = trim((string) ($record['member_number'] ?? ''));
        $memberNumber = $memberNumber !== '' ? $memberNumber : 'MBR-'.Str::upper((string) Str::ulid());
        if (mb_strlen($memberNumber) > 50 || ! preg_match('/^[A-Za-z0-9_-]+$/', $memberNumber)) {
            throw new RuntimeException('Member number must use letters, numbers, dashes or underscores.');
        }

        $branchCode = mb_strtoupper(trim((string) ($record['branch_code'] ?? '')));
        if ($branchCode !== '' && ! isset($branchIds[$branchCode])) {
            throw new RuntimeException('Branch code does not exist in this gym.');
        }

        $now = now();
        return [
            'id' => (string) Str::uuid(),
            // Explicit gym_id plus active RLS makes this bulk path tenant-safe.
            'gym_id' => app(TenantContext::class)->id(),
            'home_branch_id' => $branchCode !== '' ? $branchIds[$branchCode] : null,
            'user_id' => null,
            'member_number' => $memberNumber,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email !== '' ? $email : null,
            'phone' => $this->nullableString($record['phone'] ?? null, 40, 'Phone'),
            'date_of_birth' => $this->nullableDate($record['date_of_birth'] ?? null, 'Date of birth'),
            'status' => $status->value,
            'joined_at' => $this->nullableDate($record['joined_at'] ?? null, 'Joined date'),
            'archived_at' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param list<array<string, mixed>> $batch @return array{int, int} */
    private function flush(array $batch): array
    {
        // Batches of 500 bound memory and round trips; RLS still checks each row.
        $inserted = DB::table('members')->insertOrIgnore($batch);
        return [$inserted, count($batch) - $inserted];
    }

    private function saveProgress(
        MemberImport $import,
        int $processed,
        int $success,
        int $failure,
        array $errors,
    ): void {
        $import->update([
            'processed_rows' => $processed,
            'success_rows' => $success,
            'failure_rows' => $failure,
            'errors' => $errors ?: null,
        ]);
    }

    private function nullableString(mixed $value, int $max, string $label): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new RuntimeException("{$label} is too long.");
        }
        return $value;
    }

    private function nullableDate(mixed $value, string $label): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            throw new RuntimeException("{$label} must use YYYY-MM-DD.");
        }
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException("{$label} must use YYYY-MM-DD.");
        }
        return $value;
    }

    /** @param list<string|null> $row */
    private function isEmptyRow(array $row): bool
    {
        return count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0;
    }
}
