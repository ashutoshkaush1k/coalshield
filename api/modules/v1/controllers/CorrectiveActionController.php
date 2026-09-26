<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\components\ListingQuery;
use app\models\CorrectiveAction;
use app\models\Violation;
use app\services\CorrectiveActionService;
use Yii;
use yii\web\UploadedFile;

/**
 * Corrective actions: list (?mine_id=, ?overdue=1, filter[status]), detail with history,
 * create for an own violation, resolve with proof (multipart: proof_text, file?).
 */
class CorrectiveActionController extends ApiController
{
    protected function verbs(): array
    {
        return ['index' => ['GET'], 'view' => ['GET'], 'create' => ['POST'], 'resolve' => ['POST']];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('correctiveAction.view');
        $query = $this->scopedList(CorrectiveAction::find());
        if (Yii::$app->request->get('overdue')) {
            $query->andWhere(['corrective_action.status' => 'open'])->andWhere(['<', 'corrective_action.due_at', gmdate('Y-m-d H:i:sP')]);
        }
        return ListingQuery::apply($query, ['status', 'violation_id', 'contractor_id'], ['created_at', 'due_at', 'id'], '-created_at');
    }

    public function actionView(int $id): array
    {
        $this->requirePermission('correctiveAction.view');
        $action = CorrectiveAction::findScoped($id);
        return $action->toArray([], ['violation', 'history']);
    }

    public function actionCreate(): CorrectiveAction
    {
        $this->requirePermission('correctiveAction.create');
        $body = $this->body();
        if (!isset($body['violation_id']) || !is_numeric($body['violation_id'])) {
            throw ApiException::fields(['violation_id' => ['REQUIRED']]);
        }
        $violation = Violation::findScoped((int) $body['violation_id']);
        Yii::$app->response->statusCode = 201;
        return CorrectiveActionService::create($violation, $this->currentUser(), $body);
    }

    public function actionResolve(int $id): array
    {
        $this->requirePermission('correctiveAction.resolve');
        $action = CorrectiveAction::findScoped($id);
        $proof = (string) (Yii::$app->request->post('proof_text') ?? $this->body()['proof_text'] ?? '');
        CorrectiveActionService::resolve($action, $this->currentUser(), $proof, UploadedFile::getInstanceByName('file'));
        return $action->toArray([], ['violation', 'history']);
    }
}
