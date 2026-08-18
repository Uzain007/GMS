<?php

namespace App\Services;

use RuntimeException;

final class StripeProviderException extends RuntimeException
{
    public static function rejected(): self
    {
        // Do not retain the HTTP exception chain: provider bodies, request URLs
        // and customer references must not reach ledger or failed-job evidence.
        return new self('The Stripe provider rejected the request.');
    }
}
