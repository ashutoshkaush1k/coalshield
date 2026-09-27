<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\components\ListingQuery;
use app\models\Alert;
use app\models\Mine;
use app\services\AlertService;
use Yii;
use yii\db\Expression;
use yii\web\UploadedFile;

/**
 * Alerts in scope. Open directives first (a person is waiting on them), then newest.
 * ?mine_id=, ?status=open|acknowledged|resolved, ?directives=1, ?since=<ISO> (polling), filter[code].
 * Each alert carries `history`: every transition with its proof or reason.
 */
class AlertController extends ApiController
{
    protected function verbs(): array
    {
        return ['index' => ['GET'], 'view' => ['GET'], 'acknowledge' => ['POST'], 'directive' => ['POST'],
            'resolve' => ['POST'], 'reopen' => ['POST']];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('alert.view');
        $request = Yii::$app->request;
        $query = $this->scopedList(Alert::find())->with('mine');
        if (($status = $request->get('status')) !== null && $status !== '') {
            $query->andWhere(['alert.status' => explode(',', $status)]);
        }
        if ($request->get('directives') !== null && $request->get('directives') !== '') {
            $wanted = filter_var($request->get('directives'), FILTER_VALIDATE_BOOLEAN);
            $query->andWhere([$wanted ? '=' : '<>', 'alert.code', Alert::CODE_DIRECTIVE]);
        }
        if (($since = $request->get('since')) !== null && $since !== '') {
            if (strtotime($since) === false) {
                throw ApiException::fields(['since' => ['INVALID_DATETIME']]);
            }
            $query->andWhere(['>', 'alert.created_at', gmdate('Y-m-d H:i:s', strtotime($since)) . '+00']);
        }
        $query->addOrderBy(new Expression("CASE WHEN alert.code = 'INSPECTION_DIRECTIVE' AND alert.status <> 'resolved' THEN 0 WHEN alert.code = 'INSPECTION_DIRECTIVE' THEN 1 ELSE 2 END"));
        // ListingQuery keeps the directive-first ordering and appends the requested sort.
        $alerts = ListingQuery::apply($query, ['code', 'severity', 'status', 'entity_type'], ['created_at', 'id'], '-created_at');
        \app\models\StatusHistory::preload($alerts);
        return array_map(fn(Alert $a) => $a->toArray([], ['history']), $alerts);
    }

    public function actionView(int $id): array
    {
        $this->requirePermission('alert.view');
        return Alert::findScoped($id)->toArray([], ['history']);
    }

    public function actionAcknowledge(int $id): array
    {
        $this->requirePermission('alert.acknowledge');
        return AlertService::acknowledge(Alert::findScoped($id), $this->currentUser())->toArray([], ['history']);
    }

    /** POST /v1/alerts/directives {mine_id, message?, severity?, reference_id?} */
    public function actionDirective(): array
    {
        $this->requirePermission('directive.create');
        $body = $this->body();
        if (!isset($body['mine_id']) || !is_numeric($body['mine_id'])) {
            throw ApiException::fields(['mine_id' => ['REQUIRED']]);
        }
        $mine = Mine::findScoped((int) $body['mine_id']);
        $alert = AlertService::raiseDirective(
            $mine, $this->currentUser(),
            is_string($body['message'] ?? null) ? $body['message'] : null,
            is_string($body['severity'] ?? null) ? strtolower($body['severity']) : null,
            isset($body['reference_id']) && is_numeric($body['reference_id']) ? (int) $body['reference_id'] : null,
        );
        Yii::$app->response->statusCode = 201;
        return $alert->toArray([], ['history']);
    }

    /** Multipart: proof_text, file (optional image). */
    public function actionResolve(int $id): array
    {
        $this->requirePermission('alert.resolve');
        $alert = Alert::findScoped($id);
        $proof = (string) (Yii::$app->request->post('proof_text') ?? $this->body()['proof_text'] ?? '');
        return AlertService::resolve($alert, $this->currentUser(), $proof, UploadedFile::getInstanceByName('file'))->toArray([], ['history']);
    }

    public function actionReopen(int $id): array
    {
        $this->requirePermission('directive.reopen');
        $alert = Alert::findScoped($id);
        return AlertService::reopen($alert, (string) ($this->body()['reason'] ?? ''))->toArray([], ['history']);
    }
}
