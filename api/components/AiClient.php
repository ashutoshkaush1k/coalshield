<?php

declare(strict_types=1);

namespace app\components;

use Yii;
use yii\httpclient\Client;

/**
 * HTTP client for ai-service (brief section 2). Short timeout; any failure becomes
 * AiUnavailableException so callers can degrade instead of crashing.
 */
final class AiClient
{
    /** @return array decoded /vision/ppe response */
    public static function ppe(string $path, string $originalName): array
    {
        $params = Yii::$app->params;
        try {
            $response = (new Client(['baseUrl' => rtrim($params['ai.baseUrl'], '/')]))
                ->post('vision/ppe', ['filename' => $originalName])
                ->addFile('file', $path, ['fileName' => $originalName])
                ->setOptions(['timeout' => $params['ai.timeoutSeconds'], 'connectTimeout' => 3])
                ->send();
        } catch (\Throwable $e) {
            Yii::warning('ai-service unreachable: ' . $e->getMessage(), __METHOD__);
            throw new AiUnavailableException('unreachable', 0, $e);
        }
        if ($response->statusCode === '422') {
            $detail = $response->data['detail'] ?? [];
            throw new ApiException(422, 'VALIDATION_FAILED', [], ['file' => [is_array($detail) ? ($detail['code'] ?? 'INVALID_FILE') : 'INVALID_FILE']]);
        }
        if (!$response->isOk || !is_array($response->data)) {
            Yii::warning('ai-service error ' . $response->statusCode, __METHOD__);
            throw new AiUnavailableException('status ' . $response->statusCode);
        }
        return $response->data;
    }
}
