<?php

declare(strict_types=1);

namespace app\services;

use app\components\ApiException;
use app\components\Rules;
use app\components\StatusTransition;
use app\models\Alert;
use app\models\Mine;
use app\models\User;
use app\models\Violation;
use yii\web\UploadedFile;
use Yii;

/**
 * Alerts, ported from backend/app/services/alerts/{engine,directives}.py. Every alert is
 * {code, params}; the frontend composes the sentence (brief rule 7).
 *
 * Directives (INSPECTION_DIRECTIVE) are a government user telling a mine to act. The mine closes
 * one with proof (text, optional image); government may reopen it. Each step is a transition in
 * status_history, so a reopened directive keeps every earlier proof.
 */
final class AlertService
{
    public static function forViolation(Violation $violation): Alert
    {
        $confidence = $violation->confidence === null ? null : (float) $violation->confidence;
        $severity = $confidence === null ? 'medium' : ($confidence >= 0.80 ? 'high' : ($confidence >= 0.60 ? 'medium' : 'low'));
        return self::create($violation->mine_id, Alert::CODE_VIOLATION, $severity, 'violation', (int) $violation->id, [
            'violation_id' => (int) $violation->id,
            'violation_type' => $violation->violation_type,
            'category' => $violation->category,
            'source' => $violation->source,
            'confidence' => $confidence,
        ]);
    }

    /** @return array{alert_id: int, mine_id: int, sensor_type: string, value: float} */
    public static function forBreach(int $readingId, int $mineId, string $type, float $value, ?float $rollingMean): array
    {
        $rule = Rules::sensor($type);
        $compared = $rule['compare'] === 'rolling_8h_mean' && $rollingMean !== null ? $rollingMean : $value;
        $alert = self::create($mineId, Alert::CODE_SENSOR, SensorService::severityForMargin($type, $compared), 'sensor_reading', $readingId, array_filter([
            'sensor_type' => $type,
            'value' => $value,
            'rolling_mean' => $rollingMean === null ? null : round($rollingMean, 3),
            'compare' => $rule['compare'],
            'unit' => $rule['unit'],
            'limit' => $rule['limit'],
            'obligation' => $rule['obligation'],
        ], fn($v) => $v !== null));
        return ['alert_id' => (int) $alert->id, 'mine_id' => $mineId, 'sensor_type' => $type, 'value' => $value];
    }

    public static function create(int $mineId, string $code, string $severity, string $entityType, int $entityId, array $params): Alert
    {
        $alert = new Alert([
            'mine_id' => $mineId, 'code' => $code, 'severity' => $severity,
            'entity_type' => $entityType, 'entity_id' => $entityId, 'params' => $params,
        ]);
        if (!$alert->save()) {
            throw ApiException::validation($alert);
        }
        return $alert;
    }

    /**
     * Raise a directive against a mine. Without a severity it follows the mine's risk band now;
     * params snapshot the score at the moment of the click (the dashboard may be seconds stale).
     */
    public static function raiseDirective(Mine $mine, User $by, ?string $note, ?string $severity, ?int $violationId): Alert
    {
        $note = $note === null ? null : trim($note);
        if ($note !== null && mb_strlen($note) > 500) {
            throw ApiException::fields(['message' => ['TOO_LONG']]);
        }
        if ($severity !== null && !in_array($severity, Alert::SEVERITIES, true)) {
            throw ApiException::fields(['severity' => ['INVALID_VALUE']]);
        }
        if ($violationId !== null) {
            $violation = Violation::findOne($violationId);
            if ($violation === null || (int) $violation->mine_id !== (int) $mine->id) {
                throw ApiException::fields(['reference_id' => ['NOT_IN_SAME_MINE']]);
            }
        }
        $score = ComplianceScoreService::scoreMine((int) $mine->id);
        return self::create((int) $mine->id, Alert::CODE_DIRECTIVE, $severity ?? $score->riskLevel,
            $violationId ? 'violation' : 'mine', $violationId ?? (int) $mine->id, array_filter([
                'raised_by' => $by->id,
                'raised_by_name' => $by->full_name,
                'note' => $note ?: null,
                'score' => $score->score,
                'risk_level' => $score->riskLevel,
                'violation_count' => $score->violationCount,
                'breach_count' => $score->breachCount,
                'violation_id' => $violationId,
            ], fn($v) => $v !== null));
    }

    public static function acknowledge(Alert $alert, User $by): Alert
    {
        if ($alert->status !== Alert::STATUS_OPEN) {
            throw new ApiException(422, 'INVALID_TRANSITION', ['from' => $alert->status, 'to' => Alert::STATUS_ACKNOWLEDGED]);
        }
        $alert->ack_by = $by->id;
        StatusTransition::apply($alert, Alert::STATUS_ACKNOWLEDGED);
        return $alert;
    }

    /** Close an alert with evidence. Directives need proof text; an image is optional. */
    public static function resolve(Alert $alert, User $by, string $proofText, ?UploadedFile $file): Alert
    {
        $proofText = trim($proofText);
        if ($proofText === '') {
            throw ApiException::fields(['proof_text' => ['REQUIRED']]);
        }
        if (mb_strlen($proofText) > 2000) {
            throw ApiException::fields(['proof_text' => ['TOO_LONG']]);
        }
        if (!StatusTransition::canTransition($alert, Alert::STATUS_RESOLVED)) {
            throw new ApiException(422, 'INVALID_TRANSITION', ['from' => $alert->status, 'to' => Alert::STATUS_RESOLVED]);
        }
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $fileId = null;
            if ($file !== null) {
                $fileId = (int) Yii::$app->fileStorage->storeUpload($file, 'alert', (int) $alert->id, (int) $by->id)->id;
            }
            if ($alert->ack_by === null) {
                $alert->ack_by = $by->id;
            }
            StatusTransition::apply($alert, Alert::STATUS_RESOLVED, array_filter(['proof_text' => $proofText, 'file_id' => $fileId]));
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return $alert;
    }

    /** Government sends a resolved directive back: the proof was not sufficient. */
    public static function reopen(Alert $alert, string $reason): Alert
    {
        if (!$alert->isDirective()) {
            throw new ApiException(422, 'NOT_A_DIRECTIVE');
        }
        if ($alert->status !== Alert::STATUS_RESOLVED) {
            throw new ApiException(422, 'INVALID_TRANSITION', ['from' => $alert->status, 'to' => Alert::STATUS_OPEN]);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) > 500) {
            throw ApiException::fields(['reason' => ['TOO_LONG']]);
        }
        $alert->ack_by = null;
        StatusTransition::apply($alert, Alert::STATUS_OPEN, array_filter(['reason' => $reason]));
        return $alert;
    }
}
