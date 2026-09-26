<?php

declare(strict_types=1);

namespace app\components;

use Yii;

/**
 * The one workflow engine (brief rule 5). A model implementing HasStatusTransitions declares its
 * status attribute and allowed transitions; apply() rejects anything else with
 * 422 {"error": {"code": "INVALID_TRANSITION", "params": {"from": ..., "to": ...}}}, saves the model
 * (AuditBehavior logs the change) and lets the model write its own history row, all in one
 * transaction.
 */
final class StatusTransition
{
    public static function canTransition(HasStatusTransitions $model, string $to): bool
    {
        $from = (string) $model->getAttribute($model::statusAttribute());
        return in_array($to, $model::transitions()[$from] ?? [], true);
    }

    /** @param array<string, mixed> $context free-form data for the history row (reason, note, ...) */
    public static function apply(HasStatusTransitions&ActiveRecord $model, string $to, array $context = []): void
    {
        $attribute = $model::statusAttribute();
        $from = (string) $model->getAttribute($attribute);
        if (!self::canTransition($model, $to)) {
            throw new ApiException(422, 'INVALID_TRANSITION', ['from' => $from, 'to' => $to]);
        }

        $transaction = $model::getDb()->beginTransaction();
        try {
            $model->setAttribute($attribute, $to);
            if (!$model->save()) {
                throw ApiException::validation($model);
            }
            $identity = Yii::$app->has('user', true) ? Yii::$app->user->identity : null;
            $model->recordTransition($from, $to, $identity?->getId() === null ? null : (int) $identity->getId(), $context);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
}
