<?php

declare(strict_types=1);

namespace app\services;

use app\components\ApiException;
use app\components\Format;
use app\components\StatusTransition;
use app\models\Inspection;
use app\models\Mine;
use app\models\Observation;
use app\models\RecordEditLog;
use app\models\User;
use app\models\Violation;
use Yii;

/**
 * Inspection records and their observations (PLAN Q11). Closing an inspection locks it; edits of
 * a locked inspection need a reason and are written to record_edit_log (brief rule 9). Promoting
 * an observation creates the violation it describes.
 */
final class InspectionService
{
    public static function schedule(Mine $mine, User $inspector, array $body): Inspection
    {
        $inspection = new Inspection([
            'mine_id' => $mine->id,
            'inspector_id' => $inspector->id,
            'inspection_type' => $body['inspection_type'] ?? null,
            'scheduled_for' => $body['scheduled_for'] ?? null,
            'status' => 'scheduled',
            'findings_count' => 0,
            'is_locked' => false,
        ]);
        if (!$inspection->save()) {
            throw ApiException::validation($inspection);
        }
        return $inspection;
    }

    /** PATCH: editable fields only; a locked record also needs `reason`. */
    public static function update(Inspection $inspection, User $by, array $body): Inspection
    {
        $reason = is_string($body['reason'] ?? null) ? trim($body['reason']) : '';
        unset($body['reason']);
        $readOnly = array_diff(array_keys($body), Inspection::EDITABLE);
        if ($readOnly) {
            throw ApiException::fields(array_fill_keys(array_values($readOnly), ['READ_ONLY']));
        }
        if ($inspection->is_locked && $reason === '') {
            throw new ApiException(422, 'RECORD_LOCKED', [], ['reason' => ['REQUIRED']]);
        }
        $old = $inspection->getAttributes(Inspection::EDITABLE);
        $inspection->setAttributes($body, false);
        $transaction = Yii::$app->db->beginTransaction();
        try {
            if (!$inspection->save()) {
                throw ApiException::validation($inspection);
            }
            if ($inspection->is_locked) {
                foreach ($body as $field => $value) {
                    if ((string) $old[$field] !== (string) $value) {
                        (new RecordEditLog([
                            'entity' => 'inspection', 'entity_id' => $inspection->id, 'mine_id' => $inspection->mine_id,
                            'field' => $field, 'old_value' => (string) $old[$field], 'new_value' => (string) $value,
                            'reason' => $reason, 'edited_by' => $by->id, 'edited_at' => Format::sql(Format::now()),
                        ]))->save(false);
                    }
                }
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return $inspection;
    }

    public static function addObservation(Inspection $inspection, array $body): Observation
    {
        if ($inspection->status !== 'visited') {
            throw new ApiException(422, 'INSPECTION_NOT_IN_PROGRESS', ['status' => $inspection->status]);
        }
        $observation = new Observation([
            'inspection_id' => $inspection->id,
            'mine_id' => $inspection->mine_id,
            'category' => $body['category'] ?? null,
            'severity' => $body['severity'] ?? null,
            'status' => 'open',
            'observed_at' => Format::sql(Format::now()),
        ]);
        $transaction = Yii::$app->db->beginTransaction();
        try {
            if (!$observation->save()) {
                throw ApiException::validation($observation);
            }
            $inspection->updateCounters(['findings_count' => 1]);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return $observation;
    }

    /** open -> promoted: creates the violation (source inspection) and links both ways. */
    public static function promote(Observation $observation, array $body): Violation
    {
        if (!StatusTransition::canTransition($observation, 'promoted')) {
            throw new ApiException(422, 'INVALID_TRANSITION', ['from' => $observation->status, 'to' => 'promoted']);
        }
        $type = is_string($body['violation_type'] ?? null) ? $body['violation_type'] : null;
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $violation = new Violation([
                'mine_id' => $observation->mine_id,
                'violation_type' => $type,
                'category' => $observation->category,
                'source' => 'inspection',
                'inspection_id' => $observation->inspection_id,
                'observation_id' => $observation->id,
                'contractor_id' => $observation->contractor_id,
                'detected_at' => Format::sql(Format::now()),
                'resolved' => false,
            ]);
            if (!$violation->save()) {
                throw ApiException::validation($violation);
            }
            $observation->violation_id = $violation->id;
            StatusTransition::apply($observation, 'promoted', ['violation_id' => (int) $violation->id]);
            AlertService::forViolation($violation);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return $violation;
    }
}
