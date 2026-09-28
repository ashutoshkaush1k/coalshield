<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;
use app\components\ScopedActiveRecord;

/**
 * What a detector found (Phase 7; AnomalyService). One row per detector, mine and subject - a day
 * (production), a sensor run, a category, a contractor, "night", "closures", "sla" - active while
 * the detector still finds it, cleared when it no longer does. Scoped by mine; the grievance-cluster
 * finding is shown only to roles that see grievance analytics (a mine head could otherwise count
 * sensitive grievances it may not see).
 *
 * @property int $id
 * @property string $detector
 * @property int $mine_id
 * @property string $subject
 * @property string|null $window_from
 * @property string|null $window_to
 * @property float $score
 * @property array $reasons
 * @property array $entities
 * @property string $engine
 * @property string $status
 * @property string $first_detected_at
 * @property string $last_seen_at
 * @property string|null $cleared_at
 */
class AnomalyFlag extends ScopedActiveRecord
{
    public const DETECTORS = ['production_anomaly', 'sensor_flatline', 'night_shift', 'repeat_violations', 'late_actions',
        'contractor_outlier', 'grievance_cluster'];
    /** Detectors whose findings need a permission beyond seeing the mine. */
    public const PERMISSION = ['grievance_cluster' => 'grievance.stats'];

    public static function tableName(): string
    {
        return '{{%anomaly_flag}}';
    }

    public static function restrictFor(User $user, \app\components\ScopedActiveQuery $query): void
    {
        foreach (self::PERMISSION as $detector => $permission) {
            if (!\Yii::$app->authManager->checkAccess($user->id, $permission)) {
                $query->andWhere(['<>', 'anomaly_flag.detector', $detector]);
            }
        }
    }

    public function fields(): array
    {
        return [
            'id', 'detector', 'mine_id',
            'mine_name' => fn() => $this->mine?->name,
            'mine_code' => fn() => $this->mine?->code,
            'subject',
            'from' => fn() => Format::utc($this->window_from),
            'to' => fn() => Format::utc($this->window_to),
            'score' => fn() => (float) $this->score,
            'reasons' => fn() => Format::json($this->reasons),
            'entities' => fn() => (object) Format::json($this->entities),
            'engine', 'status',
            'first_detected_at' => fn() => Format::utc($this->first_detected_at),
            'last_seen_at' => fn() => Format::utc($this->last_seen_at),
        ];
    }

    public function getMine()
    {
        return $this->hasOne(Mine::class, ['id' => 'mine_id']);
    }
}
