<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 支付插件可选的主动查单能力。
 */
interface PaymentStatusQuery
{
    /**
     * @return array{paid: bool, trade_no: string, amount: string|null}
     */
    public function queryPayment(): array;
}
