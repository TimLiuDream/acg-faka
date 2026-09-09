<?php
declare(strict_types=1);

namespace App\Pay\Epay\Impl;

final class Signature implements \App\Pay\Signature
{
    public static function generateSignature(array $data, string $key): string
    {
        unset($data['sign'], $data['sign_type']);
        ksort($data, SORT_STRING);

        $pairs = [];
        foreach ($data as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $pairs[] = $name . '=' . (string)$value;
        }

        return md5(implode('&', $pairs) . $key);
    }

    public function verification(array $data, array $config): bool
    {
        $sign = strtolower(trim((string)($data['sign'] ?? '')));
        $key = trim((string)($config['key'] ?? ''));
        if ($sign === '' || preg_match('/^[a-f0-9]{32}$/D', $sign) !== 1 || $key === '') {
            return false;
        }

        $expected = self::generateSignature($data, $key);
        return hash_equals($expected, $sign);
    }
}
