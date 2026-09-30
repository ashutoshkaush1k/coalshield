<?php

declare(strict_types=1);

namespace app\components;

use Yii;
use yii\base\Component;
use yii\httpclient\Client;

/**
 * One private Supabase Storage bucket, through its REST API with the project's secret (service) key.
 * Used by FileStorage online (STORAGE_DRIVER=supabase, docs/DEPLOYMENT.md): Render's disk is wiped
 * on every restart, so the bytes live here. The key never leaves the server and is never logged.
 */
class SupabaseBucket extends Component
{
    public string $url = '';
    public string $key = '';
    public string $bucket = 'files';
    public float $timeout = 30;

    public function init(): void
    {
        parent::init();
        if ($this->url === '' || $this->key === '') {
            throw new \yii\base\InvalidConfigException('STORAGE_DRIVER=supabase needs SUPABASE_URL and SUPABASE_SERVICE_KEY');
        }
    }

    /** Upload (or overwrite) one object. */
    public function put(string $path, string $sourcePath, string $mime): void
    {
        $response = $this->client()->post($this->objectPath($path), (string) file_get_contents($sourcePath), $this->headers() + [
            'Content-Type' => $mime,
            'x-upsert' => 'true',
        ])->setOptions(['timeout' => $this->timeout])->send();
        if (!$response->isOk) {
            Yii::warning("storage upload failed: status {$response->statusCode}", __METHOD__);
            throw new \RuntimeException('Cannot write stored file');
        }
    }

    /** The object's bytes, or null when it does not exist. */
    public function get(string $path): ?string
    {
        $response = $this->client()->get($this->objectPath($path), null, $this->headers())
            ->setOptions(['timeout' => $this->timeout])->send();
        if ($response->isOk) {
            return $response->content;
        }
        if (!in_array($response->statusCode, ['400', '404'], true)) {
            Yii::warning("storage read failed: status {$response->statusCode}", __METHOD__);
        }
        return null;
    }

    private function client(): Client
    {
        return new Client(['baseUrl' => rtrim($this->url, '/') . '/storage/v1']);
    }

    private function objectPath(string $path): string
    {
        return 'object/' . rawurlencode($this->bucket) . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** Legacy keys are JWTs and go in both headers; the newer secret keys (sb_secret_...) only in apikey. */
    private function headers(): array
    {
        $headers = ['apikey' => $this->key];
        if (str_starts_with($this->key, 'eyJ')) {
            $headers['Authorization'] = 'Bearer ' . $this->key;
        }
        return $headers;
    }
}
