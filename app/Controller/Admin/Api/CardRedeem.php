<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Model\CardRedeem as Redeem;
use App\Util\RedeemPayloadCrypto;
use App\Util\Schema;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

/** Authenticated queue endpoints for the recharge worker. */
#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class CardRedeem extends Manage
{
    public function claim(): array
    {
        Schema::ensureCardRedeem();
        Redeem::pruneSensitivePayloads();

        $result = DB::transaction(function (): ?array {
            $redeem = Redeem::query()
                ->where('status', Redeem::STATUS_QUEUED)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$redeem) {
                return null;
            }

            try {
                $session = RedeemPayloadCrypto::decrypt((string)$redeem->session_payload);
            } catch (\Throwable) {
                $session = null;
            }

            if ($session === null) {
                $redeem->status = Redeem::STATUS_FAILED;
                $redeem->message = '处理凭证无法解密，请用户重新提交';
                $redeem->session_payload = null;
                $redeem->update_time = date('Y-m-d H:i:s');
                $redeem->save();
                return ['error' => '兑换凭证无法解密'];
            }

            $redeem->status = Redeem::STATUS_PROCESSING;
            $redeem->update_time = date('Y-m-d H:i:s');
            $redeem->save();

            return [
                'id' => $redeem->id,
                'card_id' => $redeem->card_id,
                'account_email' => $redeem->account_email,
                'account_plan' => $redeem->account_plan,
                'account_expire' => $redeem->account_expire,
                'session' => $session,
            ];
        });

        if (isset($result['error'])) {
            throw new JSONException($result['error']);
        }
        return $this->json(data: $result);
    }

    public function finish(): array
    {
        Schema::ensureCardRedeem();
        $id = (int)$this->request->post('id');
        $status = (int)$this->request->post('status');
        $message = trim((string)$this->request->post('message', flags: Filter::NORMAL));

        if ($id < 1 || !in_array($status, [Redeem::STATUS_COMPLETED, Redeem::STATUS_FAILED], true)) {
            throw new JSONException('兑换任务参数不正确');
        }

        DB::transaction(function () use ($id, $status, $message): void {
            $redeem = Redeem::query()->whereKey($id)->lockForUpdate()->first();
            if (!$redeem) {
                throw new JSONException('兑换任务不存在');
            }
            if ((int)$redeem->status !== Redeem::STATUS_PROCESSING) {
                throw new JSONException('兑换任务不在处理中');
            }

            $redeem->status = $status;
            $redeem->message = $message === '' ? null : mb_substr($message, 0, 500);
            $redeem->session_payload = null;
            $redeem->update_time = date('Y-m-d H:i:s');
            $redeem->save();
        });

        return $this->json(data: ['id' => $id, 'status' => $status]);
    }
}
