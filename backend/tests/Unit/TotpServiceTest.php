<?php

namespace Tests\Unit;

use App\Services\TotpService;
use Tests\TestCase;

class TotpServiceTest extends TestCase
{
    public function test_it_matches_the_six_digit_rfc_6238_vector_and_rejects_replay(): void
    {
        $totp = app(TotpService::class);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame('287082', $totp->codeForTimestamp($secret, 59));
        $this->assertSame(1, $totp->matchingCounter($secret, '287082', null, 59));
        $this->assertNull($totp->matchingCounter($secret, '287082', 1, 59));
    }
}
