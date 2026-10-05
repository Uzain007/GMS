<?php

namespace App\Exceptions;

use RuntimeException;

final class SaasBillingReminderDeliveryException extends RuntimeException
{
    public static function rejected(): self
    {
        // Failed-job storage serializes exception chains. Never retain the raw
        // mail transport exception, which may contain credentials or recipients.
        return new self('SaaS billing reminder delivery failed.');
    }
}
