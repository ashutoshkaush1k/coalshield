<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ListingQuery;
use app\models\Mine;

/**
 * Minimal mine reads for Phase 1 (scoping tests). Phase 2 adds compliance, GeoJSON collection and
 * the dashboard fields.
 */
class MineController extends ApiController
{
    protected function verbs(): array
    {
        return ['index' => ['GET'], 'view' => ['GET']];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('mine.view');
        return ListingQuery::apply(
            Mine::find()->forCurrentUser()->with('subsidiary'),
            ['type', 'status', 'subsidiary_id', 'area_id', 'state', 'region'],
            ['id', 'code', 'name', 'state', 'region'],
        );
    }

    public function actionView(int $id): Mine
    {
        $this->requirePermission('mine.view');
        return Mine::findScoped($id);
    }
}
