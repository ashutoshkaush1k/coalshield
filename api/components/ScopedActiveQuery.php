<?php

declare(strict_types=1);

namespace app\components;

use app\models\User;
use Yii;
use yii\db\ActiveQuery;
use yii\db\Expression;

/**
 * Mine scoping in exactly one place (brief rule 2):
 *   government, inspector -> all mines
 *   corporate            -> mines of the user's subsidiary
 *   mine_head            -> the user's own mine
 * A scoped model declares scopePath(): the column that holds a mine id ('mine_id', or 'id' for the
 * mine table itself), or 'relation.column' when the mine is reached through a relation
 * (e.g. 'contract.mine_id'). Unknown roles see nothing.
 */
class ScopedActiveQuery extends ActiveQuery
{
    public function forCurrentUser(): static
    {
        $identity = Yii::$app->has('user', true) ? Yii::$app->user->identity : null;
        if (!$identity instanceof User) {
            return $this->andWhere(new Expression('FALSE'));
        }
        return $this->forUser($identity);
    }

    public function forUser(User $user): static
    {
        /** @var class-string<ScopedActiveRecord> $modelClass */
        $modelClass = $this->modelClass;
        $path = $modelClass::scopePath();
        if (str_contains($path, '.')) {
            [$relation, $column] = explode('.', $path, 2);
            $this->joinWith($relation, false);
            $target = $this->getRelatedTable($relation) . '.' . $column;
        } else {
            $target = $modelClass::tableName() . '.' . $path;
        }

        return match ($user->role) {
            User::ROLE_GOVERNMENT, User::ROLE_INSPECTOR => $this,
            User::ROLE_CORPORATE => $user->subsidiary_id === null
                ? $this->andWhere(new Expression('FALSE'))
                : $this->andWhere(['in', $target,
                    (new \yii\db\Query())->select('id')->from('{{%mine}}')->where(['subsidiary_id' => $user->subsidiary_id])]),
            User::ROLE_MINE_HEAD => $user->mine_id === null
                ? $this->andWhere(new Expression('FALSE'))
                : $this->andWhere([$target => $user->mine_id]),
            default => $this->andWhere(new Expression('FALSE')),
        };
    }

    private function getRelatedTable(string $relation): string
    {
        /** @var ScopedActiveRecord $model */
        $model = new $this->modelClass();
        $query = $model->getRelation($relation);
        return $query->modelClass::tableName();
    }
}
