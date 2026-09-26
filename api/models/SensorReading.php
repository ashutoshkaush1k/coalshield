<?php

declare(strict_types=1);

namespace app\models;

use app\components\Format;
use app\components\ScopedActiveRecord;

/**
 * One sensor reading; data/schema/sensor_reading.yaml. Partitioned by month (PK id, recorded_at).
 * Telemetry, not a record people edit: not audited row by row (SensorService audits each ingest
 * batch), and written through SensorService only.
 *
 * @property int $id
 * @property int $mine_id
 * @property string $sensor_type
 * @property string $value
 * @property string $unit
 * @property string $recorded_at
 * @property bool|null $breached
 * @property bool $resolved
 * @property string|null $resolved_at
 */
class SensorReading extends ScopedActiveRecord
{
    public static function tableName(): string
    {
        return '{{%sensor_reading}}';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public function behaviors(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            'id' => fn() => (int) $this->id,
            'mine_id', 'sensor_type',
            'value' => fn() => (float) $this->value,
            'unit',
            'breached' => fn() => Format::bool($this->breached),
            'recorded_at' => fn() => Format::utc($this->recorded_at),
        ];
    }
}
