<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\models\File;
use Yii;
use yii\web\Response;

/**
 * GET /v1/files/{id}/content?expires=&signature= - a stored file through a signed, time-limited
 * link (FileStorage::signedUrl), so an <img> can show an annotated frame or a proof image without
 * a bearer token. No valid signature -> 404, as if the file did not exist.
 */
class FileController extends ApiController
{
    protected array $publicActions = ['content'];

    protected function verbs(): array
    {
        return ['content' => ['GET']];
    }

    public function actionContent(int $id): Response
    {
        $request = Yii::$app->request;
        $storage = Yii::$app->fileStorage;
        $file = File::findOne($id);
        if ($file === null || !$storage->validSignature($id, (int) $request->get('expires'), (string) $request->get('signature'))) {
            throw ApiException::notFound();
        }
        $path = $storage->absolutePath($file->path);
        if (!is_file($path)) {
            throw ApiException::notFound();
        }
        $response = Yii::$app->response;
        $response->headers->set('Cache-Control', 'private, max-age=300');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response->sendFile($path, basename($file->path), ['mimeType' => $file->mime, 'inline' => true]);
    }
}
