<?php
declare(strict_types=1);

return [
    [
        'title' => '支付网关',
        'name' => 'url',
        'type' => 'input',
        'placeholder' => '例如：https://pay.example.com',
        'required' => true,
    ],
    [
        'title' => '商户 ID',
        'name' => 'pid',
        'type' => 'input',
        'placeholder' => '聚合支付平台提供的商户 ID',
        'required' => true,
    ],
    [
        'title' => '商户 KEY',
        'name' => 'key',
        'type' => 'password',
        'placeholder' => '聚合支付平台提供的商户 KEY',
        'required' => true,
    ],
];
