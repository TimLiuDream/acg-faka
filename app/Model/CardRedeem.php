<?php
declare(strict_types=1);

namespace App\Model;


use App\Util\RedeemPayloadCrypto;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $card_id
 * @property string $account_email
 * @property string $account_plan
 * @property string $account_expire
 * @property string $session_payload
 * @property int $status
 * @property string $message
 * @property string $create_time
 * @property string $update_time
 */
class CardRedeem extends Model
{
    public const STATUS_QUEUED = 0;
    public const STATUS_PROCESSING = 1;
    public const STATUS_COMPLETED = 2;
    public const STATUS_FAILED = 3;
    public const PAYLOAD_TTL_SECONDS = 86400;

    /**
     * @var string
     */
    protected $table = "card_redeem";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'card_id' => 'integer', 'status' => 'integer'];

    /** Clear credentials after terminal states and expire abandoned jobs after 24 hours. */
    public static function pruneSensitivePayloads(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        // 兼容功能初版已经写入的少量明文凭证：原地加密；失败则清除并要求用户重提。
        $legacyRows = self::query()
            ->whereNotNull('session_payload')
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_PROCESSING])
            ->where('session_payload', 'not like', RedeemPayloadCrypto::PREFIX . '%')
            ->get(['id', 'session_payload']);
        foreach ($legacyRows as $row) {
            try {
                $encrypted = RedeemPayloadCrypto::encrypt((string)$row->session_payload);
                self::query()->whereKey($row->id)->update(['session_payload' => $encrypted]);
            } catch (\Throwable) {
                self::query()->whereKey($row->id)->update([
                    'status' => self::STATUS_FAILED,
                    'message' => '处理凭证迁移失败，请重新提交',
                    'session_payload' => null,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        self::query()
            ->whereNotNull('session_payload')
            ->whereIn('status', [self::STATUS_COMPLETED, self::STATUS_FAILED])
            ->update(['session_payload' => null]);

        self::query()
            ->whereNotNull('session_payload')
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_PROCESSING])
            ->where('create_time', '<', date('Y-m-d H:i:s', time() - self::PAYLOAD_TTL_SECONDS))
            ->update([
                'status' => self::STATUS_FAILED,
                'message' => '处理凭证已过期，请重新提交',
                'session_payload' => null,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
    }
}
