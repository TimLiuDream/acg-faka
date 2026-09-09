<?php
declare(strict_types=1);

return [
    'version' => '1.1.0',
    'name' => '易支付聚合支付',
    'author' => 'Dreamer Labs',
    'website' => 'https://pay.yf2.cn/wd1.html',
    'description' => '兼容易支付协议，支持支付宝与微信支付',
    'options' => [
        'alipay' => '支付宝',
        'wxpay' => '微信支付',
    ],
    'callback' => [
        \App\Consts\Pay::IS_SIGN => true,
        \App\Consts\Pay::IS_STATUS => true,
        \App\Consts\Pay::FIELD_STATUS_KEY => 'trade_status',
        \App\Consts\Pay::FIELD_STATUS_VALUE => 'TRADE_SUCCESS',
        \App\Consts\Pay::FIELD_ORDER_KEY => 'out_trade_no',
        \App\Consts\Pay::FIELD_AMOUNT_KEY => 'money',
        \App\Consts\Pay::FIELD_RESPONSE => 'SUCCESS',
    ],
];
