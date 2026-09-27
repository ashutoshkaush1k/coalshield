<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\models\Obligation;
use app\models\ObligationSubmission;
use app\models\ObligationTask;
use app\services\ObligationService;
use Yii;
use yii\web\UploadedFile;

/**
 * The statutory obligation register (Phase 5B). Every obligation carries its citation.
 *
 *   GET  /v1/obligations                              the cited catalogue (40)
 *   GET  /v1/obligations/summary?state=               statutory compliance per mine / company / domain,
 *                                                     most overdue items (multi-mine roles)
 *   GET  /v1/obligation-tasks?mine_id=&status=&domain=&view=due_soon|overdue|submitted|accepted
 *   GET  /v1/obligation-tasks/{id}                    with submissions and history
 *   POST /v1/obligation-tasks/{id}/submissions        mine head: multipart file + note
 *   POST /v1/obligation-submissions/{id}/review       government / inspector: {decision: accept|reject, note}
 *   POST /v1/obligation-tasks/{id}/waive              government: {reason} - the mine is not bound for this period
 * Reading runs the register check first (reminders, overdue, escalation).
 */
class ObligationController extends ApiController
{
    protected function verbs(): array
    {
        return ['index' => ['GET'], 'summary' => ['GET'], 'tasks' => ['GET'], 'task' => ['GET'], 'submit' => ['POST'], 'review' => ['POST'], 'waive' => ['POST']];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('obligation.view');
        return array_map(fn(Obligation $o) => $o->toArray(), Obligation::find()->orderBy(['domain' => SORT_ASC, 'code' => SORT_ASC])->all());
    }

    public function actionSummary(): array
    {
        $this->requirePermission('obligation.summary');
        ObligationService::checkForRequest();
        return ObligationService::summary(array_map(fn($m) => (int) $m->id, $this->visibleMines(Yii::$app->request->get('state'))));
    }

    public function actionTasks(): array
    {
        $this->requirePermission('obligation.view');
        ObligationService::checkForRequest();
        $request = Yii::$app->request;
        $query = $this->scopedList(ObligationTask::find())->with(['obligation', 'mine', 'latestSubmission.submitter', 'latestSubmission.reviewer', 'latestSubmission.file']);
        $now = \app\components\Format::now();
        $reminder = (int) ObligationService::settings()['reminder_days'];
        match ((string) $request->get('view', '')) {
            'due_soon' => $query->andWhere(['obligation_task.status' => ['open', 'rejected']])
                ->andWhere(['between', 'obligation_task.due_at', \app\components\Format::sql($now), \app\components\Format::sql($now->modify("+{$reminder} days"))]),
            'open' => $query->andWhere(['obligation_task.status' => ['open', 'rejected']]),
            'overdue' => $query->andWhere(['obligation_task.status' => ObligationTask::LATE]),
            'submitted' => $query->andWhere(['obligation_task.status' => 'submitted']),
            'accepted' => $query->andWhere(['obligation_task.status' => 'accepted']),
            '' => null,
            default => throw ApiException::fields(['view' => ['INVALID_VALUE']]),
        };
        if (($state = $request->get('state')) !== null && $state !== '') {
            $query->andWhere(['obligation_task.mine_id' => array_map(fn($m) => (int) $m->id, $this->visibleMines((string) $state))]);
        }
        if (($status = $request->get('status')) !== null && $status !== '') {
            $query->andWhere(['obligation_task.status' => explode(',', (string) $status)]);
        }
        if (($domain = $request->get('domain')) !== null && $domain !== '') {
            $query->andWhere(['obligation_task.obligation_id' => Obligation::find()->select('id')->where(['domain' => $domain])]);
        }
        $order = $request->get('view') === 'accepted' ? SORT_DESC : SORT_ASC;
        $perPage = max(1, min((int) $request->get('per_page', 50), 200));
        $total = (int) (clone $query)->count();
        Yii::$app->response->headers->set('X-Total-Count', (string) $total);
        $rows = $query->orderBy(['obligation_task.due_at' => $order, 'obligation_task.id' => SORT_ASC])->limit($perPage)->all();
        return array_map(fn(ObligationTask $t) => $t->toArray(), $rows);
    }

    public function actionTask(int $id): array
    {
        $this->requirePermission('obligation.view');
        return ObligationTask::findScoped($id)->toArray([], ['submissions', 'history']);
    }

    public function actionSubmit(int $id): array
    {
        $this->requirePermission('obligation.submit');
        $task = ObligationTask::findScoped($id);
        $note = (string) (Yii::$app->request->post('note') ?? $this->body()['note'] ?? '');
        ObligationService::submit($task, $this->currentUser(), UploadedFile::getInstanceByName('file'), $note);
        Yii::$app->response->statusCode = 201;
        return ObligationTask::findScoped($id)->toArray([], ['submissions', 'history']);
    }

    public function actionReview(int $id): array
    {
        $this->requirePermission('obligation.review');
        $submission = ObligationSubmission::findScoped($id);
        $body = $this->body();
        ObligationService::review($submission, $this->currentUser(), (string) ($body['decision'] ?? ''), (string) ($body['note'] ?? ''));
        return ObligationTask::findScoped((int) $submission->task_id)->toArray([], ['submissions', 'history']);
    }

    public function actionWaive(int $id): array
    {
        $this->requirePermission('obligation.waive');
        ObligationService::waive(ObligationTask::findScoped($id), (string) ($this->body()['reason'] ?? ''));
        return ObligationTask::findScoped($id)->toArray([], ['submissions', 'history']);
    }
}
