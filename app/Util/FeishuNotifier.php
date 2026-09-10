<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Commodity;
use App\Model\Config;
use App\Model\Order;
use GuzzleHttp\Client;

final class FeishuNotifier
{
    public const CONFIG_KEY = 'sale_notification_config';

    public static function isValidWebhook(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
            || !in_array(parse_url($url, PHP_URL_PORT), [null, 443], true)
            || parse_url($url, PHP_URL_QUERY) !== null
            || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            return false;
        }

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $path = (string)parse_url($url, PHP_URL_PATH);
        return in_array($host, ['open.feishu.cn', 'open.larksuite.com'], true)
            && preg_match('#^/open-apis/bot/v2/hook/[A-Za-z0-9_-]+$#D', $path) === 1;
    }

    public static function sendSale(Order $order, Commodity $commodity): void
    {
        $config = self::config();
        if ((int)($config['feishu_enabled'] ?? 0) !== 1) {
            return;
        }

        $amount = number_format((float)$order->amount, 2, '.', '');
        $plain = static function (mixed $value, int $limit = 180): string {
            $text = is_scalar($value) ? trim((string)$value) : '';
            $text = (string)preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text);
            return mb_substr(str_replace(['<', '>'], ['‹', '›'], $text), 0, $limit);
        };
        $payName = $plain($order->pay?->name ?? '') ?: '未知';
        $deliveryStatus = (int)$order->delivery_status === 1 ? '已发货' : '待发货';
        $contact = $plain($order->contact) ?: '未填写';
        $shopName = $plain(Config::get('shop_name'), 80) ?: 'Dreamer Labs';
        $commodityName = $plain($commodity->name);
        $text = "💰 {$shopName} 新订单已支付\n"
            . "订单号：{$order->trade_no}\n"
            . "商品：{$commodityName}\n"
            . "数量：{$order->card_num}\n"
            . "实付：¥{$amount}\n"
            . "支付方式：{$payName}\n"
            . "发货状态：{$deliveryStatus}\n"
            . "联系方式：{$contact}\n"
            . "支付时间：{$order->pay_time}";

        self::send($text, $config);
    }

    public static function sendTest(): void
    {
        $config = self::config();
        $shopName = trim((string)Config::get('shop_name')) ?: 'Dreamer Labs';
        self::send("✅ {$shopName} 飞书订单通知配置成功\n发送时间：" . Date::current(), $config);
    }

    private static function config(): array
    {
        try {
            $config = json_decode((string)Config::get(self::CONFIG_KEY), true, 16, JSON_THROW_ON_ERROR);
            return is_array($config) ? $config : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private static function send(string $text, array $config): void
    {
        $webhook = trim((string)($config['feishu_webhook'] ?? ''));
        if (!self::isValidWebhook($webhook)) {
            throw new \RuntimeException('飞书机器人 Webhook 尚未正确配置');
        }

        $payload = [
            'msg_type' => 'text',
            'content' => ['text' => $text],
        ];
        $secret = (string)($config['feishu_secret'] ?? '');
        if ($secret !== '') {
            $timestamp = (string)time();
            $payload['timestamp'] = $timestamp;
            $payload['sign'] = base64_encode(hash_hmac('sha256', '', $timestamp . "\n" . $secret, true));
        }

        try {
            $response = (new Client([
                'connect_timeout' => 2,
                'timeout' => 5,
                'http_errors' => false,
            ]))->post($webhook, ['json' => $payload]);
        } catch (\Throwable) {
            throw new \RuntimeException('无法连接飞书机器人，请检查服务器网络和 Webhook 配置');
        }

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('飞书机器人返回 HTTP ' . $response->getStatusCode());
        }

        try {
            $result = json_decode((string)$response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \RuntimeException('飞书机器人返回了无法识别的响应');
        }

        $code = $result['code'] ?? $result['StatusCode'] ?? null;
        if ((int)$code !== 0) {
            $message = trim((string)($result['msg'] ?? $result['StatusMessage'] ?? '发送失败'));
            throw new \RuntimeException('飞书机器人：' . mb_substr($message, 0, 160));
        }
    }
}
