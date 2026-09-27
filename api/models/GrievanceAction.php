<?php

declare(strict_types=1);

namespace app\models;

use app\components\ActiveRecord;
use app\components\Format;

/**
 * One step of a grievance's timeline; data/schema/grievance_action.yaml (plus `assign`).
 * Read only through its grievance, so the grievance's scoping and routing apply.
 *
 * @property int $id
 * @property int $grievance_id
 * @property string $action
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $note
 * @property int|null $actor_id
 * @property string $created_at
 */
class GrievanceAction extends ActiveRecord
{
    public const ACTIONS = ['submit', 'acknowledge', 'investigate', 'resolve', 'close', 'reopen', 'escalate', 'assign'];

    public static function tableName(): string
    {
        return '{{%grievance_action}}';
    }

    public static function record(Grievance $grievance, string $action, ?string $from, string $to, ?string $note, ?int $actorId): self
    {
        $row = new self([
            'grievance_id' => $grievance->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to,
            'note' => $note, 'actor_id' => $actorId, 'created_at' => Format::sql(Format::now()),
        ]);
        $row->save(false);
        return $row;
    }

    public function fields(): array
    {
        return [
            'id', 'action', 'from_status', 'to_status', 'note', 'actor_id',
            'actor_name' => fn() => $this->actor?->full_name,
            'created_at' => fn() => Format::utc($this->created_at),
        ];
    }

    /** The public tracking view: what happened and when - no notes, no people. */
    public function publicFields(): array
    {
        return ['action' => $this->action, 'status' => $this->to_status, 'at' => Format::utc($this->created_at)];
    }

    public function getActor()
    {
        return $this->hasOne(User::class, ['id' => 'actor_id']);
    }
}
