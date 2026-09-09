<?php
declare(strict_types=1);

namespace App\Pay\Epay\Impl;

use App\Entity\PayEntity;
use App\Pay\Base;
use Kernel\Exception\JSONException;

final class Pay extends Base implements \App\Pay\Pay
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
