<?php
declare(strict_types=1);

namespace App\Service;

use App\Util\Http;
use Kernel\Util\Log;

/**
 * Small, read-only adapter for AIHOT's public Codex reset snapshot.
 *
 * The upstream feed is optional because external commercial use requires a
 * separate written authorization. The storefront controller only calls this
 * service after the LiuNeng theme switch has been explicitly enabled.
 */
final class CodexResetFeed
{
    public const SOURCE_URL = 'https://aihot.news/api/v1/codex-resets';
    public const PAGE_URL = 'https://aihot.news/codex-reset';

    private const CACHE_TTL = 300;
    private const MAX_BODY_BYTES = 1048576;
    private const CACHE_DIR = '/runtime/cache';
    private const CACHE_FILE = '/runtime/cache/codex-reset-feed.json';
    private const LOCK_FILE = '/runtime/cache/codex-reset-feed.lock';

    /**
     * @return array{available:bool,stale:bool,source:array{name:string,url:string},checkedAt:?string,historyFrom:?string,count:int,events:array}
     */
    public static function snapshot(): array
    {
        $cached = self::readCache();
        if ($cached !== null && (int)$cached['fetchedAt'] >= time() - self::CACHE_TTL) {
            return self::present($cached['payload'], false);
        }

        $dir = BASE_PATH . self::CACHE_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $cached !== null ? self::present($cached['payload'], true) : self::unavailable();
        }

        $lock = @fopen(BASE_PATH . self::LOCK_FILE, 'c');
        if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                @fclose($lock);
            }
            return $cached !== null ? self::present($cached['payload'], true) : self::unavailable();
        }

        try {
            // Another request may have refreshed while this request waited for the lock.
            $fresh = self::readCache();
            if ($fresh !== null && (int)$fresh['fetchedAt'] >= time() - self::CACHE_TTL) {
                return self::present($fresh['payload'], false);
            }
            if ($fresh !== null) {
                $cached = $fresh;
            }

            $headers = [
                'Accept' => 'application/json',
                'User-Agent' => 'DreamerLabs/1.0',
            ];
            if ($cached !== null && (string)$cached['etag'] !== '') {
                $headers['If-None-Match'] = (string)$cached['etag'];
            }

            $response = Http::make([
                'verify' => true,
                'timeout' => 8,
                'connect_timeout' => 4,
                'http_errors' => false,
            ])->get(self::SOURCE_URL, ['headers' => $headers]);

            if ($response->getStatusCode() === 304 && $cached !== null) {
                $cached['fetchedAt'] = time();
                self::writeCache($cached);
                return self::present($cached['payload'], false);
            }

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('upstream status ' . $response->getStatusCode());
            }

            $body = (string)$response->getBody();
            if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) {
                throw new \RuntimeException('upstream body size is invalid');
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('upstream body is not JSON');
            }

            $payload = self::normalize($decoded);
            $cache = [
                'fetchedAt' => time(),
                'etag' => mb_substr((string)$response->getHeaderLine('ETag'), 0, 256),
                'payload' => $payload,
            ];
            self::writeCache($cache);
            return self::present($payload, false);
        } catch (\Throwable $e) {
            Log::inst()->error('Codex 重置公开数据读取失败：' . mb_substr($e->getMessage(), 0, 240));
            return $cached !== null ? self::present($cached['payload'], true) : self::unavailable();
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private static function normalize(array $payload): array
    {
        if (($payload['schemaVersion'] ?? null) !== 1 || ($payload['timezone'] ?? null) !== 'Asia/Shanghai') {
            throw new \RuntimeException('unsupported upstream schema');
        }

        $events = [];
        foreach (array_slice(is_array($payload['events'] ?? null) ? $payload['events'] : [], 0, 160) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $normalized = self::normalizeEvent($event);
            if ($normalized !== null) {
                $events[] = $normalized;
            }
        }

        return [
            'checkedAt' => self::dateTime($payload['checkedAt'] ?? null),
            'historyFrom' => self::dateTime($payload['historyFrom'] ?? null),
            'count' => count($events),
            'events' => $events,
        ];
    }

    private static function normalizeEvent(array $event): ?array
    {
        $id = self::text($event['id'] ?? '', 160);
        $type = (string)($event['type'] ?? '');
        $status = (string)($event['status'] ?? '');
        if ($id === '' || !in_array($type, ['direct_reset', 'reset_credit'], true)
            || !in_array($status, ['announced', 'confirmed'], true)) {
            return null;
        }

        $posts = [];
        foreach (array_slice(is_array($event['posts'] ?? null) ? $event['posts'] : [], 0, 12) as $post) {
            if (!is_array($post)) {
                continue;
            }
            $url = self::url($post['url'] ?? '', ['x.com', 'twitter.com']);
            $publishedAt = self::dateTime($post['publishedAt'] ?? null);
            if ($url === '' || $publishedAt === null) {
                continue;
            }
            $posts[] = [
                'id' => self::text($post['id'] ?? '', 160),
                'publishedAt' => $publishedAt,
                'stage' => self::text($post['stage'] ?? '', 80),
                'text' => self::text($post['text'] ?? '', 600),
                'originalText' => self::text($post['originalText'] ?? '', 1200),
                'url' => $url,
            ];
        }

        $schedule = null;
        if (is_array($event['schedule'] ?? null)) {
            $from = self::dateTime($event['schedule']['from'] ?? null);
            $through = self::dateTime($event['schedule']['through'] ?? null);
            $precision = (string)($event['schedule']['precision'] ?? '');
            if ($from !== null && $through !== null
                && in_array($precision, ['exact', 'approximate', 'deadline', 'date', 'window'], true)) {
                $schedule = [
                    'precision' => $precision,
                    'from' => $from,
                    'through' => $through,
                    'label' => self::text($event['schedule']['label'] ?? '', 160),
                ];
            }
        }

        return [
            'id' => $id,
            'type' => $type,
            'label' => $type === 'direct_reset' ? '全员重置' : '发重置卡',
            'status' => $status,
            'title' => self::text($event['title'] ?? '', 240),
            'scope' => self::text($event['scope'] ?? '', 240),
            'createdAt' => self::dateTime($event['createdAt'] ?? null),
            'updatedAt' => self::dateTime($event['updatedAt'] ?? null),
            'confirmedAt' => self::dateTime($event['confirmedAt'] ?? null),
            'occurredOn' => self::date($event['occurredOn'] ?? null),
            'confirmationBasis' => in_array(($basis = $event['confirmationBasis'] ?? null), ['source_post', 'receipt_review'], true) ? $basis : null,
            'schedule' => $schedule,
            'posts' => $posts,
            'url' => self::url($event['url'] ?? '', ['aihot.news']),
        ];
    }

    private static function present(array $payload, bool $stale): array
    {
        return [
            'available' => true,
            'stale' => $stale,
            'source' => ['name' => 'AIHOT', 'url' => self::PAGE_URL],
            'checkedAt' => $payload['checkedAt'] ?? null,
            'historyFrom' => $payload['historyFrom'] ?? null,
            'count' => (int)($payload['count'] ?? 0),
            'events' => is_array($payload['events'] ?? null) ? $payload['events'] : [],
        ];
    }

    private static function unavailable(): array
    {
        return [
            'available' => false,
            'stale' => false,
            'source' => ['name' => 'AIHOT', 'url' => self::PAGE_URL],
            'checkedAt' => null,
            'historyFrom' => null,
            'count' => 0,
            'events' => [],
        ];
    }

    private static function readCache(): ?array
    {
        $file = BASE_PATH . self::CACHE_FILE;
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (!is_array($decoded) || !isset($decoded['fetchedAt'], $decoded['payload'])
            || !is_array($decoded['payload'])) {
            return null;
        }
        return [
            'fetchedAt' => (int)$decoded['fetchedAt'],
            'etag' => self::text($decoded['etag'] ?? '', 256),
            'payload' => $decoded['payload'],
        ];
    }

    private static function writeCache(array $cache): void
    {
        $encoded = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || @file_put_contents(BASE_PATH . self::CACHE_FILE, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException('unable to write local cache');
        }
    }

    private static function text(mixed $value, int $maxLength): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }
        return mb_substr(trim(strip_tags((string)$value)), 0, $maxLength);
    }

    private static function dateTime(mixed $value): ?string
    {
        $value = self::text($value, 80);
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function date(mixed $value): ?string
    {
        $value = self::text($value, 16);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function url(mixed $value, array $allowedHosts): string
    {
        $value = self::text($value, 2048);
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        return in_array($host, $allowedHosts, true) ? $value : '';
    }
}
