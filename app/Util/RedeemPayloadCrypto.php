<?php
declare(strict_types=1);

namespace App\Util;

/** Encrypt short-lived account session payloads before they enter the queue. */
final class RedeemPayloadCrypto
{
    public const PREFIX = 'ACGR1:';

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('兑换凭证加密失败');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): ?string
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            return null;
        }

        $blob = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($blob === false || strlen($blob) < 29) {
            return null;
        }

        $plain = openssl_decrypt(
            substr($blob, 28),
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            substr($blob, 0, 12),
            substr($blob, 12, 16)
        );
        return $plain === false ? null : $plain;
    }

    private static function key(): string
    {
        return hash_hmac('sha256', 'card-redeem-session-v1', RequestLogCrypto::key(), true);
    }
}
