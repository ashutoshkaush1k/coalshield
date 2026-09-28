<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\models\Mine;
use app\models\User;
use app\services\ComplianceScoreService;
use app\services\InspectionPriorityService;
use Yii;
use yii\db\Query;

/**
 * GET /v1/dashboard?state= - one endpoint, three shapes (ported from the prototype):
 * national or state overview for multi-mine roles, own-mine summary for a mine head.
 * `stats` covers every mine in scope; `mines` is capped nationally to the worst few.
 */
class DashboardController extends ApiController
{
    protected function verbs(): array
    {
        return ['index' => ['GET']];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('dashboard.view');
        $user = $this->currentUser();
        $params = Yii::$app->params;
        $state = Yii::$app->request->get('state') ?: null;
        $multiMine = $user->role !== User::ROLE_MINE_HEAD;

        $inScope = $this->visibleMines($state);
        $ids = array_map(fn(Mine $m) => (int) $m->id, $inScope);
        $results = ComplianceScoreService::scoreMines($ids);

        $ordered = $inScope;
        usort($ordered, fn(Mine $a, Mine $b) => [$results[$a->id]->score, $a->id] <=> [$results[$b->id]->score, $b->id]);
        $limit = (int) $params['dashboard.nationalBoardLimit'];
        $truncated = $multiMine && $state === null && count($ordered) > $limit;
        $shown = $truncated ? array_slice($ordered, 0, $limit) : $ordered;
        $alerts = ComplianceScoreService::openAlertCounts(array_map(fn(Mine $m) => (int) $m->id, $shown));

        $queue = [];
        if ($multiMine && Yii::$app->user->can('inspection.viewQueue')) {
            $queue = array_slice(InspectionPriorityService::queue($inScope, null, $this->currentUser()), 0, (int) $params['dashboard.inspectionPreview']);
        }
        $states = $multiMine
            ? (new Query())->select('state')->distinct()->from('{{%mine}}')
                ->where(['id' => array_map(fn(Mine $m) => (int) $m->id, $this->visibleMines())])
                ->orderBy('state')->column()
            : [];

        return [
            'role' => $user->role,
            'scope' => $multiMine ? ($user->role === User::ROLE_CORPORATE ? 'subsidiary' : 'all_mines') : 'mine',
            'state' => $state,
            'scope_label_code' => $state !== null ? 'STATE' : ($multiMine ? ($user->role === User::ROLE_CORPORATE ? 'SUBSIDIARY' : 'NATIONAL') : 'THIS_MINE'),
            'states' => $states,
            'stats' => ComplianceScoreService::fleetStats($results),
            'mines' => array_map(fn(Mine $m) => $m->toArray() + [
                'compliance' => $results[$m->id]->toArray(),
                'open_alerts' => $alerts[$m->id] ?? 0,
            ], $shown),
            'showing' => count($shown),
            'is_truncated' => $truncated,
            'inspection_queue' => $queue,
        ];
    }
}
