<?php

declare(strict_types=1);

namespace app\services;

use app\components\ApiException;
use app\components\Format;
use app\components\Rules;
use app\components\StatusTransition;
use app\models\Alert;
use app\models\Mine;
use app\models\ProductionDetailRequest;
use app\models\User;
use Yii;
use yii\web\UploadedFile;

/**
 * "Call for Detailed Report" (brief Phase 4): a regulator's demand for a mine's detailed
 * production records over a date range, with a deadline.
 *
 * Escalation is automatic (escalateDue): a request still waiting at its due time becomes overdue
 * and raises DETAIL_REQUEST_OVERDUE (escalation level 1); still waiting
 * product.detail_request_escalate_after_hours later it becomes escalated and the alert moves to
 * level 2. It runs whenever requests or the production summary are read, and from
 * `yii production/check` - idempotent, so nothing depends on a scheduler being set up.
 * Answering the request resolves its overdue alert.
 */
final class DetailRequestService
{
    public const MAX_RANGE_DAYS = 92;
    public const MAX_DUE_DAYS = 60;

    public static function create(User $by, Mine $mine, array $data): ProductionDetailRequest
    {
        $request = new ProductionDetailRequest([
            'mine_id' => $mine->id,
            'requested_by' => $by->id,
            'date_from' => (string) ($data['date_from'] ?? ''),
            'date_to' => (string) ($data['date_to'] ?? ''),
            'reason' => trim((string) ($data['reason'] ?? '')),
            'status' => ProductionDetailRequest::STATUS_PENDING,
        ]);
        $errors = [];
        $due = self::parseTime($data['due_at'] ?? null);
        if ($due === null) {
            $errors['due_at'] = ['INVALID_DATETIME'];
        } elseif ($due <= Format::now()) {
            $errors['due_at'] = ['IN_PAST'];
        } elseif ($due > Format::now()->modify('+' . self::MAX_DUE_DAYS . ' days')) {
            $errors['due_at'] = ['TOO_FAR'];
        } else {
            $request->due_at = Format::sql($due);
        }
        $request->validate();
        foreach ($request->getErrors() as $field => $codes) {
            $errors[$field] = array_values(array_unique(array_merge($errors[$field] ?? [], $codes)));
        }
        if (!isset($errors['date_to']) && $request->date_to > ProductionService::today()) {
            $errors['date_to'] = ['IN_FUTURE'];
        }
        if (!isset($errors['date_from']) && !isset($errors['date_to'])
            && (new \DateTimeImmutable($request->date_from))->diff(new \DateTimeImmutable($request->date_to))->days >= self::MAX_RANGE_DAYS) {
            $errors['date_to'] = ['RANGE_TOO_LONG'];
        }
        unset($errors['requested_by'], $errors['mine_id']);
        if ($errors !== []) {
            throw ApiException::fields($errors);
        }
        $request->created_at = Format::sql(Format::now());
        $request->save(false);
        return $request;
    }

    /** The mine head answers: a note, optionally a file. Also a late answer to an overdue request. */
    public static function respond(ProductionDetailRequest $request, User $by, string $note, ?UploadedFile $file): ProductionDetailRequest
    {
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw ApiException::fields(['response_note' => [$note === '' ? 'REQUIRED' : 'TOO_SHORT']]);
        }
        if (mb_strlen($note) > 2000) {
            throw ApiException::fields(['response_note' => ['TOO_LONG']]);
        }
        if (!StatusTransition::canTransition($request, ProductionDetailRequest::STATUS_SUBMITTED)) {
            throw new ApiException(422, 'INVALID_TRANSITION', ['from' => $request->status, 'to' => ProductionDetailRequest::STATUS_SUBMITTED]);
        }
        $late = $request->status !== ProductionDetailRequest::STATUS_PENDING;
        $transaction = Yii::$app->db->beginTransaction();
        try {
            if ($file !== null) {
                $request->response_file_id = (int) Yii::$app->fileStorage->storeUpload($file, 'production_detail_request', (int) $request->id, (int) $by->id)->id;
            }
            $request->response_note = $note;
            $request->responded_by = $by->id;
            $request->responded_at = Format::sql(Format::now());
            StatusTransition::apply($request, ProductionDetailRequest::STATUS_SUBMITTED, array_filter(['late' => $late ?: null]));
            foreach (self::openAlerts([(int) $request->id]) as $alert) {
                StatusTransition::apply($alert, Alert::STATUS_RESOLVED, ['code' => 'DETAIL_REQUEST_ANSWERED', 'request_id' => (int) $request->id]);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return $request;
    }

    /** The requesting side accepts the response. */
    public static function close(ProductionDetailRequest $request, ?string $note): ProductionDetailRequest
    {
        $note = $note === null ? null : trim($note);
        if ($note !== null && mb_strlen($note) > 500) {
            throw ApiException::fields(['note' => ['TOO_LONG']]);
        }
        StatusTransition::apply($request, ProductionDetailRequest::STATUS_CLOSED, array_filter(['note' => $note ?: null]));
        return $request;
    }

    /**
     * Move waiting requests past their deadline to overdue, and overdue ones past the escalation
     * window to escalated, raising or raising the level of their DETAIL_REQUEST_OVERDUE alert.
     * @return array{overdue: int, escalated: int}
     */
    public static function escalateDue(?\DateTimeImmutable $now = null): array
    {
        $now ??= Format::now();
        $hours = (int) Rules::value('product', 'detail_request_escalate_after_hours');
        $done = ['overdue' => 0, 'escalated' => 0];
        $due = ProductionDetailRequest::find()
            ->where(['or',
                ['and', ['status' => ProductionDetailRequest::STATUS_PENDING], ['<', 'due_at', Format::sql($now)]],
                ['and', ['status' => ProductionDetailRequest::STATUS_OVERDUE], ['<', 'due_at', Format::sql($now->modify("-{$hours} hours"))]],
            ])->orderBy('id')->all();
        if ($due === []) {
            return $done;
        }
        $alerts = [];
        foreach (self::openAlerts(array_map(fn($r) => (int) $r->id, $due)) as $alert) {
            $alerts[(int) $alert->entity_id] = $alert;
        }
        // A system action: the history and the audit chain must not credit whoever happened to
        // load the page that ran the check.
        $webUser = Yii::$app->has('user', true) ? Yii::$app->user : null;
        $identity = $webUser?->getIdentity(false);
        $webUser?->setIdentity(null);
        $transaction = Yii::$app->db->beginTransaction();
        try {
            foreach ($due as $request) {
                $params = ['request_id' => (int) $request->id, 'due_at' => Format::utc($request->due_at),
                    'date_from' => $request->date_from, 'date_to' => $request->date_to];
                if ($request->status === ProductionDetailRequest::STATUS_PENDING) {
                    StatusTransition::apply($request, ProductionDetailRequest::STATUS_OVERDUE, ['code' => 'DEADLINE_PASSED']);
                    if (!isset($alerts[(int) $request->id])) {
                        $alert = AlertService::create((int) $request->mine_id, 'DETAIL_REQUEST_OVERDUE', 'high', 'production_detail_request', (int) $request->id, $params);
                        $alert->escalation_level = 1;
                        $alert->save(false);
                        $alerts[(int) $request->id] = $alert;
                    }
                    $done['overdue']++;
                    // A request already far past its deadline (e.g. created while the checks were
                    // not running) escalates in the same pass.
                    if (new \DateTimeImmutable($request->due_at) >= $now->modify("-{$hours} hours")) {
                        continue;
                    }
                }
                StatusTransition::apply($request, ProductionDetailRequest::STATUS_ESCALATED, ['code' => 'ESCALATED', 'after_hours' => $hours]);
                $alert = $alerts[(int) $request->id] ?? AlertService::create((int) $request->mine_id, 'DETAIL_REQUEST_OVERDUE', 'high', 'production_detail_request', (int) $request->id, $params);
                $alert->escalation_level = max(2, (int) $alert->escalation_level);
                $alert->save(false);
                $done['escalated']++;
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        } finally {
            $webUser?->setIdentity($identity);
        }
        return $done;
    }

    /** @return Alert[] unresolved DETAIL_REQUEST_OVERDUE alerts of these requests */
    private static function openAlerts(array $requestIds): array
    {
        return Alert::find()->where(['code' => 'DETAIL_REQUEST_OVERDUE', 'entity_type' => 'production_detail_request', 'entity_id' => $requestIds])
            ->andWhere(['<>', 'status', Alert::STATUS_RESOLVED])->all();
    }

    private static function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || strtotime($value) === false) {
            return null;
        }
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
    }
}
