<?php

declare(strict_types=1);

namespace app\components;

use Yii;

/**
 * Runs one scheduled job (Phase 7, yii jobs/*): records it in job_run (running -> ok / failed,
 * with its summary and duration), logs it to runtime/logs/jobs.log, and holds a PostgreSQL
 * advisory lock for the job's name - a second copy started by Task Scheduler while the first is
 * still running is recorded as "skipped" and does nothing. Every job is idempotent: run twice, the
 * second run finds nothing left to do.
 */
final class JobRunner
{
    /** @return array{status: string, summary: array, seconds: float} */
    public static function run(string $job, callable $work): array
    {
        $db = Yii::$app->db;
        $started = microtime(true);
        $id = null;
        $lock = (int) crc32('coalshield-job:' . $job);
        if (!(bool) $db->createCommand('SELECT pg_try_advisory_lock(:k)', [':k' => $lock])->queryScalar()) {
            $db->createCommand()->insert('{{%job_run}}', ['job' => $job, 'started_at' => Format::sql(Format::now()),
                'finished_at' => Format::sql(Format::now()), 'status' => 'skipped', 'summary' => ['reason' => 'already running']])->execute();
            Yii::info("$job skipped: already running", 'jobs');
            return ['status' => 'skipped', 'summary' => ['reason' => 'already running'], 'seconds' => 0.0];
        }
        try {
            $db->createCommand()->insert('{{%job_run}}', ['job' => $job, 'started_at' => Format::sql(Format::now()), 'status' => 'running'])->execute();
            $id = (int) $db->getLastInsertID();
            $summary = $work();
            $seconds = round(microtime(true) - $started, 2);
            $db->createCommand()->update('{{%job_run}}', ['status' => 'ok', 'finished_at' => Format::sql(Format::now()),
                'summary' => $summary + ['seconds' => $seconds]], ['id' => $id])->execute();
            Yii::info("$job ok in {$seconds}s: " . json_encode($summary), 'jobs');
            return ['status' => 'ok', 'summary' => $summary, 'seconds' => $seconds];
        } catch (\Throwable $e) {
            $seconds = round(microtime(true) - $started, 2);
            if ($id !== null) {
                $db->createCommand()->update('{{%job_run}}', ['status' => 'failed', 'finished_at' => Format::sql(Format::now()),
                    'error' => mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 2000), 'summary' => ['seconds' => $seconds]],
                    ['id' => $id])->execute();
            }
            Yii::error("$job failed after {$seconds}s: " . $e->getMessage(), 'jobs');
            return ['status' => 'failed', 'summary' => ['error' => $e->getMessage()], 'seconds' => $seconds];
        } finally {
            $db->createCommand('SELECT pg_advisory_unlock(:k)', [':k' => $lock])->execute();
        }
    }
}
