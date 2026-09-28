<?php

declare(strict_types=1);

namespace app\services;

use app\components\ApiException;
use app\components\FieldChecklist;
use app\components\Format;
use app\components\Rules;
use app\components\StatusTransition;
use app\models\Inspection;
use app\models\Mine;
use app\models\Obligation;
use app\models\Observation;
use app\models\User;
use Yii;
use yii\db\Query;
use yii\web\UploadedFile;

/**
 * The offline field app's server side (Phase 7B).
 *
 * The phone records visits and captures with no signal and sends them later, in order, each with
 * an id it made itself (a UUID). Each item is idempotent: the first sync acts and stores its result
 * in field_sync; a retry of the same id - a lost response, a double tap, a resent queue - returns
 * that result and acts no more. Nothing bypasses the rules: scoping (404 for another mine), the
 * inspection's status transitions, validation, audit, status history and alerts all come from the
 * services the dashboards use.
 *
 *   visit    {client_id, mine_id, inspection_id?, recorded_at}: puts an inspection in progress - the
 *            inspector's scheduled one, or a new one (inspector: spot; mine head: self)
 *   capture  {client_id, visit_client_id, category, severity, checklist_item?, obligation_code?,
 *            note?, recorded_at, location?: {lat, lon, accuracy_m}, location_status,
 *            violation_type?, corrective_action?: {description, due_days}}: an observation, and
 *            when a violation type is given, the violation (with its alert) and optionally a
 *            corrective action for the mine
 *   photo    POST /v1/field/photos {client_id, capture_client_id, file}: a photo of a synced capture
 *
 * A capture far from the mine's recorded point, or sent from a phone whose clock is off, is
 * flagged - never refused (rules.yaml product.field_capture).
 */
final class FieldSyncService
{
    public const LOCATION_STATUSES = ['gps', 'unknown_underground', 'denied', 'unavailable'];
    private const MAX_ITEMS = 200;
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public static function settings(): array
    {
        return Rules::value('product', 'field_capture');
    }

    /** Everything the app needs to work offline, for this account. */
    public static function bootstrap(User $user): array
    {
        $inspections = $user->role === User::ROLE_INSPECTOR
            ? Inspection::find()->forUser($user)->andWhere(['inspection.inspector_id' => $user->id, 'inspection.status' => ['scheduled', 'visited']])
                ->orderBy(['inspection.scheduled_for' => SORT_ASC, 'inspection.id' => SORT_ASC])->all()
            : [];
        $assigned = array_map(fn(Inspection $i) => (int) $i->mine_id, $inspections);
        $mines = Mine::find()->forUser($user)
            ->select(['mine.id', 'mine.code', 'mine.name', 'mine.district', 'mine.state',
                'lat' => 'ST_Y(mine.location)', 'lon' => 'ST_X(mine.location)'])
            ->orderBy(['mine.name' => SORT_ASC])->asArray()->all();
        $mines = array_map(fn($m) => ['id' => (int) $m['id'], 'code' => $m['code'], 'name' => $m['name'], 'district' => $m['district'],
            'state' => $m['state'], 'lat' => $m['lat'] === null ? null : (float) $m['lat'], 'lon' => $m['lon'] === null ? null : (float) $m['lon'],
            'assigned' => in_array((int) $m['id'], $assigned, true)], $mines);
        usort($mines, fn($a, $b) => [!$a['assigned'], $a['name']] <=> [!$b['assigned'], $b['name']]);

        $items = FieldChecklist::items();
        $codes = array_values(array_unique(array_merge(...array_values(array_map(fn($i) => $i['obligations'], $items)))));
        $obligations = Obligation::find()->select(['code', 'title', 'instrument', 'clause'])->where(['code' => $codes])
            ->orderBy('code')->asArray()->all();
        $settings = self::settings();
        return [
            'server_now' => Format::utc(Format::sql(Format::now())),
            'user' => $user->toArray(),
            'settings' => [
                'geo_radius_m' => (int) $settings['geo_radius_m'], 'clock_skew_s' => (int) $settings['clock_skew_s'],
                'photos_per_capture' => (int) $settings['photos_per_capture'], 'photo_max_bytes' => (int) $settings['photo_max_bytes'],
                'photo_max_px' => (int) $settings['photo_max_px'], 'photo_quality' => (float) $settings['photo_quality'],
                'token_ttl_hours' => (int) Yii::$app->params['jwt.ttlHours'],
            ],
            'categories' => array_map(fn($key, $types) => ['key' => $key, 'types' => $types],
                array_keys(Rules::violationTypes()), array_values(Rules::violationTypes())),
            'checklist' => ['version' => FieldChecklist::version(), 'items' => array_values($items)],
            'obligations' => $obligations,
            'mines' => $mines,
            'inspections' => array_map(fn(Inspection $i) => ['id' => (int) $i->id, 'mine_id' => (int) $i->mine_id,
                'inspection_type' => $i->inspection_type, 'status' => $i->status, 'scheduled_for' => $i->scheduled_for], $inspections),
        ];
    }

    /**
     * Sync a batch, in order. @return array{server_now: string, clock_skew_s: ?int, results: list<array>}
     * Each result: {client_id, kind, status: created|replayed|failed, result?, error?: {code, params, fields}}.
     */
    public static function sync(User $user, array $body): array
    {
        $items = $body['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) === 0 || count($items) > self::MAX_ITEMS) {
            throw ApiException::fields(['items' => ['INVALID_VALUE']]);
        }
        $now = Format::now();
        $skew = null;
        if (is_string($body['device_now'] ?? null) && ($device = self::time($body['device_now'])) !== null) {
            $skew = $device->getTimestamp() - $now->getTimestamp();
        }
        $results = [];
        foreach ($items as $item) {
            $clientId = is_array($item) && is_string($item['client_id'] ?? null) ? strtolower($item['client_id']) : null;
            $kind = is_array($item) ? ($item['kind'] ?? null) : null;
            $out = ['client_id' => $clientId, 'kind' => $kind];
            try {
                if ($clientId === null || !preg_match(self::UUID, $clientId)) {
                    throw ApiException::fields(['client_id' => ['INVALID_VALUE']]);
                }
                if (!in_array($kind, ['visit', 'capture'], true)) {
                    throw ApiException::fields(['kind' => ['INVALID_VALUE']]);
                }
                [$status, $result] = self::once($user, $clientId, $kind,
                    fn() => $kind === 'visit' ? self::visit($user, $item, $clientId) : self::capture($user, $item, $clientId, $skew));
                $results[] = $out + ['status' => $status, 'result' => $result];
            } catch (ApiException $e) {
                $results[] = $out + ['status' => 'failed', 'error' => array_filter(
                    ['code' => $e->errorCode, 'params' => $e->params ?: null, 'fields' => $e->fields ?: null, 'http_status' => $e->statusCode],
                    fn($v) => $v !== null)];
            }
        }
        return ['server_now' => Format::utc(Format::sql($now)), 'clock_skew_s' => $skew, 'results' => $results];
    }

    /** A photo of a synced capture. @return array{0: string, 1: array} [created|replayed, result] */
    public static function photo(User $user, array $body, ?UploadedFile $file): array
    {
        $clientId = is_string($body['client_id'] ?? null) ? strtolower($body['client_id']) : '';
        $captureId = is_string($body['capture_client_id'] ?? null) ? strtolower($body['capture_client_id']) : '';
        $fields = [];
        if (!preg_match(self::UUID, $clientId)) {
            $fields['client_id'] = ['INVALID_VALUE'];
        }
        if (!preg_match(self::UUID, $captureId)) {
            $fields['capture_client_id'] = ['INVALID_VALUE'];
        }
        if ($fields) {
            throw ApiException::fields($fields);
        }
        return self::once($user, $clientId, 'photo', function () use ($user, $captureId, $file) {
            $capture = self::synced($user, $captureId, 'capture');
            if ($capture === null) {
                throw new ApiException(409, 'CAPTURE_NOT_SYNCED', ['capture_client_id' => $captureId]);
            }
            $observation = Observation::findScoped((int) $capture['observation_id']);
            if ($file === null) {
                throw ApiException::fields(['file' => ['REQUIRED']]);
            }
            $settings = self::settings();
            if ($file->size > (int) $settings['photo_max_bytes']) {
                throw ApiException::fields(['file' => ['TOO_LARGE']]);
            }
            if (!in_array($file->type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                throw ApiException::fields(['file' => ['INVALID_TYPE']]);
            }
            $count = (int) (new Query())->from('{{%file}}')->where(['entity' => 'observation', 'entity_id' => $observation->id])->count();
            if ($count >= (int) $settings['photos_per_capture']) {
                throw new ApiException(422, 'TOO_MANY_PHOTOS', ['max' => (int) $settings['photos_per_capture']]);
            }
            $stored = Yii::$app->fileStorage->storeUpload($file, 'observation', (int) $observation->id, (int) $user->id);
            FieldCapture::forget();
            return ['file_id' => (int) $stored->id, 'observation_id' => (int) $observation->id];
        });
    }

    /**
     * Run $work once per client id: a stored result is returned as `replayed`; otherwise $work runs
     * in a transaction together with the field_sync row, so a failure leaves nothing behind and the
     * item can be retried. A client id used by another account is refused.
     */
    private static function once(User $user, string $clientId, string $kind, callable $work): array
    {
        $done = (new Query())->from('{{%field_sync}}')->where(['client_uuid' => $clientId])->one();
        if ($done !== false && $done !== null) {
            if ((int) $done['user_id'] !== (int) $user->id || $done['kind'] !== $kind) {
                throw new ApiException(409, 'CLIENT_ID_CONFLICT');
            }
            return ['replayed', json_decode((string) $done['result'], true)];
        }
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $result = $work();
            $inserted = $db->createCommand('INSERT INTO {{%field_sync}} (client_uuid, user_id, kind, result) VALUES (:c, :u, :k, :r)
                ON CONFLICT (client_uuid) DO NOTHING', [':c' => $clientId, ':u' => $user->id, ':k' => $kind, ':r' => json_encode($result)])->execute();
            if ($inserted === 0) {
                // The same id arrived twice at once and the other request won: undo ours, return its result.
                $transaction->rollBack();
                return self::once($user, $clientId, $kind, $work);
            }
            $transaction->commit();
            return ['created', $result];
        } catch (\Throwable $e) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $e;
        }
    }

    private static function synced(User $user, string $clientId, string $kind): ?array
    {
        $row = (new Query())->from('{{%field_sync}}')->where(['client_uuid' => $clientId, 'user_id' => $user->id, 'kind' => $kind])->one();
        return $row ? json_decode((string) $row['result'], true) : null;
    }

    private static function visit(User $user, array $item, string $clientId): array
    {
        if (!is_numeric($item['mine_id'] ?? null)) {
            throw ApiException::fields(['mine_id' => ['REQUIRED']]);
        }
        $mine = Mine::findScoped((int) $item['mine_id']);
        $recordedAt = self::time($item['recorded_at'] ?? null) ?? Format::now();
        $context = ['client_id' => $clientId, 'recorded_at' => Format::utc(Format::sql($recordedAt)), 'via' => 'field'];
        if (($item['inspection_id'] ?? null) !== null) {
            if (!is_numeric($item['inspection_id']) || $user->role === User::ROLE_MINE_HEAD) {
                throw ApiException::fields(['inspection_id' => ['INVALID_VALUE']]);
            }
            $inspection = Inspection::findScoped((int) $item['inspection_id']);
            if ((int) $inspection->mine_id !== (int) $mine->id) {
                throw ApiException::fields(['inspection_id' => ['INVALID_VALUE']]);
            }
            if ((int) $inspection->inspector_id !== (int) $user->id) {
                throw new ApiException(422, 'INSPECTION_NOT_ASSIGNED', ['inspection_id' => (int) $inspection->id]);
            }
        } else {
            $inspection = new Inspection([
                'mine_id' => $mine->id,
                'inspector_id' => $user->id,
                'inspection_type' => $user->role === User::ROLE_MINE_HEAD ? 'self' : (string) self::settings()['unscheduled_type'],
                'scheduled_for' => $recordedAt->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d'),
                'status' => 'scheduled', 'findings_count' => 0, 'is_locked' => false, 'client_uuid' => $clientId,
            ]);
            if (!$inspection->save()) {
                throw ApiException::validation($inspection);
            }
        }
        if ($inspection->status === 'scheduled') {
            StatusTransition::apply($inspection, 'visited', $context);
        } elseif ($inspection->status !== 'visited') {
            throw new ApiException(422, 'INVALID_TRANSITION', ['from' => $inspection->status, 'to' => 'visited']);
        }
        return ['inspection_id' => (int) $inspection->id, 'mine_id' => (int) $mine->id, 'inspection_type' => $inspection->inspection_type];
    }

    private static function capture(User $user, array $item, string $clientId, ?int $skew): array
    {
        $visitId = is_string($item['visit_client_id'] ?? null) ? strtolower($item['visit_client_id']) : '';
        $visit = preg_match(self::UUID, $visitId) ? self::synced($user, $visitId, 'visit') : null;
        if ($visit === null) {
            throw new ApiException(409, 'VISIT_NOT_SYNCED', ['visit_client_id' => $visitId]);
        }
        $inspection = Inspection::findScoped((int) $visit['inspection_id']);
        $settings = self::settings();
        $fields = [];

        $category = $item['category'] ?? null;
        $checklistItem = $item['checklist_item'] ?? null;
        $items = FieldChecklist::items();
        if ($checklistItem !== null && (!is_string($checklistItem) || !isset($items[$checklistItem]) || $items[$checklistItem]['category'] !== $category)) {
            $fields['checklist_item'] = ['INVALID_VALUE'];
        }
        $obligation = $item['obligation_code'] ?? null;
        if ($obligation !== null && (!is_string($checklistItem) || !in_array($obligation, $items[$checklistItem]['obligations'] ?? [], true))) {
            $fields['obligation_code'] = ['INVALID_VALUE'];
        }
        $note = $item['note'] ?? null;
        if ($note !== null && (!is_string($note) || mb_strlen($note) > 2000)) {
            $fields['note'] = ['INVALID_VALUE'];
        }
        $recordedAt = self::time($item['recorded_at'] ?? null);
        if ($recordedAt === null) {
            $fields['recorded_at'] = ['REQUIRED'];
        }
        $status = $item['location_status'] ?? null;
        $location = $item['location'] ?? null;
        if (!in_array($status, self::LOCATION_STATUSES, true)) {
            $fields['location_status'] = ['INVALID_VALUE'];
        } elseif ($status === 'gps') {
            $ok = is_array($location) && is_numeric($location['lat'] ?? null) && is_numeric($location['lon'] ?? null)
                && abs((float) $location['lat']) <= 90 && abs((float) $location['lon']) <= 180
                && (!isset($location['accuracy_m']) || (is_numeric($location['accuracy_m']) && $location['accuracy_m'] >= 0));
            if (!$ok) {
                $fields['location'] = ['INVALID_VALUE'];
            }
        }
        $type = $item['violation_type'] ?? null;
        if ($type !== null && (!is_string($category) || !in_array($type, Rules::violationTypes()[$category] ?? [], true))) {
            $fields['violation_type'] = ['INVALID_VALUE'];
        }
        $action = $item['corrective_action'] ?? null;
        if ($action !== null) {
            $days = $action['due_days'] ?? null;
            if ($type === null || !is_array($action) || !is_string($action['description'] ?? null) || trim($action['description']) === ''
                || !is_numeric($days) || (int) $days < 1 || (int) $days > 90) {
                $fields['corrective_action'] = ['INVALID_VALUE'];
            }
        }
        if ($fields) {
            throw ApiException::fields($fields);
        }

        $now = Format::now();
        $distance = null;
        $point = null;
        if ($status === 'gps') {
            $point = [(float) $location['lon'], (float) $location['lat']];
            $d = Yii::$app->db->createCommand('SELECT ST_Distance(location::geography, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography)
                FROM {{%mine}} WHERE id = :id AND location IS NOT NULL', [':lon' => $point[0], ':lat' => $point[1], ':id' => $inspection->mine_id])->queryScalar();
            $distance = $d === false || $d === null ? null : (int) round((float) $d);
        }
        $clockFlag = $skew !== null && abs($skew) > (int) $settings['clock_skew_s'];
        // The observation's time is the phone's; a phone whose clock is off, or a time in the future, falls back to receipt.
        $observedAt = $clockFlag || $recordedAt > $now ? $now : $recordedAt;
        $observation = InspectionService::addObservation($inspection, ['category' => $category, 'severity' => $item['severity'] ?? null], [
            'client_uuid' => $clientId,
            'checklist_item' => $checklistItem,
            'checklist_version' => $checklistItem === null ? null : FieldChecklist::version(),
            'obligation_code' => $obligation,
            'note' => $note === null ? null : trim($note),
            'observed_at' => Format::sql($observedAt),
            'recorded_at' => Format::sql($recordedAt),
            'received_at' => Format::sql($now),
            'location_status' => $status,
            // EWKT: PostGIS reads it on insert, and the audit record holds the same text.
            'location' => $point === null ? null : sprintf('SRID=4326;POINT(%.7F %.7F)', $point[0], $point[1]),
            'location_accuracy_m' => $status === 'gps' && isset($location['accuracy_m']) ? round((float) $location['accuracy_m'], 1) : null,
            'distance_m' => $distance,
            'geo_flag' => $distance !== null && $distance > (int) $settings['geo_radius_m'],
            'clock_skew_s' => $skew,
            'clock_flag' => $clockFlag,
            'recorded_by' => $user->id,
        ]);
        $violation = $type === null ? null : InspectionService::promote($observation, ['violation_type' => $type], Format::sql($observedAt));
        $correctiveAction = $action === null ? null : CorrectiveActionService::create($violation, $user, [
            'description' => trim($action['description']),
            'due_at' => $now->modify('+' . (int) $action['due_days'] . ' days')->format('Y-m-d\TH:i:s\Z'),
        ]);
        FieldCapture::forget();
        return [
            'observation_id' => (int) $observation->id, 'inspection_id' => (int) $inspection->id,
            'violation_id' => $violation === null ? null : (int) $violation->id,
            'corrective_action_id' => $correctiveAction === null ? null : (int) $correctiveAction->id,
            'received_at' => Format::utc(Format::sql($now)),
            'distance_m' => $distance, 'geo_flag' => (bool) $observation->geo_flag,
            'clock_skew_s' => $skew, 'clock_flag' => $clockFlag,
        ];
    }

    private static function time(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $value)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
