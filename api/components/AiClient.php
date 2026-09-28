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
    /**
     * POST a JSON payload (the detectors, the risk model); the decoded JSON response. Retries once on
     * a connection failure (the service may be starting), then gives up with AiUnavailableException.
     */
    /** GET /health, once, with a short timeout: the service's answer, or [] when it does not answer. */
    public static function health(float $timeout = 1.5): array
    {
        try {
            $response = (new Client(['baseUrl' => rtrim(Yii::$app->params['ai.baseUrl'], '/'),
                'responseConfig' => ['format' => Client::FORMAT_JSON]]))
                ->get('health')->setOptions(['timeout' => $timeout, 'connectTimeout' => 1])->send();
            return $response->isOk && is_array($response->data) ? $response->data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public static function postJson(string $path, array $payload, ?float $timeout = null): array
    {
        $params = Yii::$app->params;
        $timeout ??= (float) ($params['ai.detectorTimeoutSeconds'] ?? 30);
        $last = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = (new Client(['baseUrl' => rtrim($params['ai.baseUrl'], '/'), 'requestConfig' => ['format' => Client::FORMAT_JSON],
                    'responseConfig' => ['format' => Client::FORMAT_JSON]]))
                    ->post(ltrim($path, '/'), $payload)
                    ->setOptions(['timeout' => $timeout, 'connectTimeout' => 2])
                    ->send();
            } catch (\Throwable $e) {
                $last = $e;
                if ($attempt === 1) {
                    usleep(300_000);
                }
                continue;
            }
            if (!$response->isOk || !is_array($response->data)) {
                Yii::warning("ai-service $path: status " . $response->statusCode, __METHOD__);
                throw new AiUnavailableException('status ' . $response->statusCode);
            }
            return $response->data;
        }
        Yii::warning("ai-service $path unreachable: " . $last?->getMessage(), __METHOD__);
        throw new AiUnavailableException('unreachable', 0, $last);
    }

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
