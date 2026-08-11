<?php

namespace App\Services;

use InvalidArgumentException;

final class TotpService
{
    public const DIGITS = 6;
    public const PERIOD_SECONDS = 30;
    public const WINDOW_STEPS = 1;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        // RFC 4226 recommends a 160-bit shared secret for HMAC-SHA-1.
        return $this->encodeBase32(random_bytes(20));
    }

    public function provisioningUri(string $secret, string $email): string
    {
        $issuer = 'IronCore';
        $label = rawurlencode($issuer.':'.$email);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD_SECONDS,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function matchingCounter(string $secret, string $code, ?int $lastUsedStep = null, ?int $timestamp = null): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $current = intdiv($timestamp ?? now()->getTimestamp(), self::PERIOD_SECONDS);
        foreach ([0, -1, 1] as $offset) {
            $counter = $current + $offset;
            if ($counter < 0 || ($lastUsedStep !== null && $counter <= $lastUsedStep)) {
                continue;
            }

            if (hash_equals($this->codeForCounter($secret, $counter), $code)) {
                return $counter;
            }
        }

        return null;
    }

    public function codeForTimestamp(string $secret, int $timestamp): string
    {
        return $this->codeForCounter($secret, intdiv($timestamp, self::PERIOD_SECONDS));
    }

    public function codeForCounter(string $secret, int $counter): string
    {
        $key = $this->decodeBase32($secret);
        $high = intdiv($counter, 4294967296);
        $low = $counter % 4294967296;
        $digest = hash_hmac('sha1', pack('N2', $high, $low), $key, true);
        $offset = ord($digest[19]) & 0x0f;
        $binary = ((ord($digest[$offset]) & 0x7f) << 24)
            | ((ord($digest[$offset + 1]) & 0xff) << 16)
            | ((ord($digest[$offset + 2]) & 0xff) << 8)
            | (ord($digest[$offset + 3]) & 0xff);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function randomRecoveryCode(): string
    {
        $plain = $this->encodeBase32(random_bytes(10));

        return implode('-', str_split($plain, 4));
    }

    private function encodeBase32(string $bytes): string
    {
        $buffer = 0;
        $bits = 0;
        $encoded = '';

        foreach (unpack('C*', $bytes) as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::BASE32_ALPHABET[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }

        if ($bits > 0) {
            $encoded .= self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }

    private function decodeBase32(string $encoded): string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $encoded) ?? '');
        if ($normalized === '' || preg_match('/[^A-Z2-7]/', $normalized)) {
            throw new InvalidArgumentException('The TOTP secret is not valid Base32.');
        }

        $buffer = 0;
        $bits = 0;
        $decoded = '';

        foreach (str_split($normalized) as $character) {
            $value = strpos(self::BASE32_ALPHABET, $character);
            if ($value === false) {
                throw new InvalidArgumentException('The TOTP secret is not valid Base32.');
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 0xff);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return $decoded;
    }
}
