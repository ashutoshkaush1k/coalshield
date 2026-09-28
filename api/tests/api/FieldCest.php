<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\Alert;
use app\models\CorrectiveAction;
use app\models\Inspection;
use app\models\Observation;
use app\models\Violation;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/**
 * The offline field app's sync (Phase 7B): every item is idempotent by the phone's own id, sync
 * goes through the normal services (scoping, transitions, violations with alerts, corrective
 * actions), a capture far from the mine or from a phone with a wrong clock is flagged and kept,
 * and an expired login refuses the batch without touching anything.
 */
class FieldCest
{
    private const INSPECTOR = 'inspector.07@dgms.example';
    private const PHOTO = __DIR__ . '/../../../backend/data/samples/images/ppe_sample.jpg';

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** The inspector's scheduled inspection and its mine, from the app's own bootstrap. */
    private function assignment(ApiTester $I): array
    {
        $I->amBearerOf(self::INSPECTOR);
        $I->sendGet('/v1/field/bootstrap');
        $I->seeResponseCodeIs(200);
        $b = json_decode($I->grabResponse(), true);
        $I->assertCount(23, $b['checklist']['items']);
        $I->assertCount(11, $b['categories']);
        $inspection = array_values(array_filter($b['inspections'], fn($i) => $i['status'] === 'scheduled'))[0];
        $mine = array_values(array_filter($b['mines'], fn($m) => $m['id'] === $inspection['mine_id']))[0];
        $I->assertTrue($mine['assigned']);
        return [$inspection, $mine];
    }

    private function capture(string $visit, array $mine, array $extra = []): array
    {
        return $extra + [
            'kind' => 'capture', 'client_id' => self::uuid(), 'visit_client_id' => $visit,
            'category' => 'roof_strata', 'severity' => 'high', 'checklist_item' => 'RS-01', 'obligation_code' => 'SAF-08',
            'note' => 'Props missing at the face', 'recorded_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
            'location_status' => 'gps', 'location' => ['lat' => $mine['lat'] + 0.004, 'lon' => $mine['lon'], 'accuracy_m' => 8],
            'violation_type' => 'inadequate_roof_support',
            'corrective_action' => ['description' => 'Set props to the support plan', 'due_days' => 3],
        ];
    }

    private function sync(ApiTester $I, array $items, ?string $deviceNow = null): array
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/v1/field/sync', ['device_now' => $deviceNow ?? gmdate('Y-m-d\TH:i:s\Z'), 'items' => $items]);
        $I->seeResponseCodeIs(200);
        return array_column(json_decode($I->grabResponse(), true)['results'], null, 'client_id');
    }

    public function syncIsIdempotent(ApiTester $I): void
    {
        [$inspection, $mine] = $this->assignment($I);
        $visit = self::uuid();
        $items = [
            ['kind' => 'visit', 'client_id' => $visit, 'mine_id' => $mine['id'], 'inspection_id' => $inspection['id'], 'recorded_at' => gmdate('Y-m-d\TH:i:s\Z')],
            $this->capture($visit, $mine),
            $this->capture($visit, $mine, ['category' => 'ppe', 'severity' => 'medium', 'checklist_item' => 'PP-01', 'obligation_code' => null,
                'violation_type' => null, 'corrective_action' => null]),
        ];
        $counts = fn() => [Observation::find()->count(), Violation::find()->count(), CorrectiveAction::find()->count(),
            Alert::find()->where(['code' => 'VIOLATION_RECORDED'])->count()];
        $before = $counts();

        $first = $this->sync($I, $items);
        $I->assertSame(['created', 'created', 'created'], array_column(array_values($first), 'status'));
        $I->assertSame('visited', Inspection::findOne($inspection['id'])->status);
        $capture = $first[$items[1]['client_id']]['result'];
        $I->assertNotNull($capture['violation_id']);
        $I->assertNotNull($capture['corrective_action_id']);
        $I->assertNull($first[$items[2]['client_id']]['result']['violation_id'], 'an observation only');
        $I->assertEquals([$before[0] + 2, $before[1] + 1, $before[2] + 1, $before[3] + 1], $counts());
        $observation = Observation::findOne($capture['observation_id']);
        $I->assertSame($items[1]['client_id'], $observation->client_uuid);
        $I->assertSame('RS-01', $observation->checklist_item);
        $I->assertSame(self::INSPECTOR, \app\models\User::findOne($observation->recorded_by)->email);

        // A retry of the same batch - a lost answer - acts no more and answers the same.
        $again = $this->sync($I, $items);
        $I->assertSame(['replayed', 'replayed', 'replayed'], array_column(array_values($again), 'status'));
        $I->assertEquals($capture, $again[$items[1]['client_id']]['result']);
        $I->assertEquals([$before[0] + 2, $before[1] + 1, $before[2] + 1, $before[3] + 1], $counts());

        // The violation carries the capture for the dashboards.
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet("/v1/violations/{$capture['violation_id']}");
        $I->seeResponseContainsJson(['source' => 'inspection', 'field' => ['checklist_item' => 'RS-01', 'obligation_code' => 'SAF-08',
            'location_status' => 'gps', 'geo_flag' => false, 'recorded_by' => \app\models\User::findOne(['email' => self::INSPECTOR])->full_name]]);
        $field = $I->grabDataFromResponseByJsonPath('$.field')[0];
        $I->assertEqualsWithDelta($mine['lat'] + 0.004, $field['location']['lat'], 1e-6);
        $I->assertEqualsWithDelta($mine['lon'], $field['location']['lon'], 1e-6);
        $I->assertEquals(8.0, $field['location']['accuracy_m']);

        // Photos: only for a synced capture, once per id, at most the setting's number.
        $I->amBearerOf(self::INSPECTOR);
        $photo = self::uuid();
        $file = ['file' => ['name' => 'p.jpg', 'type' => 'image/jpeg', 'error' => UPLOAD_ERR_OK, 'size' => filesize(self::PHOTO), 'tmp_name' => self::PHOTO]];
        $I->deleteHeader('Content-Type');
        $I->sendPost('/v1/field/photos', ['client_id' => self::uuid(), 'capture_client_id' => self::uuid()], $file);
        $I->seeApiError(409, 'CAPTURE_NOT_SYNCED');
        $I->sendPost('/v1/field/photos', ['client_id' => $photo, 'capture_client_id' => $items[1]['client_id']], $file);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['status' => 'created', 'result' => ['observation_id' => $capture['observation_id']]]);
        $I->sendPost('/v1/field/photos', ['client_id' => $photo, 'capture_client_id' => $items[1]['client_id']], $file);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'replayed']);
        for ($n = 0; $n < 3; $n++) {
            $I->sendPost('/v1/field/photos', ['client_id' => self::uuid(), 'capture_client_id' => $items[1]['client_id']], $file);
            $I->seeResponseCodeIs(201);
        }
        $I->sendPost('/v1/field/photos', ['client_id' => self::uuid(), 'capture_client_id' => $items[1]['client_id']], $file);
        $I->seeApiError(422, 'TOO_MANY_PHOTOS');

        // Another account cannot reuse (or read back) an id.
        $I->amBearerOf(Auth::INSPECTOR);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $other = $this->sync($I, [$items[1]]);
        $I->assertSame('CLIENT_ID_CONFLICT', $other[$items[1]['client_id']]['error']['code']);
    }

    public function scopingAndPermissions(ApiTester $I): void
    {
        // A mine head records a self-inspection at its own mine; another mine is 404, as everywhere.
        $head = Auth::user(Auth::MINE_HEAD_BHUBANESWARI);
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/field/bootstrap');
        $b = json_decode($I->grabResponse(), true);
        $I->assertSame([(int) $head->mine_id], array_column($b['mines'], 'id'));
        $I->assertSame([], $b['inspections']);
        $own = self::uuid();
        $foreign = self::uuid();
        $r = $this->sync($I, [
            ['kind' => 'visit', 'client_id' => $own, 'mine_id' => $head->mine_id, 'recorded_at' => gmdate('Y-m-d\TH:i:s\Z')],
            ['kind' => 'visit', 'client_id' => $foreign, 'mine_id' => 1, 'recorded_at' => gmdate('Y-m-d\TH:i:s\Z')],
            ['kind' => 'capture', 'client_id' => self::uuid(), 'visit_client_id' => $foreign, 'category' => 'ppe', 'severity' => 'low',
                'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'), 'location_status' => 'unknown_underground'],
        ]);
        $I->assertSame('created', $r[$own]['status']);
        $I->assertSame('self', Inspection::findOne($r[$own]['result']['inspection_id'])->inspection_type);
        $I->assertSame(['code' => 'NOT_FOUND', 'http_status' => 404], $r[$foreign]['error']);
        $I->assertSame('VISIT_NOT_SYNCED', array_values($r)[2]['error']['code']);

        // An inspector may not use another inspector's inspection.
        $taken = Inspection::find()->where(['status' => 'scheduled'])->andWhere(['<>', 'inspector_id', Auth::user(Auth::INSPECTOR)->id])->one();
        $I->amBearerOf(Auth::INSPECTOR);
        $v = self::uuid();
        $r = $this->sync($I, [['kind' => 'visit', 'client_id' => $v, 'mine_id' => $taken->mine_id, 'inspection_id' => $taken->id]]);
        $I->assertSame('INSPECTION_NOT_ASSIGNED', $r[$v]['error']['code']);

        // Government and corporate do not use the field app.
        foreach ([Auth::GOVERNMENT, Auth::CORPORATE_SECL] as $who) {
            $I->amBearerOf($who);
            $I->sendGet('/v1/field/bootstrap');
            $I->seeApiError(403, 'FORBIDDEN');
        }
    }

    public function farAwayOrWrongClockIsFlaggedNotRefused(ApiTester $I): void
    {
        [$inspection, $mine] = $this->assignment($I);
        $visit = self::uuid();
        $near = $this->capture($visit, $mine, ['violation_type' => null, 'corrective_action' => null]);
        $far = $this->capture($visit, $mine, ['violation_type' => null, 'corrective_action' => null,
            'location' => ['lat' => $mine['lat'] + 1.8, 'lon' => $mine['lon'], 'accuracy_m' => 30]]);
        $r = $this->sync($I, [
            ['kind' => 'visit', 'client_id' => $visit, 'mine_id' => $mine['id'], 'inspection_id' => $inspection['id']],
            $near, $far,
        ]);
        $I->assertFalse($r[$near['client_id']]['result']['geo_flag']);
        $I->assertLessThan(1000, $r[$near['client_id']]['result']['distance_m']);
        $I->assertTrue($r[$far['client_id']]['result']['geo_flag']);
        $I->assertGreaterThan(190000, $r[$far['client_id']]['result']['distance_m']);
        $I->assertTrue((bool) Observation::findOne($r[$far['client_id']]['result']['observation_id'])->geo_flag);

        // A phone clock two hours ahead: flagged; the observation's time falls back to receipt.
        $late = $this->capture($visit, $mine, ['violation_type' => null, 'corrective_action' => null, 'recorded_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 7200)]);
        $r = $this->sync($I, [$late], gmdate('Y-m-d\TH:i:s\Z', time() + 7200));
        $result = $r[$late['client_id']]['result'];
        $I->assertTrue($result['clock_flag']);
        $I->assertGreaterThan(7000, $result['clock_skew_s']);
        $observation = Observation::findOne($result['observation_id']);
        $I->assertLessThan(120, abs(strtotime($observation->observed_at) - strtotime($observation->received_at)));

        // A bad item fails alone; the rest of the batch goes through.
        $bad = $this->capture($visit, $mine, ['category' => 'astrology']);
        $good = $this->capture($visit, $mine, ['violation_type' => null, 'corrective_action' => null]);
        $r = $this->sync($I, [$bad, $good]);
        $I->assertSame('VALIDATION_FAILED', $r[$bad['client_id']]['error']['code']);
        $I->assertSame('created', $r[$good['client_id']]['status']);
    }

    public function expiredLoginKeepsTheQueue(ApiTester $I): void
    {
        [$inspection, $mine] = $this->assignment($I);
        $visit = self::uuid();
        $items = [['kind' => 'visit', 'client_id' => $visit, 'mine_id' => $mine['id'], 'inspection_id' => $inspection['id']],
            $this->capture($visit, $mine)];
        $observations = Observation::find()->count();

        // Queued while the token expired: the batch is refused whole, nothing is written.
        $expired = Auth::user(self::INSPECTOR)->issueToken(new \DateTimeImmutable('-13 hours', new \DateTimeZone('UTC')));
        $I->haveHttpHeader('Authorization', "Bearer $expired");
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/v1/field/sync', ['device_now' => gmdate('Y-m-d\TH:i:s\Z'), 'items' => $items]);
        $I->seeResponseCodeIs(401);
        $I->assertEquals($observations, Observation::find()->count());
        $I->assertSame('scheduled', Inspection::findOne($inspection['id'])->status);

        // Signed in again, the same queue goes through once.
        $I->amBearerOf(self::INSPECTOR);
        $r = $this->sync($I, $items);
        $I->assertSame(['created', 'created'], array_column(array_values($r), 'status'));
        $I->assertEquals($observations + 1, Observation::find()->count());
    }

    public function closedInspectionIsAConflict(ApiTester $I): void
    {
        [$inspection, $mine] = $this->assignment($I);
        Inspection::updateAll(['status' => 'closed', 'is_locked' => true, 'closed_at' => gmdate('Y-m-d H:i:sP')], ['id' => $inspection['id']]);
        $I->amBearerOf(self::INSPECTOR);
        $visit = self::uuid();
        $capture = $this->capture($visit, $mine);
        $r = $this->sync($I, [['kind' => 'visit', 'client_id' => $visit, 'mine_id' => $mine['id'], 'inspection_id' => $inspection['id']], $capture]);
        $I->assertSame('INVALID_TRANSITION', $r[$visit]['error']['code']);
        $I->assertSame(['from' => 'closed', 'to' => 'visited'], $r[$visit]['error']['params']);
        $I->assertSame('VISIT_NOT_SYNCED', $r[$capture['client_id']]['error']['code']);
    }
}
