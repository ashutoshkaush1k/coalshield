<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\Format;
use app\models\Mine;
use app\services\ComplianceScoreService;
use Yii;
use yii\db\Query;

/** GET /v1/compliance/{mine_id} (current, with inputs) and /history (recorded points, oldest first). */
class ComplianceController extends ApiController
{
    protected function verbs(): array
    {
        return ['view' => ['GET'], 'history' => ['GET']];
    }

    public function actionView(int $mine_id): array
    {
        $this->requirePermission('compliance.view');
        $mine = Mine::findScoped($mine_id);
        return ['mine_id' => (int) $mine->id] + ComplianceScoreService::scoreMine((int) $mine->id)->toArray();
    }

    public function actionHistory(int $mine_id): array
    {
        $this->requirePermission('compliance.view');
        $mine = Mine::findScoped($mine_id);
        $limit = max(1, min(1000, (int) Yii::$app->request->get('limit', 200)));
        $rows = (new Query())->from('{{%compliance_score}}')->where(['mine_id' => $mine->id])
            ->orderBy(['id' => SORT_DESC])->limit($limit)->all();
        return array_map(fn($r) => [
            'score' => (float) $r['score'], 'risk_level' => $r['risk_level'],
            'violation_count' => (int) $r['violation_count'], 'breach_count' => (int) $r['breach_count'],
            'computed_at' => Format::utc($r['computed_at']),
        ], array_reverse($rows));
    }
}
