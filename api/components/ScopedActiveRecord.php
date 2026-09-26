<?php

declare(strict_types=1);

namespace app\components;

/**
 * A model whose rows belong to a mine. find() returns a ScopedActiveQuery; controllers read through
 * findScoped() / find()->forCurrentUser() so an out-of-scope id is indistinguishable from a missing
 * one (404, brief rule 2).
 */
abstract class ScopedActiveRecord extends ActiveRecord
{
    /** Column holding the mine id, or 'relation.column'. */
    public static function scopePath(): string
    {
        return 'mine_id';
    }

    public static function find(): ScopedActiveQuery
    {
        return new ScopedActiveQuery(static::class);
    }

    /** The record if it exists and the current user may see it; otherwise 404 NOT_FOUND. */
    public static function findScoped(int|string $id): static
    {
        $model = static::find()->forCurrentUser()
            ->andWhere([static::tableName() . '.id' => (int) $id])
            ->one();
        if ($model === null) {
            throw ApiException::notFound();
        }
        return $model;
    }
}
