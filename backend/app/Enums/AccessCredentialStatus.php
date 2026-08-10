<?php

namespace App\Enums;

enum AccessCredentialStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
