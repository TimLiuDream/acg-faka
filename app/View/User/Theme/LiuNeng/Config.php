<?php
declare(strict_types=1);

namespace App\View\User\Theme\LiuNeng;

use App\Consts\Render;

interface Config
{
    public const INFO = [
        'NAME' => 'LiuNeng Labs',
        'AUTHOR' => 'LiuNeng Labs',
        'VERSION' => '0.1.0',
        'WEB_SITE' => '#',
        'DESCRIPTION' => '面向 AI 会员与数字服务的清爽品牌主题',
        'RENDER' => Render::ENGINE_SMARTY,
    ];

    public const SUBMIT = [
        [
            'title' => '首页眉题',
            'name' => 'hero_eyebrow',
            'type' => 'input',
            'placeholder' => '例如：AI MEMBERSHIP SERVICE',
        ],
        [
            'title' => '首页主标题',
            'name' => 'hero_title',
            'type' => 'input',
            'placeholder' => '例如：更简单地使用',
        ],
        [
            'title' => '首页强调标题',
            'name' => 'hero_accent',
            'type' => 'input',
            'placeholder' => '例如：更强大的 AI。',
        ],
        [
            'title' => '首页简介',
            'name' => 'hero_description',
            'type' => 'input',
            'placeholder' => '一句话说明服务价值',
        ],
        [
            'title' => '更多联系链接',
            'name' => 'support_url',
            'type' => 'input',
            'placeholder' => '例如：https://your-site.com/contact',
        ],
        [
            'title' => 'Twitter / X',
            'name' => 'support_twitter',
            'type' => 'input',
            'placeholder' => '例如：@yourname',
        ],
        [
            'title' => '微信账号',
            'name' => 'support_wechat',
            'type' => 'input',
            'placeholder' => '例如：your_wechat_id',
        ],
        [
            'title' => '微信二维码',
            'name' => 'support_wechat_qr',
            'type' => 'image',
        ],
        [
            'title' => 'Telegram',
            'name' => 'support_telegram',
            'type' => 'input',
            'placeholder' => '例如：@yourname',
        ],
    ];

    public const THEME = [
        'INDEX' => 'Index/Index.html',
        'ITEM' => 'Index/Item.html',
        'QUERY' => 'Index/Query.html',
        'LOGIN' => 'Authentication/Login.html',
        'REGISTER' => 'Authentication/Register.html',
        'DASHBOARD' => 'Dashboard/Index.html',
        'PERSONAL' => 'User/Personal.html',
        'PASSWORD' => 'User/Personal.html',
        'EMAIL' => 'User/Personal.html',
        'PHONE' => 'User/Personal.html',
    ];
}
