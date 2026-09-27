<?php

declare(strict_types=1);

namespace app\models;

use app\components\ActiveRecord;
use app\components\Format;

/**
 * One workflow transition (brief rule 5) of an alert, corrective action, inspection or
 * observation. Written by StatusTransition through the model's recordTransition(); also audited.
 *
 * @property int $id
 * @property string $entity
 * @property int $entity_id
 * @property int|null $mine_id
 * @property string $from_status
 * @property string $to_status
 * @property int|null $user_id
 * @property array $context
 * @property string $created_at
 */
class StatusHistory extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%status_history}}';
    }

    public static function record(ActiveRecord $model, string $from, string $to, ?int $userId, array $context): self
    {
        $row = new self([
            'entity' => trim($model::tableName(), '{}%'),
            'entity_id' => (int) $model->getPrimaryKey(),
            'mine_id' => $model->hasAttribute('mine_id') ? $model->getAttribute('mine_id') : null,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $userId,
            'context' => $context === [] ? new \ArrayObject() : $context,
            'created_at' => Format::sql(Format::now()),
        ]);
        $row->save(false);
        return $row;
    }

    /** @var array<string, self[]> histories fetched by preload(), each handed out once */
    private static array $preloaded = [];

    /**
     * Fetch the histories of a whole list in one query, for the forEntity() calls that follow
     * when the list is serialized (instead of one query per record).
     * @param ActiveRecord[] $models of one class
     */
    public static function preload(array $models): void
    {
        if ($models === []) {
            return;
        }
        $entity = trim(reset($models)::tableName(), '{}%');
        $ids = array_map(fn(ActiveRecord $m) => $m->getPrimaryKey(), $models);
        foreach ($ids as $id) {
            self::$preloaded["$entity|$id"] = [];
        }
        foreach (self::find()->where(['entity' => $entity, 'entity_id' => $ids])->with('user')->orderBy(['id' => SORT_DESC])->all() as $row) {
            self::$preloaded["$entity|{$row->entity_id}"][] = $row;
        }
    }

    /** @return self[] newest first */
    public static function forEntity(ActiveRecord $model): array
    {
        $key = trim($model::tableName(), '{}%') . '|' . $model->getPrimaryKey();
        if (array_key_exists($key, self::$preloaded)) {
            $rows = self::$preloaded[$key];
            unset(self::$preloaded[$key]);
            return $rows;
        }
        return self::find()
            ->where(['entity' => trim($model::tableName(), '{}%'), 'entity_id' => $model->getPrimaryKey()])
            ->with('user')
            ->orderBy(['id' => SORT_DESC])
            ->all();
    }

    public function fields(): array
    {
        return [
            'id', 'from_status', 'to_status', 'user_id',
            'user_name' => fn() => $this->user?->full_name,
            'context' => fn() => (object) Format::json($this->context),
            // A signed, time-limited link to the proof image, when one was attached.
            'proof_url' => function () {
                $fileId = Format::json($this->context)['file_id'] ?? null;
                $file = $fileId ? File::findOne($fileId) : null;
                return $file ? \Yii::$app->fileStorage->signedUrl($file) : null;
            },
            'created_at' => fn() => Format::utc($this->created_at),
        ];
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
