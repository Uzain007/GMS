<?php

namespace App\Services;

use App\Models\Member;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MemberCodeService
{
    private const MAX_CODE = 999999;

    public function __construct(private readonly TenantContext $tenant) {}

    public function generate(): string
    {
        return $this->reserve(1)[0];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    public function assignToImportRows(array $rows): array
    {
        $codes = $this->reserve(count($rows));

        return array_map(function (array $row, int $index) use ($codes): array {
            $row['member_code'] = $codes[$index];
            return $row;
        }, $rows, array_keys($rows));
    }

    /** @return list<string> */
    private function reserve(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $gymId = $this->tenant->id();
        if (DB::connection()->getDriverName() === 'pgsql' && DB::transactionLevel() > 0) {
            // Generation is serialized only within this gym. The tenant-leading
            // unique index remains the final concurrency boundary at insert time.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["ironcore-member-code:{$gymId}"]);
        }

        $reserved = [];
        $attempts = 0;

        while (count($reserved) < $count && $attempts++ < 40) {
            $candidates = [];
            $target = min(($count - count($reserved)) * 3, 1500);
            while (count($candidates) < $target) {
                $code = str_pad((string) random_int(0, self::MAX_CODE), 6, '0', STR_PAD_LEFT);
                $candidates[$code] = true;
            }

            $existing = Member::query()->whereIn('member_code', array_keys($candidates))
                ->pluck('member_code')->all();
            foreach ($existing as $code) {
                unset($candidates[$code]);
            }
            foreach (array_keys($candidates) as $code) {
                if (! isset($reserved[$code])) {
                    $reserved[$code] = true;
                }
                if (count($reserved) === $count) {
                    break;
                }
            }
        }

        if (count($reserved) !== $count) {
            throw new RuntimeException('No available six-digit member code could be allocated for this gym.');
        }

        return array_keys($reserved);
    }
}
