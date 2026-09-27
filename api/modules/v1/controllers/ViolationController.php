<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\components\ListingQuery;
use app\models\Violation;
use app\services\ContractorLinkService;
use Yii;

/** Violations in scope, newest first. ?mine_id= narrows (404 if out of scope). */
class ViolationController extends ApiController
{
    protected function verbs(): array
    {
        return ['index' => ['GET'], 'view' => ['GET'], 'contractor' => ['PATCH']];
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

    /** PATCH /v1/violations/{id}/contractor {contractor_id: int|null} - link a finding to the contractor responsible. */
    public function actionContractor(int $id): array
    {
        $this->requirePermission('violation.linkContractor');
        $violation = Violation::findScoped($id);
        $body = $this->body();
        if (!array_key_exists('contractor_id', $body)) {
            throw ApiException::fields(['contractor_id' => ['REQUIRED']]);
        }
        $violation->contractor_id = ContractorLinkService::contractorFor((int) $violation->mine_id, $body['contractor_id']);
        $violation->save(false);
        return $violation->toArray();
    }
}
