<?php

declare(strict_types=1);

namespace app\models;

use app\components\ScopedActiveQuery;
use app\components\ScopedActiveRecord;

/**
 * A coal mine. Scoped by its own id. Geometry columns are read as GeoJSON (location_geojson,
 * boundary_geojson) and exposed as GeoJSON objects. Columns: data/schema/mine.yaml.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property int $subsidiary_id
 * @property int|null $area_id
 * @property string|null $location
 * @property string|null $boundary
 * @property string $status
 * @property string $district
 * @property string $state
 * @property string $region
 * @property string $location_quality
 * @property string|null $capacity_mtpa
 * @property string|null $gem_id
 * @property-read Subsidiary $subsidiary
 */
class Mine extends ScopedActiveRecord
{
    public const TYPES = ['opencast', 'underground', 'mixed'];
    public const STATUSES = ['active', 'inactive'];
    public const LOCATION_QUALITIES = ['exact_gem', 'approx_gem', 'wikidata', 'district_centroid'];

    public ?string $location_geojson = null;
    public ?string $boundary_geojson = null;

    public static function tableName(): string
    {
        return '{{%mine}}';
    }

    public static function scopePath(): string
    {
        return 'id';
    }

    public static function find(): ScopedActiveQuery
    {
        return parent::find()->select([
            '{{%mine}}.*',
            'location_geojson' => 'ST_AsGeoJSON({{%mine}}.location)',
            'boundary_geojson' => 'ST_AsGeoJSON({{%mine}}.boundary)',
        ]);
    }

    public function rules(): array
    {
        return [
            [['code', 'name', 'type', 'subsidiary_id', 'status', 'district', 'state', 'region', 'location_quality'], 'required', 'message' => 'REQUIRED'],
            [['type'], 'in', 'range' => self::TYPES, 'message' => 'INVALID_VALUE'],
            [['status'], 'in', 'range' => self::STATUSES, 'message' => 'INVALID_VALUE'],
            [['location_quality'], 'in', 'range' => self::LOCATION_QUALITIES, 'message' => 'INVALID_VALUE'],
            [['subsidiary_id', 'area_id'], 'integer', 'message' => 'INVALID_VALUE'],
            [['capacity_mtpa'], 'number', 'min' => 0, 'message' => 'INVALID_VALUE'],
            [['code'], 'unique', 'message' => 'NOT_UNIQUE'],
        ];
    }

    public function fields(): array
    {
        return [
            'id',
            'code',
            'name',
            'type',
            'status',
            'subsidiary_id',
            'operator' => fn() => $this->subsidiary?->code,
            'operator_name' => fn() => $this->subsidiary?->name,
            'area_id',
            'district',
            'state',
            'region',
            'location' => fn() => $this->location_geojson === null ? null : json_decode($this->location_geojson, true),
            'boundary' => fn() => $this->boundary_geojson === null ? null : json_decode($this->boundary_geojson, true),
            'location_quality',
            'capacity_mtpa' => fn() => $this->capacity_mtpa === null ? null : (float) $this->capacity_mtpa,
            'gem_id',
        ];
    }

    public function getSubsidiary()
    {
        return $this->hasOne(Subsidiary::class, ['id' => 'subsidiary_id']);
    }

    public function getArea()
    {
        return $this->hasOne(Area::class, ['id' => 'area_id']);
    }
}
