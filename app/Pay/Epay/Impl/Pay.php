<?php
declare(strict_types=1);

namespace App\Pay\Epay\Impl;

use App\Entity\PayEntity;
use App\Pay\Base;
use Kernel\Exception\JSONException;

final class Pay extends Base implements \App\Pay\Pay, \App\Service\PaymentStatusQuery
{
    private const SUPPORTED_CHANNELS = ['alipay', 'wxpay'];

    public function trade(): PayEntity
    {
        $gateway = $this->gatewayUrl((string)($this->config['url'] ?? ''));
        $pid = trim((string)($this->config['pid'] ?? ''));
        $key = trim((string)($this->config['key'] ?? ''));

        if ($pid === '') {
            throw new JSONException('请配置易支付商户 ID');
        }
        if ($key === '') {
            throw new JSONException('请配置易支付商户 KEY');
        }
        if (!in_array($this->code, self::SUPPORTED_CHANNELS, true)) {
            throw new JSONException('当前支付通道不受支持');
        }
        if ($this->amount <= 0) {
            throw new JSONException('支付金额必须大于 0');
        }

        $params = [
            'pid' => $pid,
            'type' => $this->code,
            'out_trade_no' => $this->tradeNo,
            'notify_url' => $this->callbackUrl,
            'return_url' => $this->returnUrl,
            'name' => 'Dreamer Labs - ' . $this->tradeNo,
            'money' => number_format($this->amount, 2, '.', ''),
            'sitename' => 'Dreamer Labs',
        ];
        $params['sign'] = Signature::generateSignature($params, $key);
        $params['sign_type'] = 'MD5';

        $entity = new PayEntity();
        $entity->setType(self::TYPE_SUBMIT);
        $entity->setUrl($gateway . '/submit.php');
        $entity->setOption($params);
        return $entity;
    }

    public function queryPayment(): array
    {
        $gateway = $this->gatewayUrl((string)($this->config['url'] ?? ''));
        $pid = trim((string)($this->config['pid'] ?? ''));
        $key = trim((string)($this->config['key'] ?? ''));

        if ($pid === '' || $key === '') {
            throw new JSONException('易支付商户配置不完整');
        }

        $params = [
            'act' => 'order',
            'pid' => $pid,
            'out_trade_no' => $this->tradeNo,
        ];
        $params['sign'] = Signature::generateSignature($params, $key);
        $params['sign_type'] = 'MD5';

        try {
            $response = $this->http()->get($gateway . '/api.php', [
                'query' => $params,
                'connect_timeout' => 4,
                'timeout' => 12,
                'http_errors' => false,
                // 该网关 CDN 会对默认 Guzzle UA 返回 200 空响应。
                'headers' => [
                    'Accept' => '*/*',
                    'User-Agent' => 'Mozilla/5.0 (compatible; DreamerLabs-Payment/1.0)',
                ],
            ]);
        } catch (\Throwable) {
            // 不把包含签名参数的请求 URL 写入业务日志。
            throw new JSONException('支付平台查单请求失败');
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new JSONException('支付平台查单请求失败');
        }

        $body = (string)$response->getBody();
        if (strlen($body) > 65536) {
            throw new JSONException('支付平台查单响应异常');
        }
        $result = json_decode($body, true);
        if (!is_array($result) || (int)($result['code'] ?? 0) !== 1) {
            $message = is_array($result) && is_scalar($result['msg'] ?? null)
                ? trim((string)$result['msg'])
                : '';
            if ($message === '') {
                $message = trim(strip_tags($body));
            }
            $message = mb_substr((string)preg_replace('/[\x00-\x1f\x7f]/u', '', $message), 0, 120);
            throw new JSONException('支付平台未返回有效订单' . ($message !== '' ? '：' . $message : ''));
        }

        $resultTradeNo = trim((string)($result['out_trade_no'] ?? ''));
        if ($resultTradeNo === '' || !hash_equals($this->tradeNo, $resultTradeNo)) {
            throw new JSONException('支付平台返回的订单号不匹配');
        }
        if (isset($result['pid']) && !hash_equals($pid, (string)$result['pid'])) {
            throw new JSONException('支付平台返回的商户号不匹配');
        }

        return [
            'paid' => (int)($result['status'] ?? 0) === 1,
            'trade_no' => $resultTradeNo,
            'amount' => isset($result['money']) && is_scalar($result['money']) ? (string)$result['money'] : null,
        ];
    }

    private function gatewayUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);

        if (
            $url === ''
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
        ) {
            throw new JSONException('请配置有效的 HTTPS 易支付网关地址');
        }

        return $url;
    }
}
