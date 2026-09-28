<?php

namespace App\Support;

use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

final class TenantClock
{
    public static function timezone(): string
    {
        return app(TenantContext::class)->gym()->timezone;
    }

    public static function businessDate(): string
    {
        // Tenant business rules must not move to the next/previous day when
        // the application server crosses UTC midnight.
        return CarbonImmutable::now(self::timezone())->toDateString();
    }

    public static function startOfToday(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    public static function localDate(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, self::timezone());
    }
}
