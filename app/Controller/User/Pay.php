<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Controller\Base\View\User;
use App\Interceptor\Waf;
use App\Model\Order;
use App\Model\OrderOption;
use App\Service\Order as OrderService;
use App\Util\Client;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;
use Kernel\Util\View;
use App\Util\Csp;
use App\Util\PayConfig;

#[Interceptor(Waf::class)]
class Pay extends User
{
    #[Inject]
    private OrderService $orderService;

    /**
     * @return string
     * @throws JSONException
     * @throws ViewException
     * @throws \SmartyException
     */
    public function order(): string
    {
        if (!isset($_GET['_PARAMETER'][0]) || !isset($_GET['_PARAMETER'][1])) {
            return '订单不存在';
        }

        $tradeNo = $_GET['_PARAMETER'][0];
        $type = (int)$_GET['_PARAMETER'][1];
        //获取订单信息
        $order = Order::with(['pay'])->where("trade_no", $tradeNo)->first();
        if (!$order) {
            return '订单不存在';
        }

        if (!$order->pay) {
            return '支付方式不存在';
        }

        $data = OrderOption::get($order->id);

        if ($type == 2) {
            if (!$data) {
                throw new JSONException("参数错误");
            }
            Csp::allowFormAction((string)$order->pay_url);
            return $this->render("正在下单，请稍后..", "Submit.html", [
                "url" => $order->pay_url,
                "data" => $data
            ]);
        }

        //路径安全在 renderTemplate 里把关：code 是站长可填的值，不能直接拼进文件路径
        $html = PayConfig::renderTemplate((string)$order->pay->handle, (string)$order->pay->code);

        if ($html === null) {
            throw new JSONException("视图不存在");
        }

        return View::render($html, ['order' => $order, 'option' => $data], BASE_PATH . '/app/Pay/');
    }

    /**
     * 接收支付平台的同步回跳。只有验签、订单号和金额全部通过时才会完成订单。
     */
    public function result(): string
    {
        $tradeNo = trim((string)($_GET['_PARAMETER'][0] ?? ''));
        if (!\App\Service\Bind\Order::isCallbackTradeNo($tradeNo)) {
            return '订单不存在';
        }

        $order = Order::query()->where('trade_no', $tradeNo)->first(['id', 'trade_no', 'owner', 'status']);
        if (!$order) {
            return '订单不存在';
        }

        if ((int)$order->status === 0) {
            $map = $_GET;
            unset($map['_PARAMETER'], $map['s']);
            if (!empty($map)) {
                try {
                    $this->orderService->callback($tradeNo, $map);
                } catch (\Throwable) {
                    // 回跳页始终继续展示订单；失败原因已由 callback() 写入脱敏日志，
                    // 页面加载后还会再走一次主动查单兜底。
                }
            }
        }

        $target = (int)$order->owner === 0
            ? '/user/index/query?tradeNo=' . rawurlencode($tradeNo)
            : '/user/personal/purchaseRecord?tradeNo=' . rawurlencode($tradeNo);
        Client::redirect($target, '正在确认支付结果，请稍候..', 0);
        return '';
    }
}
