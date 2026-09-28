<?php

declare(strict_types=1);

namespace app\services;

use app\components\AiClient;
use app\components\AiUnavailableException;
use app\components\ApiException;
use app\components\AuditChain;
use app\components\Format;
use app\models\CorrectiveAction;
use app\models\Mine;
use app\models\User;
use app\models\Violation;
use Yii;
use yii\web\UploadedFile;

/**
 * One PPE vision run, ported from the FastAPI prototype's app/services/vision/ingest.py: ai-service detects,
 * this service persists violations and alerts, rescores the mine and returns the score either side.
 *
 * A clean frame that shows workers or worn PPE is evidence of compliance and resolves the mine's
 * open PPE violations from vision (the prototype resolved every open violation; inspection
 * findings in other categories now stay open until their own corrective action).
 * If ai-service is down: 503 AI_SERVICE_UNAVAILABLE, a low-severity alert for the mine, nothing
 * else changes (brief section 2: degrade, never crash).
 */
final class VisionService
{
    public const SYSTEM_RESOLUTION = 'VISION_CLEAN_FRAME';

    public static function analyze(Mine $mine, User $by, UploadedFile $upload): array
    {
        if ($upload->hasError || $upload->size <= 0) {
            throw ApiException::fields(['file' => ['UPLOAD_FAILED']]);
        }
        $mineId = (int) $mine->id;
        $before = ComplianceScoreService::scoreMine($mineId);
        try {
            $result = AiClient::ppe($upload->tempName, $upload->name);
        } catch (AiUnavailableException) {
            AlertService::create($mineId, 'AI_SERVICE_UNAVAILABLE', 'low', 'mine', $mineId, ['service' => 'vision']);
            throw new ApiException(503, 'AI_SERVICE_UNAVAILABLE');
        }

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $annotatedUrl = null;
            $frameRef = $upload->name;
            if (!empty($result['annotated_jpeg_b64'])) {
                $file = Yii::$app->fileStorage->storeBytes(base64_decode($result['annotated_jpeg_b64']), 'vision_frame', $mineId, (int) $by->id);
                $annotatedUrl = Yii::$app->fileStorage->signedUrl($file);
                $frameRef = 'file:' . $file->id;
            }

            $now = Format::sql(Format::now());
            $violations = [];
            foreach ($result['violations'] ?? [] as $candidate) {
                $violation = new Violation([
                    'mine_id' => $mineId,
                    'violation_type' => (string) $candidate['violation_type'],
                    'category' => 'ppe',
                    'confidence' => round((float) $candidate['confidence'], 3),
                    'source' => 'vision',
                    'frame_ref' => $frameRef,
                    'detected_at' => $now,
                    'resolved' => false,
                ]);
                if (!$violation->save()) {
                    throw ApiException::validation($violation);
                }
                AlertService::forViolation($violation);
                $violations[] = $violation;
            }

            $evidence = $result['evidence'] ?? ['accepted' => false, 'code' => 'NO_WORKERS_SEEN', 'params' => []];
            $resolved = 0;
            if (!empty($evidence['accepted'])) {
                /** @var Violation $open */
                foreach (Violation::find()->where(['mine_id' => $mineId, 'resolved' => false, 'category' => 'ppe', 'source' => 'vision'])->all() as $open) {
                    $open->resolved = true;
                    $open->resolved_at = $now;
                    $open->save(false);
                    (new CorrectiveAction([
                        'mine_id' => $mineId, 'violation_id' => $open->id, 'description' => self::SYSTEM_RESOLUTION,
                        'status' => CorrectiveAction::STATUS_RESOLVED, 'due_at' => $now, 'created_by' => $by->id,
                        'created_at' => $now, 'resolved_at' => $now, 'proof_image_path' => $frameRef,
                    ]))->save(false);
                    $resolved++;
                }
            }

            $after = ComplianceScoreService::scoreMine($mineId);
            if ($violations || $resolved) {
                ComplianceScoreService::recordIfMoved($mineId, $after, $before);
            }
            AuditChain::append('vision_run', null, 'analyze', null, [
                'backend' => $result['backend'] ?? null, 'frame_ref' => $frameRef, 'violations' => count($violations),
                'resolved' => $resolved, 'score_before' => $before->score, 'score_after' => $after->score,
            ], null, $mineId);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return [
            'mine_id' => $mineId,
            'backend' => $result['backend'] ?? 'unknown',
            'frames_processed' => (int) ($result['frames_processed'] ?? 1),
            'detections' => $result['detections'] ?? [],
            'violations' => array_map(fn(Violation $v) => $v->toArray(), $violations),
            'alerts_raised' => count($violations),
            'score_before' => $before->toArray(),
            'score_after' => $after->toArray(),
            'score_delta' => round($after->score - $before->score, 1),
            'risk_changed' => $before->riskLevel !== $after->riskLevel,
            'annotated_url' => $annotatedUrl,
            'resolved_count' => $resolved,
            'resolution_accepted' => (bool) ($evidence['accepted'] ?? false),
            'resolution' => ['code' => $evidence['code'] ?? 'NO_WORKERS_SEEN', 'params' => (object) ($evidence['params'] ?? [])],
        ];
    }
}
