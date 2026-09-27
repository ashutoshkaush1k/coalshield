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

    /** The caller's address for bucketing (the API is served directly, not behind a proxy). */
    public static function clientKey(): string
    {
        return (string) (Yii::$app->request->userIP ?? 'unknown');
    }
}
