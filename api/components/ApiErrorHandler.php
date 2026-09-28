<?php

declare(strict_types=1);

namespace app\components;

use Yii;
use yii\web\ErrorHandler;
use yii\web\HttpException;
use yii\web\Response;

/**
 * One error format everywhere (brief rule 7):
 *   {"error": {"code": "VALIDATION_FAILED", "fields": {"coal_actual_t": ["REQUIRED"]}}}
 * Codes only; the frontend translates them. With YII_DEBUG and API_DEBUG_ERRORS=1 a "debug" object carries the exception
 * class and message for developers - never shown to users.
 */
class ApiErrorHandler extends ErrorHandler
{
    private const STATUS_CODES = [
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHENTICATED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        405 => 'METHOD_NOT_ALLOWED',
        409 => 'CONFLICT',
        415 => 'UNSUPPORTED_MEDIA_TYPE',
        422 => 'VALIDATION_FAILED',
        429 => 'RATE_LIMITED',
    ];

    protected function renderException($exception): void
    {
        $response = Yii::$app->has('response') ? Yii::$app->getResponse() : new Response();
        $response->format = Response::FORMAT_JSON;
        $response->data = $this->toArray($exception);
        $response->setStatusCode($exception instanceof HttpException ? $exception->statusCode : 500);
        $response->send();
    }

    /** @return array{error: array<string, mixed>} */
    public function toArray(\Throwable $exception): array
    {
        if ($exception instanceof ApiException) {
            $error = ['code' => $exception->errorCode];
            if ($exception->params) {
                $error['params'] = $exception->params;
            }
            if ($exception->fields) {
                $error['fields'] = $exception->fields;
            }
        } elseif ($exception instanceof HttpException) {
            $error = ['code' => self::STATUS_CODES[$exception->statusCode] ?? 'HTTP_' . $exception->statusCode];
        } else {
            $error = ['code' => 'INTERNAL_ERROR'];
            Yii::error((string) $exception, __METHOD__);
        }
        // Exception details only when explicitly enabled (API_DEBUG_ERRORS=1 in api\.env): a database
        // error's message can quote SQL, and the field server puts the API on the LAN. Never a trace.
        if (YII_DEBUG && (Yii::$app->params['api.debugErrors'] ?? false)) {
            $error['debug'] = ['exception' => get_class($exception), 'message' => $exception->getMessage()];
        }
        return ['error' => $error];
    }
}
