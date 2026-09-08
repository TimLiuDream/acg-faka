<?php
declare(strict_types=1);

namespace App\Model;


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
}
