<?php

declare(strict_types=1);

namespace app\components;

use Yii;

/**
 * Fixed-window rate limiting for anonymous callers (the public grievance endpoints), in the
 * rate_limit table so it holds across Apache threads and restarts. One row per bucket and window;
 * old windows are pruned now and then.
 */
final class RateLimiter
{
    /**
     * Count one hit; 429 RATE_LIMITED (with Retry-After) once the bucket is over its limit.
     * @param string $bucket e.g. "grievance.submit|203.0.113.7"
     */
    public static function hit(string $bucket, int $limit, int $windowSeconds): void
    {
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);
        $db = Yii::$app->db;
        $hits = (int) $db->createCommand(
            'INSERT INTO {{%rate_limit}} (bucket, window_start, hits) VALUES (:b, to_timestamp(:w), 1)
             ON CONFLICT (bucket, window_start) DO UPDATE SET hits = {{%rate_limit}}.hits + 1
             RETURNING hits',
            [':b' => mb_substr($bucket, 0, 96), ':w' => $windowStart],
        )->queryScalar();
        if (random_int(1, 50) === 1) {
            $db->createCommand("DELETE FROM {{%rate_limit}} WHERE window_start < now() - interval '1 day'")->execute();
        }
        if ($hits > $limit) {
            Yii::$app->response->headers->set('Retry-After', (string) ($windowStart + $windowSeconds - $now));
            throw new ApiException(429, 'RATE_LIMITED', ['retry_after' => $windowStart + $windowSeconds - $now]);
        }
    }

    /** Whether the bucket is already over its limit in the current window (does not count a hit). */
    public static function exceeded(string $bucket, int $limit, int $windowSeconds): bool
    {
        $now = time();
        $hits = (int) Yii::$app->db->createCommand(
            'SELECT hits FROM {{%rate_limit}} WHERE bucket = :b AND window_start = to_timestamp(:w)',
            [':b' => mb_substr($bucket, 0, 96), ':w' => $now - ($now % $windowSeconds)],
        )->queryScalar();
        return $hits >= $limit;
    }

    /**
     * The caller's address for bucketing. Apache listens on 127.0.0.1 only, so a request from
     * elsewhere comes through the field server (scripts/field_server.mjs, a proxy on this machine):
     * from a loopback peer, the first X-Forwarded-For address is the phone's. From any other peer
     * the header is ignored, so it cannot be forged to dodge a limit.
     */
    public static function clientKey(): string
    {
        $request = Yii::$app->request;
        $peer = (string) ($request->remoteIP ?? '');
        if (in_array($peer, ['127.0.0.1', '::1'], true)) {
            $forwarded = trim(explode(',', (string) $request->headers->get('X-Forwarded-For', ''))[0]);
            if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP)) {
                return $forwarded;
            }
        }
        return $peer !== '' ? $peer : 'unknown';
    }
}
