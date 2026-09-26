<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\Format;
use app\models\User;
use Yii;
use yii\db\Query;

/**
 * GET /v1/audit - the audit trail, newest first, scoped like everything else: government and
 * inspector see all entries, corporate its company's mines, a mine head its own mine. Entries
 * without a mine (logins of other users, seed) are visible to government only.
 * ?mine_id=, ?entity=, ?action=, page/per_page. Values are raw; the frontend labels them.
 */
class AuditController extends ApiController
{
    protected function verbs(): array
    {
        return ['index' => ['GET']];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('audit.view');
        $request = Yii::$app->request;
        $user = $this->currentUser();
        $query = (new Query())->from(['a' => '{{%audit_log}}'])
            ->leftJoin(['u' => '{{%user}}'], 'u.id = a.user_id')
            ->select(['a.id', 'a.mine_id', 'a.entity', 'a.entity_id', 'a.action', 'a.old_values', 'a.new_values',
                'a.user_id', 'actor' => 'u.full_name', 'a.created_at', 'a.row_hash']);

        $mine = $this->mineParam();
        if ($mine !== null) {
            $query->andWhere(['a.mine_id' => $mine->id]);
        } elseif (!in_array($user->role, [User::ROLE_GOVERNMENT, User::ROLE_INSPECTOR], true)) {
            $query->andWhere(['a.mine_id' => array_map(fn($m) => (int) $m->id, $this->visibleMines())]);
        }
        $query->andFilterWhere(['a.entity' => $request->get('entity'), 'a.action' => $request->get('action')]);

        $perPage = max(1, min((int) ($request->get('per_page') ?? $request->get('limit') ?? 50), 200));
        $page = max(1, (int) $request->get('page', 1));
        $total = (int) (clone $query)->count('*');
        $rows = $query->orderBy(['a.id' => SORT_DESC])->offset(($page - 1) * $perPage)->limit($perPage)->all();

        $headers = Yii::$app->response->headers;
        $headers->set('X-Total-Count', (string) $total);
        $headers->set('X-Page', (string) $page);
        $headers->set('X-Per-Page', (string) $perPage);
        return array_map(fn($r) => [
            'id' => (int) $r['id'],
            'mine_id' => Format::int($r['mine_id']),
            'entity' => $r['entity'],
            'entity_id' => Format::int($r['entity_id']),
            'action' => $r['action'],
            'old_values' => $r['old_values'] === null ? null : json_decode($r['old_values'], true),
            'new_values' => $r['new_values'] === null ? null : json_decode($r['new_values'], true),
            'user_id' => Format::int($r['user_id']),
            'actor' => $r['actor'],
            'created_at' => Format::utc($r['created_at']),
            'row_hash' => $r['row_hash'],
        ], $rows);
    }
}
