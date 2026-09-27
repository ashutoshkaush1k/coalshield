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
 *                                                 -> {ticket_no, tracking_code (shown once), status, sla_due_at, created_at}
 *   POST /v1/grievances/track                     {ticket_no, tracking_code} -> status and public timeline only;
 *                                                 a wrong code is the same 404 as an unknown ticket
 */
class GrievancePublicController extends ApiController
{
    protected array $publicActions = ['mines', 'submit', 'track'];

    protected function verbs(): array
    {
        return ['mines' => ['GET'], 'submit' => ['POST'], 'track' => ['POST']];
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
        [$grievance, $trackingCode] = GrievanceService::submitPublic($data, UploadedFile::getInstanceByName('file'));
        Yii::$app->response->statusCode = 201;
        return [
            'ticket_no' => $grievance->ticket_no,
            // Shown once: only an HMAC of it is stored, so it cannot be looked up or sent again.
            'tracking_code' => $trackingCode,
            'status' => $grievance->status,
            'sla_due_at' => \app\components\Format::utc($grievance->sla_due_at),
            'created_at' => \app\components\Format::utc($grievance->created_at),
        ];
    }

    /** POST, so the code never appears in a URL, a proxy log or the browser history. */
    public function actionTrack(): array
    {
        RateLimiter::hit('grievance.track|' . RateLimiter::clientKey(), (int) Yii::$app->params['grievance.trackPerMinute'], 60);
        $body = $this->body();
        return GrievanceService::track((string) ($body['ticket_no'] ?? ''), (string) ($body['tracking_code'] ?? ''));
    }
}
