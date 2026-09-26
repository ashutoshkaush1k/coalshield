<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\services\BaselineService;

/**
 * GET /v1/admin/baseline-check (PLAN Q14, government): live scores against the seeded baseline.
 * The simulator reads the same report with its API key at GET /v1/sensor-readings/baseline.
 */
class AdminController extends ApiController
{
    protected function verbs(): array
    {
        return ['baseline-check' => ['GET']];
    }

    public function actionBaselineCheck(): array
    {
        $this->requirePermission('admin.baselineCheck');
        return BaselineService::check();
    }
}
