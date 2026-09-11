<?php
declare(strict_types=1);

return [
    'version' => '1.0.0',
    'name' => 'GM Pay 加密支付',
    'author' => 'Dreamer Labs',
    'website' => 'https://github.com/GMWalletApp/epusdt',
    'description' => '通过自托管 GM Pay 接收 BSC/BEP20 USDT，到账后自动回调发货',
    'options' => [
        'usdt' => 'USDT (BSC/BEP20)',
    ],
    'callback' => [
        \App\Consts\Pay::IS_SIGN => true,
        \App\Consts\Pay::IS_STATUS => true,
        \App\Consts\Pay::FIELD_STATUS_KEY => 'status',
        \App\Consts\Pay::FIELD_STATUS_VALUE => 2,
        \App\Consts\Pay::FIELD_ORDER_KEY => 'order_id',
        \App\Consts\Pay::FIELD_AMOUNT_KEY => 'amount',
        \App\Consts\Pay::FIELD_RESPONSE => 'ok',
    ],
];
