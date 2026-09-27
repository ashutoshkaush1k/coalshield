<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;
use app\components\ScopedActiveRecord;
use Yii;

/**
 * Evidence submitted against an obligation task, and its review (Phase 5B).
 *
 * @property int $id
 * @property int $task_id
 * @property int $file_id
 * @property string|null $note
 * @property int $submitted_by
 * @property string $submitted_at
 * @property string $status
 * @property int|null $reviewed_by
 * @property string|null $reviewed_at
 * @property string|null $review_note
 */
class ObligationSubmission extends ScopedActiveRecord
{
    public static function tableName(): string
    {
        return '{{%obligation_submission}}';
    }

    public static function scopePath(): string
    {
        return 'task.mine_id';
    }

    public function fields(): array
    {
        return [
            'id', 'task_id', 'note', 'submitted_by',
            'submitter_name' => fn() => $this->submitter?->full_name,
            'submitted_at' => fn() => Format::utc($this->submitted_at),
            'status', 'reviewed_by',
            'reviewer_name' => fn() => $this->reviewer?->full_name,
            'reviewed_at' => fn() => Format::utc($this->reviewed_at),
            'review_note',
            // Seeded evidence is metadata only; a link is offered when the file exists.
            'file_url' => fn() => $this->file && Yii::$app->fileStorage->verify($this->file) ? Yii::$app->fileStorage->signedUrl($this->file) : null,
            'file_mime' => fn() => $this->file?->mime,
        ];
    }

    public function getTask()
    {
        return $this->hasOne(ObligationTask::class, ['id' => 'task_id']);
    }

    public function getFile()
    {
        return $this->hasOne(File::class, ['id' => 'file_id']);
    }

    public function getSubmitter()
    {
        return $this->hasOne(User::class, ['id' => 'submitted_by']);
    }

    public function getReviewer()
    {
        return $this->hasOne(User::class, ['id' => 'reviewed_by']);
    }
}
