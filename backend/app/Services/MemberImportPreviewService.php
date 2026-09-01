<?php

namespace App\Services;

use App\Enums\MemberStatus;
use App\Models\GymBranch;
use App\Models\Member;
use App\Models\MemberImport;
use App\Models\MembershipPlan;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Shuchkin\SimpleXLS;
use Shuchkin\SimpleXLSX;
use Throwable;

class MemberImportPreviewService
{
    public const HEADERS = [
        'first_name', 'last_name', 'email', 'phone', 'member_number',
        'branch_code', 'membership_plan_code', 'status', 'date_of_birth', 'joined_at',
    ];

    public const REQUIRED_HEADERS = ['first_name', 'last_name', 'email', 'phone'];

    /**
     * @return array{summary: array<string, int>, errors: list<array{line:int|null,category:string,message:string}>, rows:list<array<string,mixed>>}
     */
    public function analyse(MemberImport $import, bool $includeRows = false): array
    {
        $summary = array_fill_keys([
            'total_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows',
            'missing_required_fields', 'invalid_email', 'invalid_phone',
            'invalid_branch_references', 'invalid_membership_references',
        ], 0);
        $errors = [];
        $validRows = [];
        $seenEmails = [];
        $seenNumbers = [];
        $existingEmails = Member::query()->whereNotNull('email')->pluck('email')
            ->mapWithKeys(fn ($email) => [mb_strtolower((string) $email) => true])->all();
        $existingNumbers = Member::query()->pluck('member_number')
            ->mapWithKeys(fn ($number) => [mb_strtoupper((string) $number) => true])->all();
        $branches = GymBranch::query()->pluck('id', 'code')
            ->mapWithKeys(fn ($id, $code) => [mb_strtoupper((string) $code) => (string) $id])->all();
        $plans = MembershipPlan::query()->get()->keyBy(fn (MembershipPlan $plan) => mb_strtoupper($plan->code));

        $rows = $this->rows($import);
        $headerRow = $rows->current();
        if (! is_array($headerRow)) {
            throw new RuntimeException('The import file has no header row.');
        }
        $headers = array_map(fn ($value) => Str::snake(trim((string) $value, "\xEF\xBB\xBF \t\n\r\0\x0B")), $headerRow);
        foreach (self::REQUIRED_HEADERS as $required) {
            if (! in_array($required, $headers, true)) {
                throw new RuntimeException("The header must contain {$required}.");
            }
        }

        $rows->next();
        $line = 1;
        while ($rows->valid()) {
            $line++;
            $row = $rows->current();
            $rows->next();
            if (! is_array($row) || $this->isEmptyRow($row)) {
                continue;
            }
            if (++$summary['total_rows'] > 10000) {
                throw new RuntimeException('A member import may contain at most 10,000 data rows.');
            }
            $record = array_combine($headers, array_pad(array_slice($row, 0, count($headers)), count($headers), null));
            $category = null;
            $message = null;
            $mapped = null;

            try {
                $mapped = $this->mapRow($record, $branches, $plans);
                $emailKey = $mapped['email'];
                $numberKey = mb_strtoupper($mapped['member_number']);
                if (isset($seenEmails[$emailKey]) || isset($seenNumbers[$numberKey]) || isset($existingEmails[$emailKey]) || isset($existingNumbers[$numberKey])) {
                    $category = 'duplicate_rows';
                    $message = 'Email or member number already exists in this gym or import file.';
                }
                $seenEmails[$emailKey] = true;
                $seenNumbers[$numberKey] = true;
            } catch (MemberImportRowException $exception) {
                $category = $exception->category;
                $message = $exception->getMessage();
            }

            if ($category) {
                $summary['invalid_rows']++;
                $summary[$category]++;
                if (count($errors) < 100) {
                    $errors[] = ['line' => $line, 'category' => $category, 'message' => $message];
                }
                continue;
            }

            $summary['valid_rows']++;
            if ($includeRows && $mapped) {
                $validRows[] = $mapped;
            }
        }

        return ['summary' => $summary, 'errors' => $errors, 'rows' => $validRows];
    }

    /** @return Generator<int, array<int, mixed>> */
    private function rows(MemberImport $import): Generator
    {
        $stream = Storage::disk($import->storage_disk)->readStream($import->storage_path);
        if (! is_resource($stream)) {
            throw new RuntimeException('The import file could not be opened.');
        }
        $extension = mb_strtolower(pathinfo($import->original_name, PATHINFO_EXTENSION));
        $temporary = tempnam(sys_get_temp_dir(), 'ironcore-import-');
        if ($temporary === false) {
            fclose($stream);
            throw new RuntimeException('Temporary import storage is unavailable.');
        }
        $target = fopen($temporary, 'wb');
        if (! is_resource($target)) {
            fclose($stream);
            throw new RuntimeException('Temporary import storage is unavailable.');
        }
        stream_copy_to_stream($stream, $target);
        fclose($stream);
        fclose($target);

        try {
            if ($extension === 'xlsx') {
                $book = SimpleXLSX::parseFile($temporary);
                if (! $book) throw new RuntimeException('The XLSX workbook could not be read.');
                yield from $book->readRows();
                return;
            }
            if ($extension === 'xls') {
                $book = SimpleXLS::parseFile($temporary);
                if (! $book) throw new RuntimeException('The XLS workbook could not be read.');
                yield from $book->readRows();
                return;
            }
            $csv = fopen($temporary, 'rb');
            if (! is_resource($csv)) throw new RuntimeException('The CSV file could not be read.');
            try {
                while (($row = fgetcsv($csv)) !== false) yield $row;
            } finally {
                fclose($csv);
            }
        } finally {
            @unlink($temporary);
        }
    }

    /** @param array<string,string|null> $record @param array<string,string> $branches @param mixed $plans */
    private function mapRow(array $record, array $branches, $plans): array
    {
        $firstName = trim((string) ($record['first_name'] ?? ''));
        $lastName = trim((string) ($record['last_name'] ?? ''));
        $email = mb_strtolower(trim((string) ($record['email'] ?? '')));
        $phone = trim((string) ($record['phone'] ?? ''));
        if ($firstName === '' || $lastName === '' || $email === '' || $phone === '') {
            throw new MemberImportRowException('missing_required_fields', 'First name, last name, email and phone are required.');
        }
        if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            throw new MemberImportRowException('missing_required_fields', 'First and last names must be at most 100 characters.');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new MemberImportRowException('invalid_email', 'Email must be a valid address.');
        }
        if (mb_strlen($phone) > 40 || ! preg_match('/^\+?[0-9 ()-]{7,40}$/', $phone)) {
            throw new MemberImportRowException('invalid_phone', 'Phone must contain 7–40 valid phone characters.');
        }
        $status = MemberStatus::tryFrom(mb_strtolower(trim((string) ($record['status'] ?? MemberStatus::Lead->value))));
        if (! $status) throw new MemberImportRowException('missing_required_fields', 'Member status is invalid.');

        $memberNumber = trim((string) ($record['member_number'] ?? '')) ?: 'MBR-'.Str::upper((string) Str::ulid());
        if (mb_strlen($memberNumber) > 50 || ! preg_match('/^[A-Za-z0-9_-]+$/', $memberNumber)) {
            throw new MemberImportRowException('duplicate_rows', 'Member number must use letters, numbers, dashes or underscores.');
        }
        $branchCode = mb_strtoupper(trim((string) ($record['branch_code'] ?? '')));
        if ($branchCode !== '' && ! isset($branches[$branchCode])) {
            throw new MemberImportRowException('invalid_branch_references', 'Branch code does not exist in this gym.');
        }
        $planCode = mb_strtoupper(trim((string) ($record['membership_plan_code'] ?? '')));
        $plan = $planCode !== '' ? $plans->get($planCode) : null;
        if ($planCode !== '' && (! $plan || $plan->status->value !== 'active')) {
            throw new MemberImportRowException('invalid_membership_references', 'Membership plan code is missing or inactive in this gym.');
        }
        $branchId = $branchCode !== '' ? $branches[$branchCode] : null;
        if ($plan?->branch_id && $branchId && $plan->branch_id !== $branchId) {
            throw new MemberImportRowException('invalid_membership_references', 'Membership plan is not valid for the selected branch.');
        }

        return [
            'id' => (string) Str::uuid(), 'home_branch_id' => $branchId, 'member_number' => $memberNumber,
            'first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'phone' => $phone,
            'date_of_birth' => $this->nullableDate($record['date_of_birth'] ?? null, 'Date of birth'),
            'status' => $status->value,
            'joined_at' => $this->nullableDate($record['joined_at'] ?? null, 'Joined date'),
            '_plan_id' => $plan?->id,
        ];
    }

    private function nullableDate(mixed $value, string $label): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $candidate = mb_substr($value, 0, 10);
        try { $date = CarbonImmutable::createFromFormat('!Y-m-d', $candidate); } catch (Throwable) { $date = null; }
        if (! $date || $date->format('Y-m-d') !== $candidate) {
            throw new MemberImportRowException('missing_required_fields', "{$label} must use YYYY-MM-DD.");
        }
        return $candidate;
    }

    /** @param list<mixed> $row */
    private function isEmptyRow(array $row): bool
    {
        return count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0;
    }
}

class MemberImportRowException extends RuntimeException
{
    public function __construct(public readonly string $category, string $message)
    {
        parent::__construct($message);
    }
}
