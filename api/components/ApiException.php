<?php

declare(strict_types=1);

namespace app\components;

use yii\base\Model;
use yii\web\HttpException;

/**
 * An API error carrying a machine code and parameters, never display text (brief rule 7).
 * Rendered by ApiErrorHandler as {"error": {"code": ..., "params"?: ..., "fields"?: ...}}.
 */
class ApiException extends HttpException
{
    /** @param array<string, mixed> $params @param array<string, string[]> $fields */
    public function __construct(
        int $status,
        public readonly string $errorCode,
        public readonly array $params = [],
        public readonly array $fields = [],
    ) {
        parent::__construct($status, $errorCode);
    }

    /** 422 VALIDATION_FAILED with the model's errors as {attribute: [CODE, ...]}. */
    public static function validation(Model $model): self
    {
        $fields = [];
        foreach ($model->getErrors() as $attribute => $messages) {
            $fields[$attribute] = array_values(array_unique($messages));
        }
        return new self(422, 'VALIDATION_FAILED', [], $fields);
    }

    /** @param array<string, string[]> $fields */
    public static function fields(array $fields): self
    {
        return new self(422, 'VALIDATION_FAILED', [], $fields);
    }

    public static function notFound(): self
    {
        return new self(404, 'NOT_FOUND');
    }

    public static function forbidden(): self
    {
        return new self(403, 'FORBIDDEN');
    }
}
