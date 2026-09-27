<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;
use app\components\HasStatusTransitions;
use app\components\ScopedActiveRecord;
use Yii;

/**
 * A regulator's "Call for Detailed Report" (brief Phase 4) - a demand with a deadline, not a
 * permission request; data/schema/production_detail_request.yaml.
 *
 *   pending -> submitted (the mine head responds) -> closed (the requester accepts)
 *   pending -> overdue (due_at passed) -> escalated (overdue longer than the escalation window)
 *   overdue / escalated -> submitted (a late response)
 * overdue and escalated are set by DetailRequestService::escalateDue, never by a user.
 * A submitted or closed request opens GET /v1/production/detail for its mine and date range
 * (AccessRule::detailRequestCovers).
 *
 * @property int $id
 * @property int $mine_id
 * @property int $requested_by
 * @property string $date_from
 * @property string $date_to
 * @property string $reason
 * @property string $due_at
 * @property string $status
 * @property string|null $response_note
 * @property int|null $response_file_id
 * @property int|null $responded_by
 * @property string|null $responded_at
 * @property string $created_at
 */
class ProductionDetailRequest extends ScopedActiveRecord implements HasStatusTransitions
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_CLOSED = 'closed';
    /** Statuses whose response opens the detail view. */
    public const FULFILLED = [self::STATUS_SUBMITTED, self::STATUS_CLOSED];
    /** Statuses still waiting for the mine. */
    public const AWAITING = [self::STATUS_PENDING, self::STATUS_OVERDUE, self::STATUS_ESCALATED];

    public static function tableName(): string
    {
        return '{{%production_detail_request}}';
    }

    public static function scopePath(): string
    {
        return 'mine_id';
    }

    public static function statusAttribute(): string
    {
        return 'status';
    }

    public static function transitions(): array
    {
        return [
            self::STATUS_PENDING => [self::STATUS_SUBMITTED, self::STATUS_OVERDUE],
            self::STATUS_OVERDUE => [self::STATUS_SUBMITTED, self::STATUS_ESCALATED],
            self::STATUS_ESCALATED => [self::STATUS_SUBMITTED],
            self::STATUS_SUBMITTED => [self::STATUS_CLOSED],
        ];
    }

    public function recordTransition(string $from, string $to, ?int $userId, array $context): void
    {
        StatusHistory::record($this, $from, $to, $userId, $context);
    }

    public function rules(): array
    {
        return [
            [['mine_id', 'requested_by', 'date_from', 'date_to', 'reason', 'due_at'], 'required', 'message' => 'REQUIRED'],
            [['date_from', 'date_to'], 'date', 'format' => 'php:Y-m-d', 'message' => 'INVALID_DATE'],
            [['date_to'], 'compare', 'compareAttribute' => 'date_from', 'operator' => '>=', 'type' => 'string', 'message' => 'BEFORE_START'],
            [['reason'], 'string', 'min' => 10, 'max' => 1000, 'tooShort' => 'TOO_SHORT', 'tooLong' => 'TOO_LONG'],
            [['response_note'], 'string', 'max' => 2000, 'tooLong' => 'TOO_LONG'],
            [['status'], 'in', 'range' => array_merge(array_keys(self::transitions()), [self::STATUS_CLOSED]), 'message' => 'INVALID_VALUE'],
        ];
    }

    public function fields(): array
    {
        return [
            'id', 'mine_id',
            'mine_name' => fn() => $this->mine?->name,
            'mine_code' => fn() => $this->mine?->code,
            'requested_by',
            'requester_name' => fn() => $this->requester?->full_name,
            'date_from', 'date_to', 'reason',
            'due_at' => fn() => Format::utc($this->due_at),
            'status',
            'is_fulfilled' => fn() => in_array($this->status, self::FULFILLED, true),
            'response_note', 'response_file_id',
            // Seeded responses have file metadata only; a link is offered when the file exists.
            'response_url' => fn() => $this->responseFile && Yii::$app->fileStorage->verify($this->responseFile)
                ? Yii::$app->fileStorage->signedUrl($this->responseFile) : null,
            'responded_by',
            'responder_name' => fn() => $this->responder?->full_name,
            'responded_at' => fn() => Format::utc($this->responded_at),
            'created_at' => fn() => Format::utc($this->created_at),
        ];
    }

    public function extraFields(): array
    {
        return ['history' => fn() => StatusHistory::forEntity($this)];
    }

    public function getMine()
    {
        return $this->hasOne(Mine::class, ['id' => 'mine_id']);
    }

    public function getRequester()
    {
        return $this->hasOne(User::class, ['id' => 'requested_by']);
    }

    public function getResponder()
    {
        return $this->hasOne(User::class, ['id' => 'responded_by']);
    }

    public function getResponseFile()
    {
        return $this->hasOne(File::class, ['id' => 'response_file_id']);
    }
}
