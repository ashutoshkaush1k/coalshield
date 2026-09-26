<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ListingQuery;
use app\models\Mine;
use app\services\ComplianceScoreService;
use Yii;

/** Mines in scope with their live compliance; detail; GeoJSON for the map. */
class MineController extends ApiController
{
    protected function verbs(): array
    {
        return ['index' => ['GET'], 'view' => ['GET'], 'geojson' => ['GET']];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('mine.view');
        $mines = ListingQuery::apply(
            Mine::find()->forCurrentUser()->with('subsidiary'),
            ['type', 'status', 'subsidiary_id', 'area_id', 'state', 'region'],
            ['id', 'code', 'name', 'state', 'region'],
        );
        return $this->withCompliance($mines);
    }

    public function actionView(int $id): array
    {
        $this->requirePermission('mine.view');
        $mine = Mine::findScoped($id);
        $row = $this->withCompliance([$mine])[0];
        $row['total_readings'] = (int) Yii::$app->db->createCommand(
            'SELECT count(*) FROM sensor_reading WHERE mine_id = :id', [':id' => $id])->queryScalar();
        return $row;
    }

    /** FeatureCollection of the mines in scope (GEM coordinates; attribution in the frontend). */
    public function actionGeojson(): array
    {
        $this->requirePermission('mine.view');
        $mines = $this->visibleMines(Yii::$app->request->get('state'));
        $scores = ComplianceScoreService::scoreMines(array_map(fn(Mine $m) => (int) $m->id, $mines));
        $features = [];
        foreach ($mines as $mine) {
            if ($mine->location_geojson === null) {
                continue;
            }
            $features[] = [
                'type' => 'Feature',
                'geometry' => json_decode($mine->location_geojson, true),
                'properties' => [
                    'id' => (int) $mine->id, 'code' => $mine->code, 'name' => $mine->name,
                    'type' => $mine->type, 'state' => $mine->state, 'location_quality' => $mine->location_quality,
                    'score' => $scores[$mine->id]->score, 'risk_level' => $scores[$mine->id]->riskLevel,
                ],
            ];
        }
        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /** @param Mine[] $mines */
    private function withCompliance(array $mines): array
    {
        $ids = array_map(fn(Mine $m) => (int) $m->id, $mines);
        $scores = ComplianceScoreService::scoreMines($ids);
        $alerts = ComplianceScoreService::openAlertCounts($ids);
        return array_map(fn(Mine $m) => $m->toArray() + [
            'compliance' => $scores[$m->id]->toArray(),
            'open_alerts' => $alerts[$m->id] ?? 0,
        ], $mines);
    }
}
