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

            $total = (int)DB::table('page_view')->where($where)->value('views');

            try {
                $dailyWhere = $where + ['view_date' => date('Y-m-d')];
                DB::table('page_view_daily')->insertOrIgnore($dailyWhere + [
                    'views' => 0,
                    'update_time' => $now,
                ]);
                DB::table('page_view_daily')->where($dailyWhere)->increment('views', 1, [
                    'update_time' => $now,
                ]);
            } catch (\Throwable $e) {
                Log::inst()->error('每日浏览量计数失败：' . $e->getMessage());
            }

            return $total;
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

    /**
     * 最近若干天的首页与商品详情浏览趋势，缺少数据的日期补零。
     *
     * @return array{days:string[],home:int[],commodity:int[]}
     */
    public static function dailyTrend(int $days = 30): array
    {
        $days = max(7, min(90, $days));
        $labels = [];
        $home = [];
        $commodity = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} day"));
            $labels[] = $date;
            $home[$date] = 0;
            $commodity[$date] = 0;
        }

        try {
            Schema::ensurePageView();
            $rows = DB::table('page_view_daily')
                ->whereBetween('view_date', [$labels[0], $labels[count($labels) - 1]])
                ->selectRaw('view_date, page_type, SUM(views) as views')
                ->groupBy('view_date', 'page_type')
                ->get();

            foreach ($rows as $row) {
                $date = (string)$row->view_date;
                if (!array_key_exists($date, $home)) {
                    continue;
                }
                if ((int)$row->page_type === self::TYPE_HOME) {
                    $home[$date] = (int)$row->views;
                } elseif ((int)$row->page_type === self::TYPE_COMMODITY) {
                    $commodity[$date] = (int)$row->views;
                }
            }
        } catch (\Throwable $e) {
            Log::inst()->error('每日浏览量读取失败：' . $e->getMessage());
        }

        return [
            'days' => array_map(static fn(string $date): string => date('m-d', strtotime($date)), $labels),
            'home' => array_values($home),
            'commodity' => array_values($commodity),
        ];
    }
}
