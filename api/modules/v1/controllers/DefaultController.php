<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\components\JobRunner;
use app\components\ScheduledJobs;
use app\services\SystemStatus;
use Yii;

/** CORS pre-flight, a public health check, and the scheduled jobs' trigger (online only). */
class DefaultController extends ApiController
{
    protected array $publicActions = ['options', 'health', 'jobs'];

    protected function verbs(): array
    {
        return ['health' => ['GET'], 'status' => ['GET'], 'jobs' => ['POST'], 'options' => ['OPTIONS']];
    }

    /** Pre-flight: the Cors filter has already written the headers. */
    public function actionOptions(): void
    {
        Yii::$app->response->statusCode = 204;
    }

    /** GET /v1/system/status (signed in): which engine the automated detection uses now (Phase 8). */
    public function actionStatus(): array
    {
        return SystemStatus::get();
    }

    /**
     * POST /v1/system/jobs (header X-Jobs-Token) - run the scheduled jobs, as `yii jobs/all` does on
     * the laptop. Online only: the free server has no scheduler, so a GitHub Actions schedule calls
     * this every hour (docs/DEPLOYMENT.md). Without JOBS_TOKEN (the laptop) the endpoint does not
     * exist (404). Optional body {"jobs": [...]} runs only those jobs. Every job is idempotent and
     * skips itself while another copy runs.
     */
    public function actionJobs(): array
    {
        $token = (string) (Yii::$app->params['jobs.token'] ?? '');
        if ($token === '') {
            throw ApiException::notFound();
        }
        if (!hash_equals($token, (string) Yii::$app->request->headers->get('X-Jobs-Token', ''))) {
            throw ApiException::forbidden();
        }
        $jobs = $this->body()['jobs'] ?? ScheduledJobs::JOBS;
        if (!is_array($jobs) || $jobs === [] || array_diff($jobs, ScheduledJobs::JOBS) !== []) {
            throw ApiException::fields(['jobs' => ['INVALID_VALUE']]);
        }
        // The caller may give up waiting (a cold start takes a minute); the jobs still finish.
        ignore_user_abort(true);
        set_time_limit(900);
        $results = [];
        foreach (ScheduledJobs::JOBS as $job) {
            if (in_array($job, $jobs, true)) {
                $r = JobRunner::run($job, ScheduledJobs::work($job));
                $results[$job] = ['status' => $r['status'], 'seconds' => $r['seconds']];
            }
        }
        return ['failed' => count(array_filter($results, fn($r) => $r['status'] === 'failed')), 'jobs' => $results];
    }

    public function actionHealth(): array
    {
        $database = 'ok';
        try {
            Yii::$app->db->createCommand('SELECT 1')->queryScalar();
        } catch (\Throwable) {
            $database = 'unavailable';
        }
        return ['status' => $database === 'ok' ? 'ok' : 'degraded', 'database' => $database, 'time' => gmdate('Y-m-d\TH:i:s\Z')];
    }
}
