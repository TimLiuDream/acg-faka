<?php
declare(strict_types=1);

namespace App\Model;

use App\Util\Schema;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model;
use Kernel\Util\Log;

/**
 * 只记录页面累计访问次数，不保存 IP、User-Agent 等访客信息。
 *
 * @property int $id
 * @property int $page_type
 * @property int $target_id
 * @property int $views
 * @property string|null $update_time
 */
class PageView extends Model
{
    public const TYPE_HOME = 0;
    public const TYPE_COMMODITY = 1;

    protected $table = 'page_view';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'page_type' => 'integer',
        'target_id' => 'integer',
        'views' => 'integer',
    ];

    public static function hitHome(): int
    {
        return self::hit(self::TYPE_HOME, 0);
    }

    public static function hitCommodity(int $commodityId): int
    {
        return self::hit(self::TYPE_COMMODITY, $commodityId);
    }

    /**
     * 原子累加一次访问并返回最新累计值。
     * 建表或计数异常不能拖垮买家页面，因此失败时降级为 0。
     */
    public static function hit(int $pageType, int $targetId): int
    {
        try {
            Schema::ensurePageView();
            $where = ['page_type' => $pageType, 'target_id' => $targetId];
            $now = date('Y-m-d H:i:s');

            DB::table('page_view')->insertOrIgnore($where + [
                'views' => 0,
                'update_time' => $now,
            ]);
            DB::table('page_view')->where($where)->increment('views', 1, [
                'update_time' => $now,
            ]);

            return (int)DB::table('page_view')->where($where)->value('views');
        } catch (\Throwable $e) {
            Log::inst()->error('浏览量计数失败：' . $e->getMessage());
            return 0;
        }
    }

    /**
     * @return array{home:int, commodity:int}
     */
    public static function summary(): array
    {
        try {
            Schema::ensurePageView();
            return [
                'home' => (int)DB::table('page_view')
                    ->where('page_type', self::TYPE_HOME)
                    ->where('target_id', 0)
                    ->value('views'),
                'commodity' => (int)DB::table('page_view')
                    ->where('page_type', self::TYPE_COMMODITY)
                    ->sum('views'),
            ];
        } catch (\Throwable $e) {
            Log::inst()->error('浏览量汇总失败：' . $e->getMessage());
            return ['home' => 0, 'commodity' => 0];
        }
    }

    /**
     * @param int[] $commodityIds
     * @return array<int, int>
     */
    public static function commodityCounts(array $commodityIds): array
    {
        $commodityIds = array_values(array_unique(array_filter(array_map('intval', $commodityIds))));
        if ($commodityIds === []) {
            return [];
        }

        try {
            Schema::ensurePageView();
            return DB::table('page_view')
                ->where('page_type', self::TYPE_COMMODITY)
                ->whereIn('target_id', $commodityIds)
                ->pluck('views', 'target_id')
                ->mapWithKeys(static fn($views, $targetId): array => [(int)$targetId => (int)$views])
                ->all();
        } catch (\Throwable $e) {
            Log::inst()->error('商品浏览量读取失败：' . $e->getMessage());
            return [];
        }
    }
}
