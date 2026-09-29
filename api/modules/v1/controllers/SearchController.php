<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\models\Contractor;
use app\models\Grievance;
use app\models\Mine;
use app\models\Obligation;
use Yii;

/**
 * Global search for the dashboards' search panel (government and corporate: `search.global`).
 *
 *   GET /v1/search?q=  -> {q, mines, contractors, grievances, obligations}, at most 5 of each
 *
 * Read-only. Every group goes through the same scoping as its own list endpoint
 * (ScopedActiveQuery::forCurrentUser): corporate finds only its companies' mines, contractors and
 * grievances, and a sensitive grievance appears only for a role that may see it. Obligations are
 * the statutory catalogue, the same for everyone. Matches are case-insensitive substrings; a query
 * shorter than 2 characters returns empty groups.
 */
class SearchController extends ApiController
{
    private const LIMIT = 5;
    private const MIN_LENGTH = 2;

    protected function verbs(): array
    {
        return ['index' => ['GET']];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('search.global');
        $q = trim((string) Yii::$app->request->get('q', ''));
        $out = ['q' => $q, 'mines' => [], 'contractors' => [], 'grievances' => [], 'obligations' => []];
        if (mb_strlen($q) < self::MIN_LENGTH) {
            return $out;
        }
        // Yii escapes % and _ in the value and wraps it in %...% for a LIKE condition.
        $like = fn(string ...$columns) => ['or', ...array_map(fn($c) => ['ilike', $c, $q], $columns)];

        $mines = Mine::find()->forCurrentUser()->joinWith('subsidiary s')
            ->andWhere($like('{{%mine}}.name', '{{%mine}}.code', '{{%mine}}.district', '{{%mine}}.state', 's.name', 's.code'))
            ->orderBy(['{{%mine}}.name' => SORT_ASC])->limit(self::LIMIT)->all();
        $out['mines'] = array_map(fn(Mine $m) => [
            'id' => (int) $m->id, 'code' => $m->code, 'name' => $m->name, 'district' => $m->district, 'state' => $m->state,
            'operator' => $m->subsidiary?->code,
        ], $mines);

        $contractors = Contractor::find()->forCurrentUser()
            ->andWhere($like('{{%contractor}}.name', '{{%contractor}}.registration_no'))
            ->orderBy(['{{%contractor}}.name' => SORT_ASC])->limit(self::LIMIT)->all();
        $out['contractors'] = array_map(fn(Contractor $c) => [
            'id' => (int) $c->id, 'name' => $c->name, 'registration_no' => $c->registration_no,
        ], $contractors);

        $grievances = Grievance::find()->forCurrentUser()->with('mine')
            ->andWhere($like('{{%grievance}}.ticket_no'))
            ->orderBy(['{{%grievance}}.id' => SORT_DESC])->limit(self::LIMIT)->all();
        $out['grievances'] = array_map(fn(Grievance $g) => [
            'id' => (int) $g->id, 'ticket_no' => $g->ticket_no, 'category' => $g->category, 'status' => $g->status,
            'mine_id' => (int) $g->mine_id, 'mine_name' => $g->mine?->name,
        ], $grievances);

        if (Yii::$app->user->can('obligation.view')) {
            $obligations = Obligation::find()->andWhere($like('code', 'title'))
                ->orderBy(['code' => SORT_ASC])->limit(self::LIMIT)->all();
            $out['obligations'] = array_map(fn(Obligation $o) => [
                'id' => (int) $o->id, 'code' => $o->code, 'title' => $o->title, 'domain' => $o->domain,
            ], $obligations);
        }
        return $out;
    }
}
