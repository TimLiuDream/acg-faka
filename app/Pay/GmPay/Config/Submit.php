<?php
declare(strict_types=1);

return [
    [
        'title' => 'GM Pay 内部 API 地址',
        'name' => 'api_url',
        'type' => 'input',
        'placeholder' => 'Docker 本地填写：http://gmpay:8000',
        'required' => true,
    ],
    [
        'title' => 'GM Pay 公开地址',
        'name' => 'public_url',
        'type' => 'input',
        'placeholder' => '本地填写：http://127.0.0.1:8090；线上填写 HTTPS 域名',
        'required' => true,
    ],
    [
        'title' => '商城公开回调地址',
        'name' => 'callback_base_url',
        'type' => 'input',
        'placeholder' => '例如：https://dreamlab.timliu.xyz',
        'required' => true,
    ],
    [
        'title' => '商户 PID',
        'name' => 'pid',
        'type' => 'input',
        'placeholder' => '在 GM Pay 后台创建 API Key 后获得',
        'required' => true,
    ],
    [
        'title' => '商户 Secret Key',
        'name' => 'secret_key',
        'type' => 'password',
        'placeholder' => '仅保存在商城支付配置中',
        'required' => true,
    ],
    [
        'title' => '法币币种',
        'name' => 'currency',
        'type' => 'input',
        'placeholder' => 'cny',
        'required' => true,
    ],
    [
        'title' => '代币',
        'name' => 'token',
        'type' => 'input',
        'placeholder' => 'usdt',
        'required' => true,
    ],
    [
        'title' => '网络',
        'name' => 'network',
        'type' => 'input',
        'placeholder' => 'binance（GM Pay 对 BSC 的内部标识）',
        'required' => true,
    ],
];
