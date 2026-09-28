<?php

declare(strict_types=1);

namespace app\services;

use app\models\File;
use Yii;
use yii\db\Query;

/**
 * What a field capture adds to an observation or a violation made from it (Phase 7B): the phone's
 * time and the server's receipt time, the location (or why there is none), the distance from the
 * mine and the flags, the checklist item, the note, the photos (signed links) and who recorded it.
 * Loaded once per request for every field capture - there are few - so a list of 50 violations
 * costs two queries, not fifty.
 */
final class FieldCapture
{
    private static ?int $app = null;
    private static array $byObservation = [];

    public static function forObservation(?int $observationId): ?array
    {
        if ($observationId === null) {
            return null;
        }
        if (self::$app !== spl_object_id(Yii::$app)) {
            self::load();
        }
        return self::$byObservation[$observationId] ?? null;
    }

    /** After a sync changes what is stored. */
    public static function forget(): void
    {
        self::$app = null;
    }

    private static function load(): void
    {
        self::$app = spl_object_id(Yii::$app);
        self::$byObservation = [];
        $rows = (new Query())->select(['o.id', 'o.client_uuid', 'o.checklist_item', 'o.obligation_code', 'o.note', 'o.recorded_at', 'o.received_at',
            'lat' => 'ST_Y(o.location)', 'lon' => 'ST_X(o.location)', 'o.location_accuracy_m', 'o.location_status', 'o.distance_m',
            'o.geo_flag', 'o.clock_skew_s', 'o.clock_flag', 'recorded_by_name' => 'u.full_name'])
            ->from(['o' => '{{%observation}}'])->leftJoin(['u' => '{{%user}}'], 'u.id = o.recorded_by')
            ->where(['not', ['o.client_uuid' => null]])->all();
        if ($rows === []) {
            return;
        }
        $photos = [];
        foreach (File::find()->where(['entity' => 'observation', 'entity_id' => array_column($rows, 'id')])->orderBy('id')->all() as $file) {
            $photos[(int) $file->entity_id][] = ['id' => (int) $file->id, 'url' => Yii::$app->fileStorage->signedUrl($file)];
        }
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            self::$byObservation[$id] = [
                'client_id' => $r['client_uuid'],
                'checklist_item' => $r['checklist_item'],
                'obligation_code' => $r['obligation_code'],
                'note' => $r['note'],
                'recorded_at' => \app\components\Format::utc($r['recorded_at']),
                'received_at' => \app\components\Format::utc($r['received_at']),
                'location' => $r['lat'] === null ? null : ['lat' => round((float) $r['lat'], 6), 'lon' => round((float) $r['lon'], 6),
                    'accuracy_m' => $r['location_accuracy_m'] === null ? null : (float) $r['location_accuracy_m']],
                'location_status' => $r['location_status'],
                'distance_m' => $r['distance_m'] === null ? null : (int) $r['distance_m'],
                'geo_flag' => (bool) $r['geo_flag'],
                'clock_skew_s' => $r['clock_skew_s'] === null ? null : (int) $r['clock_skew_s'],
                'clock_flag' => (bool) $r['clock_flag'],
                'recorded_by' => $r['recorded_by_name'],
                'photos' => $photos[$id] ?? [],
            ];
        }
    }
}
