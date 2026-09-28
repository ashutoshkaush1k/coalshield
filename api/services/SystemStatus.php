<?php

declare(strict_types=1);

namespace app\services;

use app\components\AiClient;
use app\components\Format;
use Yii;
use yii\db\Query;

/**
 * Which engine the automated detection is using right now (Phase 8), for the dashboards' footer:
 * the ai-service when it answers its health check, else the PHP fallback (the same algorithms,
 * PPE photo analysis unavailable). The health check is cached for 20 s, so the footer's poll
 * never waits on a dead service more than once per 20 s for the whole API.
 */
final class SystemStatus
{
    private const TTL = 20;

    public static function get(): array
    {
        return Yii::$app->cache->getOrSet('system-status:v1', fn() => self::compute(), self::TTL);
    }

    private static function compute(): array
    {
        $configured = (string) (Yii::$app->params['ai.engine'] ?? 'auto');
        $up = false;
        $version = null;
        if ($configured !== 'php') {
            $health = AiClient::health(1.5);
            $up = ($health['status'] ?? null) === 'ok';
            $version = $health['detectors'] ?? null;
        }
        $engine = $configured === 'php' || !$up ? 'php' : 'ai-service';
        $last = (new Query())->select(['started_at', 'status', 'summary'])->from('{{%job_run}}')
            ->where(['job' => 'anomaly'])->orderBy(['id' => SORT_DESC])->one();
        $engines = [];
        if ($last) {
            foreach ((array) json_decode((string) $last['summary'], true) as $detector => $row) {
                if (is_array($row) && isset($row['engine'])) {
                    $engines[$row['engine']] = true;
                }
            }
        }
        return [
            'checked_at' => Format::utc(Format::sql(Format::now())),
            'detection_engine' => $engine,
            'configured_engine' => $configured,
            // why the fallback is in use: CONFIGURED_PHP (AI_ENGINE=php) or AI_SERVICE_DOWN
            'reason' => $engine === 'ai-service' ? null : ($configured === 'php' ? 'CONFIGURED_PHP' : 'AI_SERVICE_DOWN'),
            'ai_service' => ['up' => $up, 'detectors_version' => $version],
            'last_anomaly_run' => $last ? ['at' => Format::utc($last['started_at']), 'status' => $last['status'],
                'engines' => array_keys($engines)] : null,
        ];
    }
}
