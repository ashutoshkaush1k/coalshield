<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\RateLimiter;
use app\models\Mine;
use app\services\GrievanceService;
use Yii;
use yii\web\UploadedFile;

/**
 * The public, unauthenticated grievance endpoints (brief Phase 5), rate-limited per client IP:
 *
 *   GET  /v1/public/mines                         mines to choose from (code, name, district, state)
 *   POST /v1/grievances/public                    multipart or JSON: mine_id, submitter_type, name?,
 *                                                 contact?, is_anonymous, category, safety_category (safety),
 *                                                 language, description, against_mine_head, latitude?,
 *                                                 longitude?, file? (PDF/JPEG/PNG, 5 MB), website (honeypot)
 *                                                 -> {ticket_no, status, sla_due_at, created_at}
 *   GET  /v1/grievances/track/{ticket_no}         status and public timeline only
 */
class GrievancePublicController extends ApiController
{
    protected array $publicActions = ['mines', 'submit', 'track'];

    protected function verbs(): array
    {
        return ['mines' => ['GET'], 'submit' => ['POST'], 'track' => ['GET']];
    }

    public function actionMines(): array
    {
        RateLimiter::hit('public.mines|' . RateLimiter::clientKey(), 60, 60);
        return array_map(fn(Mine $m) => ['id' => (int) $m->id, 'code' => $m->code, 'name' => $m->name, 'district' => $m->district, 'state' => $m->state],
            Mine::find()->orderBy(['mine.state' => SORT_ASC, 'mine.name' => SORT_ASC])->all());
    }

    public function actionSubmit(): array
    {
        $params = Yii::$app->params;
        RateLimiter::hit('grievance.submit|' . RateLimiter::clientKey(), (int) $params['grievance.submitPerHour'], 3600);
        $request = Yii::$app->request;
        $data = array_merge($this->body(), $request->post());
        $grievance = GrievanceService::submitPublic($data, UploadedFile::getInstanceByName('file'));
        Yii::$app->response->statusCode = 201;
        return [
            'ticket_no' => $grievance->ticket_no,
            'status' => $grievance->status,
            'sla_due_at' => \app\components\Format::utc($grievance->sla_due_at),
            'created_at' => \app\components\Format::utc($grievance->created_at),
        ];
    }

    public function actionTrack(string $ticket): array
    {
        RateLimiter::hit('grievance.track|' . RateLimiter::clientKey(), (int) Yii::$app->params['grievance.trackPerMinute'], 60);
        return GrievanceService::track($ticket);
    }
}
