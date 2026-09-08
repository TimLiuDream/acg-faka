<?php
declare(strict_types=1);

const DEBUG = false;
define('BASE_PATH', '/var/www/html/');
define('APP_VERSION', '3.7.1');

require BASE_PATH . 'vendor/autoload.php';
require BASE_PATH . 'kernel/Helper.php';
require BASE_PATH . 'app/View/User/Helper.php';

\Kernel\Util\Lang::reset('zh-cn');
\Kernel\Util\Context::set(\Kernel\Consts\Base::ROUTE, '/user/dashboard/index');

$days = [
    ['label' => '09-02', 'amount' => '32.00', 'pct' => 26],
    ['label' => '09-03', 'amount' => '68.00', 'pct' => 55],
    ['label' => '09-04', 'amount' => '44.00', 'pct' => 36],
    ['label' => '09-05', 'amount' => '96.00', 'pct' => 78],
    ['label' => '09-06', 'amount' => '51.00', 'pct' => 42],
    ['label' => '09-07', 'amount' => '122.00', 'pct' => 100],
    ['label' => '09-08', 'amount' => '82.00', 'pct' => 67],
];

$data = [
    'title' => '个人主页',
    'favicon' => '/favicon.ico',
    'app' => ['version' => '3.7.1'],
    'config' => [
        'title' => 'TimLiu AI',
        'shop_name' => 'TimLiu AI',
        'currency_symbol' => '¥',
        'background_url' => '',
    ],
    'user' => [
        'username' => 'Tim',
        'avatar' => '/assets/static/images/avatar.png',
        'balance' => '168.80',
        'coin' => '24.50',
        'recharge' => '299.00',
        'total_coin' => '16.20',
        'email' => 'tim@example.com',
        'phone' => '',
        'group' => ['name' => '小康之家', 'icon' => '/assets/static/images/group/ic_user level_2.png'],
        'businessLevel' => null,
    ],
    'buy_month' => '68.00',
    'buy_total' => '299.00',
    'buy_count' => 4,
    'week_series' => $days,
    'week_series_max' => '122.00',
    'setting' => [],
];

$html = \Kernel\Util\View::render('User/Theme/LiuNeng/Dashboard/Index.html', $data, BASE_PATH . 'app/View', false);
file_put_contents(__DIR__ . '/dashboard-preview.html', $html);
echo strlen($html);
