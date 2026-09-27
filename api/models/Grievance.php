<?php

declare(strict_types=1);

namespace app\models;

use app\components\AccessRule;
use app\components\Format;
use app\components\HasStatusTransitions;
use app\components\ScopedActiveQuery;
use app\components\ScopedActiveRecord;
use Yii;

/**
 * A grievance from a worker or the community (brief Phase 5); data/schema/grievance.yaml.
 *
 *   received -> acknowledged -> under_investigation -> resolved -> closed
 *   received / acknowledged -> under_investigation or resolved directly; resolved / closed -> reopened
 *   reopened -> under_investigation / resolved
 * Every transition writes grievance_action (the grievance's own timeline).
 *
 * Sensitive (harassment, or against the mine head) grievances do not exist for a mine head
 * (restrictFor), and the complainant's identity is serialised only as AccessRule allows. Name and
 * contact are redacted in the audit log. Descriptions are shown as written, in their `language`.
 *
 * @property int $id
 * @property string $ticket_no
 * @property int $mine_id
 * @property string $submitter_type
 * @property string|null $name
 * @property string|null $contact
 * @property bool $is_anonymous
 * @property string $category
 * @property string $severity
 * @property string $language
 * @property string $description
 * @property int|null $file_id
 * @property mixed $location
 * @property string $status
 * @property int|null $assigned_to
 * @property string $sla_due_at
 * @property int $escalation_level
 * @property bool $against_mine_head
 * @property string|null $resolution_note
 * @property int|null $satisfaction_rating
 * @property string $created_at
 */
class Grievance extends ScopedActiveRecord implements HasStatusTransitions
{
    public const CATEGORIES = ['wages', 'safety', 'working_conditions', 'harassment', 'environment', 'land_compensation', 'other'];
    public const SUBMITTER_TYPES = ['employee', 'contract_worker', 'community', 'anonymous'];
    public const SEVERITIES = ['low', 'medium', 'high'];
    public const LANGUAGES = ['en', 'hi', 'bn', 'or', 'te', 'mr'];
    public const OPEN = ['received', 'acknowledged', 'under_investigation', 'reopened'];
    public const DONE = ['resolved', 'closed'];
    /** Transition target -> the grievance_action recorded for it. */
    public const ACTION_FOR = [
        'acknowledged' => 'acknowledge', 'under_investigation' => 'investigate', 'resolved' => 'resolve',
        'closed' => 'close', 'reopened' => 'reopen',
    ];

    public ?string $location_geojson = null;

    public static function tableName(): string
    {
        return '{{%grievance}}';
    }

    public static function find(): ScopedActiveQuery
    {
        return parent::find()->select(['{{%grievance}}.*', 'location_geojson' => 'ST_AsGeoJSON({{%grievance}}.location)']);
    }

    /** A mine head never sees a sensitive grievance, even at its own mine (AccessRule). */
    public static function restrictFor(User $user, ScopedActiveQuery $query): void
    {
        if (!AccessRule::seesSensitiveGrievances($user)) {
            $query->andWhere(new \yii\db\Expression('NOT ' . AccessRule::sensitiveGrievanceSql('{{%grievance}}')));
        }
    }

    public static function auditRedacted(): array
    {
        return ['name', 'contact'];
    }

    public static function statusAttribute(): string
    {
        return 'status';
    }

    public static function transitions(): array
    {
        return [
            'received' => ['acknowledged', 'under_investigation', 'resolved'],
            'acknowledged' => ['under_investigation', 'resolved'],
            'under_investigation' => ['resolved'],
            'resolved' => ['closed', 'reopened'],
            'closed' => ['reopened'],
            'reopened' => ['under_investigation', 'resolved'],
        ];
    }

    public function recordTransition(string $from, string $to, ?int $userId, array $context): void
    {
        GrievanceAction::record($this, self::ACTION_FOR[$to], $from, $to, $context['note'] ?? null, $userId);
    }

    public function isSensitive(): bool
    {
        return $this->category === 'harassment' || (bool) $this->against_mine_head;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function rules(): array
    {
        return [
            [['mine_id', 'submitter_type', 'category', 'language', 'description'], 'required', 'message' => 'REQUIRED'],
            [['submitter_type'], 'in', 'range' => self::SUBMITTER_TYPES, 'message' => 'INVALID_VALUE'],
            [['category'], 'in', 'range' => self::CATEGORIES, 'message' => 'INVALID_VALUE'],
            [['severity'], 'in', 'range' => self::SEVERITIES, 'message' => 'INVALID_VALUE'],
            [['language'], 'in', 'range' => self::LANGUAGES, 'message' => 'INVALID_VALUE'],
            [['description'], 'string', 'min' => 10, 'max' => 4000, 'tooShort' => 'TOO_SHORT', 'tooLong' => 'TOO_LONG'],
            [['name'], 'string', 'max' => 120, 'tooLong' => 'TOO_LONG'],
            [['contact'], 'string', 'max' => 64, 'tooLong' => 'TOO_LONG'],
            [['resolution_note'], 'string', 'max' => 2000, 'tooLong' => 'TOO_LONG'],
            [['satisfaction_rating'], 'integer', 'min' => 1, 'max' => 5, 'message' => 'INVALID_VALUE', 'tooSmall' => 'INVALID_VALUE', 'tooBig' => 'INVALID_VALUE'],
        ];
    }

    public function fields(): array
    {
        $identity = Yii::$app->has('user', true) ? Yii::$app->user->identity : null;
        $showIdentity = AccessRule::seesGrievanceIdentity($identity instanceof User ? $identity : null, $this->isSensitive());
        $fields = [
            'id', 'ticket_no', 'mine_id',
            'mine_name' => fn() => $this->mine?->name,
            'mine_code' => fn() => $this->mine?->code,
            'submitter_type',
            'is_anonymous' => fn() => (bool) $this->is_anonymous,
            'category', 'severity', 'language', 'description',
            'location' => fn() => $this->location_geojson === null ? null : json_decode($this->location_geojson, true),
            'status',
            'is_open' => fn() => $this->isOpen(),
            'assigned_to',
            'assignee_name' => fn() => $this->assignee?->full_name,
            'sla_due_at' => fn() => Format::utc($this->sla_due_at),
            'is_overdue' => fn() => $this->isOpen() && strtotime((string) $this->sla_due_at) < time(),
            'escalation_level' => fn() => (int) $this->escalation_level,
            'against_mine_head' => fn() => (bool) $this->against_mine_head,
            'is_sensitive' => fn() => $this->isSensitive(),
            'resolution_note',
            'satisfaction_rating' => fn() => $this->satisfaction_rating === null ? null : (int) $this->satisfaction_rating,
            'file_url' => fn() => $this->file && Yii::$app->fileStorage->verify($this->file) ? Yii::$app->fileStorage->signedUrl($this->file) : null,
            'created_at' => fn() => Format::utc($this->created_at),
        ];
        // Identity: only where AccessRule allows. Otherwise the keys are absent, not null - a
        // mine head's payload never carries a name or a contact field at all.
        if ($showIdentity) {
            $fields['name'] = 'name';
            $fields['contact'] = 'contact';
        }
        return $fields;
    }

    public function extraFields(): array
    {
        return ['timeline' => fn() => array_map(fn(GrievanceAction $a) => $a->toArray(), $this->actions)];
    }

    public function getMine()
    {
        return $this->hasOne(Mine::class, ['id' => 'mine_id']);
    }

    public function getAssignee()
    {
        return $this->hasOne(User::class, ['id' => 'assigned_to']);
    }

    public function getFile()
    {
        return $this->hasOne(File::class, ['id' => 'file_id']);
    }

    public function getActions()
    {
        return $this->hasMany(GrievanceAction::class, ['grievance_id' => 'id'])->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC]);
    }
}
