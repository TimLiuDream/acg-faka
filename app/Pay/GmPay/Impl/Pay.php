<?php
declare(strict_types=1);

namespace App\Pay\GmPay\Impl;

use App\Entity\PayEntity;
use App\Model\Order;
use App\Model\OrderOption;
use App\Pay\Base;
use App\Service\PaymentStatusQuery;
use GuzzleHttp\Client;
use Kernel\Exception\JSONException;

final class Pay extends Base implements \App\Pay\Pay, PaymentStatusQuery
{
    private const CREATE_PATH = '/payments/gmpay/v1/order/create-transaction';
    private const STATUS_PATH = '/pay/check-status/';
    private const CHECKOUT_PATH = '/pay/checkout-counter-resp/';

    public function trade(): PayEntity
    {
        if ($this->code !== 'usdt') {
            throw new JSONException('当前 GM Pay 通道不受支持');
        }

        $config = $this->validatedConfig();
        $params = [
            'pid' => $config['pid'],
            'order_id' => $this->tradeNo,
            'currency' => $config['currency'],
            'token' => $config['token'],
            'network' => $config['network'],
            'amount' => number_format($this->amount, 2, '.', ''),
            'notify_url' => $this->replaceOrigin($this->callbackUrl, $config['callback_base_url']),
            'redirect_url' => $this->returnUrl,
            'name' => 'Dreamer Labs - ' . $this->tradeNo,
        ];
        $params['signature'] = Signature::generate($params, $config['secret_key']);

        $result = $this->requestJson('POST', $config['api_url'] . self::CREATE_PATH, [
            'form_params' => $params,
        ], 'GM Pay 创建订单失败');

        $data = $result['data'] ?? null;
        if ((int)($result['status_code'] ?? 0) !== 200 || !is_array($data)) {
            throw new JSONException($this->gatewayError($result, 'GM Pay 创建订单失败'));
        }

        if (!hash_equals($this->tradeNo, trim((string)($data['order_id'] ?? '')))) {
            throw new JSONException('GM Pay 返回的商城订单号不匹配');
        }

        $remoteAmount = $data['amount'] ?? null;
        if (!is_scalar($remoteAmount) || !is_numeric((string)$remoteAmount)
            || number_format((float)$remoteAmount, 2, '.', '') !== number_format($this->amount, 2, '.', '')) {
            throw new JSONException('GM Pay 返回的订单金额不匹配');
        }

        $tradeId = trim((string)($data['trade_id'] ?? ''));
        $paymentUrl = trim((string)($data['payment_url'] ?? ''));
        if (!$this->validTradeId($tradeId) || $paymentUrl === '') {
            throw new JSONException('GM Pay 未返回有效的收银台地址');
        }
        $paymentPath = parse_url($paymentUrl, PHP_URL_PATH);
        $paymentTradeId = is_string($paymentPath) ? basename(rtrim($paymentPath, '/')) : '';
        if (!hash_equals($tradeId, $paymentTradeId)) {
            throw new JSONException('GM Pay 返回的收银台交易号不匹配');
        }

        $entity = new PayEntity();
        $entity->setType(self::TYPE_REDIRECT);
        $entity->setUrl($this->replaceOrigin($paymentUrl, $config['public_url']));
        $entity->setOption([
            'gmpay_trade_id' => $tradeId,
            'actual_amount' => is_scalar($data['actual_amount'] ?? null) ? (string)$data['actual_amount'] : '',
            'token' => (string)($data['token'] ?? ''),
            'network' => $config['network'],
            'expiration_time' => (int)($data['expiration_time'] ?? 0),
        ]);
        return $entity;
    }

    public function queryPayment(): array
    {
        $config = $this->validatedConfig();
        $tradeId = $this->resolveTradeId();
        if ($tradeId === null) {
            throw new JSONException('未找到 GM Pay 交易号');
        }

        $status = $this->requestJson(
            'GET',
            $config['api_url'] . self::STATUS_PATH . rawurlencode($tradeId),
            [],
            'GM Pay 查单失败'
        );
        $statusData = $status['data'] ?? null;
        if ((int)($status['status_code'] ?? 0) !== 200 || !is_array($statusData)) {
            throw new JSONException($this->gatewayError($status, 'GM Pay 查单失败'));
        }
        if (!hash_equals($tradeId, trim((string)($statusData['trade_id'] ?? '')))) {
            throw new JSONException('GM Pay 查单返回的交易号不匹配');
        }

        if ((int)($statusData['status'] ?? 0) !== 2) {
            return ['paid' => false, 'trade_no' => $this->tradeNo, 'amount' => null];
        }

        $checkout = $this->requestJson(
            'GET',
            $config['api_url'] . self::CHECKOUT_PATH . rawurlencode($tradeId),
            [],
            'GM Pay 订单校验失败'
        );
        $checkoutData = $checkout['data'] ?? null;
        if ((int)($checkout['status_code'] ?? 0) !== 200 || !is_array($checkoutData)) {
            throw new JSONException($this->gatewayError($checkout, 'GM Pay 订单校验失败'));
        }
        if (!hash_equals($tradeId, trim((string)($checkoutData['trade_id'] ?? '')))) {
            throw new JSONException('GM Pay 返回的交易号不匹配');
        }

        $amount = $checkoutData['amount'] ?? null;
        if (!is_scalar($amount) || !is_numeric((string)$amount)) {
            throw new JSONException('GM Pay 返回的订单金额无效');
        }

        return [
            'paid' => true,
            'trade_no' => $this->tradeNo,
            'amount' => (string)$amount,
        ];
    }

    private function resolveTradeId(): ?string
    {
        $order = Order::query()->where('trade_no', $this->tradeNo)->first(['id', 'pay_url']);
        if (!$order) {
            return null;
        }

        $option = OrderOption::get((int)$order->id);
        $storedTradeId = trim((string)($option['gmpay_trade_id'] ?? ''));
        if ($this->validTradeId($storedTradeId)) {
            return $storedTradeId;
        }

        // 兼容接入 GM Pay 之前创建、尚未保存 OrderOption 的订单。
        $path = parse_url((string)$order->pay_url, PHP_URL_PATH);
        $tradeId = is_string($path) ? basename(rtrim($path, '/')) : '';
        return $this->validTradeId($tradeId) ? $tradeId : null;
    }

    private function requestJson(string $method, string $url, array $options, string $fallback): array
    {
        try {
            // GM Pay 内网地址通常是 HTTP；若配置 HTTPS，必须验证服务端证书。
            $response = (new Client())->request($method, $url, $options + [
                'connect_timeout' => 4,
                'timeout' => 15,
                'http_errors' => false,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'DreamerLabs-GMPay/1.0',
                ],
            ]);
        } catch (\Throwable) {
            throw new JSONException($fallback);
        }

        $body = (string)$response->getBody();
        if (strlen($body) > 262144) {
            throw new JSONException($fallback . '：响应过大');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new JSONException($fallback . '：响应格式错误');
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new JSONException($this->gatewayError($decoded, $fallback));
        }
        return $decoded;
    }

    private function validatedConfig(): array
    {
        $config = [
            'api_url' => $this->baseUrl((string)($this->config['api_url'] ?? ''), true, 'GM Pay 内部 API 地址'),
            'public_url' => $this->publicCashierBaseUrl((string)($this->config['public_url'] ?? '')),
            'callback_base_url' => $this->publicCallbackBaseUrl((string)($this->config['callback_base_url'] ?? '')),
            'pid' => trim((string)($this->config['pid'] ?? '')),
            'secret_key' => trim((string)($this->config['secret_key'] ?? '')),
            'currency' => strtolower(trim((string)($this->config['currency'] ?? 'cny'))),
            'token' => strtolower(trim((string)($this->config['token'] ?? 'usdt'))),
            'network' => strtolower(trim((string)($this->config['network'] ?? 'binance'))),
        ];

        if ($config['pid'] === '' || $config['secret_key'] === '') {
            throw new JSONException('请先配置 GM Pay 商户 PID 和 Secret Key');
        }
        if (!preg_match('/^[a-z0-9_-]{2,16}$/D', $config['currency'])) {
            throw new JSONException('GM Pay 法币币种格式不正确');
        }
        if ($config['token'] !== 'usdt' || $config['network'] !== 'binance') {
            throw new JSONException('当前仅允许 GM Pay 的 BSC/BEP20 USDT');
        }
        return $config;
    }

    private function baseUrl(string $url, bool $allowLocalHttp, string $label): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));

        if (
            $url === ''
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || !in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new JSONException('请配置有效的' . $label);
        }
        if (!$allowLocalHttp && $scheme !== 'https') {
            throw new JSONException($label . '必须使用 HTTPS');
        }
        return $url;
    }

    private function publicCallbackBaseUrl(string $url): string
    {
        $url = $this->baseUrl($url, false, '商城公开回调地址');
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));

        if (
            $host === 'localhost'
            || $host === 'host.docker.internal'
            || str_ends_with($host, '.local')
            || (filter_var($host, FILTER_VALIDATE_IP) !== false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)
        ) {
            throw new JSONException('GM Pay 2.0 不接受本机或私网回调地址，请填写商城可公网访问的 HTTPS 域名');
        }

        return $url;
    }

    private function publicCashierBaseUrl(string $url): string
    {
        $url = $this->baseUrl($url, true, 'GM Pay 公开地址');
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $local = $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || $host === 'host.docker.internal';

        if ($scheme !== 'https' && !$local) {
            throw new JSONException('线上 GM Pay 公开地址必须使用 HTTPS');
        }
        return $url;
    }

    private function replaceOrigin(string $url, string $baseUrl): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['path'])) {
            throw new JSONException('GM Pay 返回的地址无效');
        }

        $target = $baseUrl . '/' . ltrim((string)$parts['path'], '/');
        if (isset($parts['query']) && $parts['query'] !== '') {
            $target .= '?' . $parts['query'];
        }
        return $target;
    }

    private function validTradeId(string $tradeId): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $tradeId) === 1;
    }

    private function gatewayError(array $result, string $fallback): string
    {
        $message = $result['message'] ?? null;
        if (!is_scalar($message)) {
            return $fallback;
        }
        $message = trim((string)preg_replace('/[\x00-\x1f\x7f]/u', '', (string)$message));
        return $message === '' ? $fallback : $fallback . '：' . mb_substr($message, 0, 120);
    }
}
