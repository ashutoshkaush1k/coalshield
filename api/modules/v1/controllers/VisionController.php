<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\models\Mine;
use app\services\VisionService;
use Yii;
use yii\web\UploadedFile;

/** POST /v1/vision/analyze (multipart: mine_id, file) - PPE detection through ai-service. */
class VisionController extends ApiController
{
    protected function verbs(): array
    {
        return ['analyze' => ['POST']];
    }

    public function actionAnalyze(): array
    {
        $this->requirePermission('vision.analyze');
        $mineId = Yii::$app->request->post('mine_id');
        if (!is_numeric($mineId)) {
            throw ApiException::fields(['mine_id' => ['REQUIRED']]);
        }
        $mine = Mine::findScoped((int) $mineId);
        $file = UploadedFile::getInstanceByName('file');
        if ($file === null) {
            throw ApiException::fields(['file' => ['REQUIRED']]);
        }
        Yii::$app->response->statusCode = 201;
        return VisionService::analyze($mine, $this->currentUser(), $file);
    }
}
