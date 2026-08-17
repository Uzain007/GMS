<?php

namespace App\Services\Notifications;

use RuntimeException;

final class NotificationProviderException extends RuntimeException
{
    public static function notConfigured(string $channel): self
    {
        return new self("The {$channel} notification adapter is not configured.");
    }

    public static function rejected(): self
    {
        // Do not retain the transport exception as `previous`: Laravel stores
        // failed-job exception chains, which could otherwise capture provider
        // response bodies, endpoint details or a member destination.
        return new self('The notification provider rejected the delivery.');
    }
}
