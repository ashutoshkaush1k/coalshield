<?php

declare(strict_types=1);

namespace app\services;

use app\components\ApiException;
use app\components\Format;
use app\components\StatusTransition;
use app\models\CorrectiveAction;
use app\models\User;
use app\models\Violation;
use Yii;
use yii\web\UploadedFile;

/**
 * Corrective actions (PLAN Q12: built, not stubbed). A mine head records the action for one of its
 * violations; resolving it with proof closes the violation, which lifts the score.
 */
final class CorrectiveActionService
{
    public static function create(Violation $violation, User $by, array $body): CorrectiveAction
    {
        if ($violation->resolved) {
            throw new ApiException(422, 'VIOLATION_ALREADY_RESOLVED', ['violation_id' => (int) $violation->id]);
        }
        $action = new CorrectiveAction([
            'mine_id' => $violation->mine_id,
            'violation_id' => $violation->id,
            'contractor_id' => array_key_exists('contractor_id', $body)
                ? ContractorLinkService::contractorFor((int) $violation->mine_id, $body['contractor_id'])
                : $violation->contractor_id,
            'alert_id' => isset($body['alert_id']) && is_numeric($body['alert_id']) ? (int) $body['alert_id'] : null,
            'description' => is_string($body['description'] ?? null) ? trim($body['description']) : null,
            'due_at' => is_string($body['due_at'] ?? null) ? $body['due_at'] : null,
            'status' => CorrectiveAction::STATUS_OPEN,
            'created_by' => $by->id,
        ]);
        if (!$action->save()) {
            throw ApiException::validation($action);
        }
        return $action;
    }

    public static function resolve(CorrectiveAction $action, User $by, string $proofText, ?UploadedFile $file): CorrectiveAction
    {
        $proofText = trim($proofText);
        if ($proofText === '') {
            throw ApiException::fields(['proof_text' => ['REQUIRED']]);
        }
        if (!StatusTransition::canTransition($action, CorrectiveAction::STATUS_RESOLVED)) {
            throw new ApiException(422, 'INVALID_TRANSITION', ['from' => $action->status, 'to' => CorrectiveAction::STATUS_RESOLVED]);
        }
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $before = ComplianceScoreService::scoreMine((int) $action->mine_id);
            $fileId = null;
            if ($file !== null) {
                $stored = Yii::$app->fileStorage->storeUpload($file, 'corrective_action', (int) $action->id, (int) $by->id);
                $fileId = (int) $stored->id;
                $action->proof_image_path = $stored->path;
            }
            $now = Format::sql(Format::now());
            $action->resolved_at = $now;
            StatusTransition::apply($action, CorrectiveAction::STATUS_RESOLVED, array_filter(['proof_text' => $proofText, 'file_id' => $fileId]));

            $violation = $action->violation;
            if (!$violation->resolved) {
                $violation->resolved = true;
                $violation->resolved_at = $now;
                if (!$violation->save()) {
                    throw ApiException::validation($violation);
                }
            }
            ComplianceScoreService::recordIfMoved((int) $action->mine_id, ComplianceScoreService::scoreMine((int) $action->mine_id), $before);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return $action;
    }
}
