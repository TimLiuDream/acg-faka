<?php
declare(strict_types=1);

namespace App\Pay\GmPay\Impl;

final class Signature implements \App\Pay\Signature
{
    public static function generate(array $data, string $secretKey): string
    {
        unset($data['signature']);
        ksort($data, SORT_STRING);

        $pairs = [];
        foreach ($data as $name => $value) {
            if ($value === '' || $value === null || !is_scalar($value)) {
                continue;
            }
            $pairs[] = $name . '=' . (string)$value;
        }

        return hash_hmac('sha256', implode('&', $pairs), $secretKey);
    }

    public function verification(array $data, array $config): bool
    {
        $signature = strtolower(trim((string)($data['signature'] ?? '')));
        $secretKey = trim((string)($config['secret_key'] ?? ''));
        $expectedPid = trim((string)($config['pid'] ?? ''));
        $callbackPid = trim((string)($data['pid'] ?? ''));

        if (
            $secretKey === ''
            || $expectedPid === ''
            || $callbackPid === ''
            || !hash_equals($expectedPid, $callbackPid)
            || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
        ) {
            return false;
        }

        return hash_equals(self::generate($data, $secretKey), $signature);
    }
}
