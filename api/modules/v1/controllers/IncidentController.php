<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\components\ListingQuery;
use app\models\Alert;
use app\models\Incident;
use app\models\Mine;
use app\models\Violation;
use app\services\AlertService;
use app\services\ObligationService;
use Yii;

/**
 * Incidents (HANDOFF C23): list (?mine_id=, ?late=1 for reports after 48 h, filter[severity|type]),
 * detail with the linked violation and the 48-hour reporting check (citing RPT-03/04/05),
 * report a new one, link or unlink a violation of the same mine.
 */
class IncidentController extends ApiController
{
    protected function verbs(): array
    {
        return ['index' => ['GET'], 'view' => ['GET'], 'create' => ['POST'], 'link' => ['PATCH']];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('incident.view');
        $query = $this->scopedList(Incident::find())->with('mine');
        $late = Yii::$app->request->get('late');
        if ($late !== null && $late !== '') {
            $query->andWhere(['incident.reported_within_48h' => !filter_var($late, FILTER_VALIDATE_BOOLEAN)]);
        }
        return ListingQuery::apply($query, ['severity', 'type', 'obligation_code', 'reported_within_48h', 'related_violation_id'],
            ['occurred_at', 'reported_at', 'id'], '-occurred_at');
    }

    public function actionView(int $id): array
    {
        $this->requirePermission('incident.view');
        return Incident::findScoped($id)->toArray([], ['relatedViolation']);
    }

    /** {mine_id, occurred_at, reported_at?, type, severity, persons_affected, description_code, related_violation_id?} */
    public function actionCreate(): array
    {
        $this->requirePermission('incident.create');
        $body = $this->body();
        if (!isset($body['mine_id']) || !is_numeric($body['mine_id'])) {
            throw ApiException::fields(['mine_id' => ['REQUIRED']]);
        }
        $mine = Mine::findScoped((int) $body['mine_id']);
        $incident = new Incident();
        $incident->setAttributes([
            'mine_id' => $mine->id,
            'occurred_at' => $body['occurred_at'] ?? null,
            'reported_at' => $body['reported_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            'type' => $body['type'] ?? null,
            'severity' => $body['severity'] ?? null,
            'persons_affected' => $body['persons_affected'] ?? 0,
            'description_code' => $body['description_code'] ?? null,
            'related_violation_id' => $body['related_violation_id'] ?? null,
        ], false);
        $transaction = Yii::$app->db->beginTransaction();
        try {
            if (!$incident->save()) {
                throw ApiException::validation($incident);
            }
            // Its reporting obligation on the register: due 48 h after it occurred, done when reported.
            ObligationService::recordIncident($incident);
            if ($incident->severity === 'dangerous_occurrence') {
                AlertService::create((int) $mine->id, Alert::CODE_DANGEROUS_OCCURRENCE, 'high', 'incident', (int) $incident->id, [
                    'incident_id' => (int) $incident->id, 'type' => $incident->type,
                    'description_code' => $incident->description_code, 'obligation_code' => $incident->obligation_code,
                ]);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        Yii::$app->response->statusCode = 201;
        return $incident->toArray([], ['relatedViolation']);
    }

    /** PATCH /v1/incidents/{id}/violation {related_violation_id: int|null} */
    public function actionLink(int $id): array
    {
        $this->requirePermission('incident.linkViolation');
        $incident = Incident::findScoped($id);
        $body = $this->body();
        if (!array_key_exists('related_violation_id', $body)) {
            throw ApiException::fields(['related_violation_id' => ['REQUIRED']]);
        }
        $violationId = $body['related_violation_id'];
        if ($violationId !== null) {
            if (!is_numeric($violationId)) {
                throw ApiException::fields(['related_violation_id' => ['INVALID_VALUE']]);
            }
            Violation::findScoped((int) $violationId);
        }
        $incident->related_violation_id = $violationId === null ? null : (int) $violationId;
        if (!$incident->save(true, ['related_violation_id'])) {
            throw ApiException::validation($incident);
        }
        return $incident->toArray([], ['relatedViolation']);
    }
}
