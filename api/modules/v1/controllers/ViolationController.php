<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ListingQuery;
use app\models\Violation;
use Yii;

/** Violations in scope, newest first. ?mine_id= narrows (404 if out of scope). */
class ViolationController extends ApiController
{
    protected function verbs(): array
    {
        return ['index' => ['GET'], 'view' => ['GET']];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('violation.view');
        $query = $this->scopedList(Violation::find());
        $resolved = Yii::$app->request->get('resolved');
        if ($resolved !== null && $resolved !== '') {
            $query->andWhere(['violation.resolved' => filter_var($resolved, FILTER_VALIDATE_BOOLEAN)]);
        }
        return ListingQuery::apply($query, ['category', 'source', 'resolved', 'violation_type', 'inspection_id', 'contractor_id'],
            ['detected_at', 'category', 'id'], '-detected_at');
    }

    public function actionView(int $id): array
    {
        $this->requirePermission('violation.view');
        $violation = Violation::findScoped($id);
        return $violation->toArray() + [
            'corrective_actions' => array_map(fn($a) => $a->toArray(), $violation->correctiveActions),
        ];
    }
}
