<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\Alert;
use app\models\DailyProduction;
use app\models\ProductionDetailRequest;
use app\services\DetailRequestService;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;
use Yii;

/**
 * Production reporting (brief Phase 4): the mine head's entries (draft, submit, edit with a reason
 * into the edit log), the numbers-only summary, the detail gate (403 DETAIL_REQUEST_REQUIRED until
 * an answered "Call for Detailed Report" covers the range), the request workflow and escalation.
 */
class ProductionCest
{
    private const GEVRA_HEAD = 'head.cg-krb-03@coalmine.in';   // Gevra, CG-KRB-03, SECL - scenario S2

    public function mineHeadEntersSubmitsAndCorrectsWithAReason(ApiTester $I): void
    {
        $I->amBearerOf(self::GEVRA_HEAD);
        $entry = ['date' => '2026-09-26', 'shift' => 'A', 'coal_target_t' => 3000, 'coal_actual_t' => 2800.5, 'ob_target_m3' => 9000,
            'ob_actual_m3' => 8700, 'dispatch_t' => 2500, 'closing_stock_t' => 41000, 'breakdown_hours' => 1.5, 'manpower_present' => 610,
            'remarks' => 'Dragline 3 down 90 minutes'];
        $I->sendPost('/v1/production', $entry);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['status' => 'draft', 'is_locked' => false, 'coal_actual_t' => 2800.5, 'mine_id' => Auth::user(self::GEVRA_HEAD)->mine_id]);
        $id = $I->grabDataFromResponseByJsonPath('$.id')[0];

        $I->sendPost('/v1/production', $entry);
        $I->seeResponseContainsJson(['error' => ['fields' => ['shift' => ['ALREADY_REPORTED']]]]);
        $I->sendPost('/v1/production', ['date' => '2099-01-01'] + $entry);
        $I->seeResponseContainsJson(['error' => ['fields' => ['date' => ['IN_FUTURE']]]]);

        // A draft changes freely - no edit log.
        $I->sendPatch("/v1/production/$id", ['coal_actual_t' => 2850]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['coal_actual_t' => 2850.0, 'edits' => []]);

        $I->sendPost("/v1/production/$id/submit");
        $I->seeResponseContainsJson(['status' => 'submitted', 'is_locked' => true]);
        $I->sendPost("/v1/production/$id/submit");
        $I->seeApiError(422, 'INVALID_TRANSITION');

        // Submitted: a change needs a reason and lands in the edit log.
        $I->sendPatch("/v1/production/$id", ['coal_actual_t' => 2900]);
        $I->seeResponseContainsJson(['error' => ['fields' => ['reason' => ['REASON_REQUIRED']]]]);
        $I->sendPatch("/v1/production/$id", ['coal_actual_t' => 2900, 'manpower_present' => 612, 'reason' => 'Weighbridge reading corrected']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['coal_actual_t' => 2900.0, 'manpower_present' => 612]);
        $I->seeResponseContainsJson(['edits' => [['field' => 'coal_actual_t', 'old_value' => '2850', 'new_value' => '2900', 'reason' => 'Weighbridge reading corrected']]]);
        $I->assertCount(2, $I->grabDataFromResponseByJsonPath('$.edits[*]'));
        $I->sendPatch("/v1/production/$id", ['coal_actual_t' => 2900, 'reason' => 'No change at all']);
        $I->seeApiError(422, 'NOTHING_CHANGED');
        $I->sendDelete("/v1/production/$id");
        $I->seeApiError(422, 'ENTRY_LOCKED');

        // Another mine's entry does not exist for this account.
        $other = DailyProduction::find()->where(['<>', 'mine_id', Auth::user(self::GEVRA_HEAD)->mine_id])->one();
        $I->sendPatch("/v1/production/{$other->id}", ['coal_actual_t' => 1, 'reason' => 'not mine to edit']);
        $I->seeApiError(404, 'NOT_FOUND');
    }

    public function summaryIsNumbersOnlyAndFlagsS2(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/production/summary', ['date' => '2026-09-25']);
        $I->seeResponseCodeIs(200);
        $rows = array_column($I->grabDataFromResponseByJsonPath('$.mines')[0], null, 'code');
        $I->assertCount(74, $rows);
        $gevra = $rows['CG-KRB-03'];
        $I->assertTrue($gevra['anomaly']['flagged']);
        $I->assertContains('2026-09-04', array_column($gevra['anomaly']['days'], 'date'));
        $I->assertSame(['target_t', 'actual_t', 'achievement_pct', 'shifts_reported'], array_keys($gevra['today']));
        $I->assertFalse($rows['JH-CHA-09']['anomaly']['flagged'] ?? false, 'N1 decoy not flagged');
        // Numbers only: no entries, remarks or edit reasons anywhere in the summary.
        $I->dontSeeResponseJsonMatchesJsonPath('$.mines[*].remarks');
        $I->dontSeeResponseJsonMatchesJsonPath('$.mines[*].entries');

        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendGet('/v1/production/summary', ['date' => '2026-09-25']);
        $I->assertCount(17, $I->grabDataFromResponseByJsonPath('$.mines[*]'));

        $I->amBearerOf(self::GEVRA_HEAD);
        $I->sendGet('/v1/production/summary');
        $I->seeApiError(403, 'FORBIDDEN');
    }

    public function detailNeedsAnAnsweredRequestCoveringTheRange(ApiTester $I): void
    {
        $mineId = Auth::user(self::GEVRA_HEAD)->mine_id;
        $range = ['mine_id' => $mineId, 'from' => '2026-09-01', 'to' => '2026-09-07'];

        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/production/detail', $range);
        $I->seeApiError(403, 'DETAIL_REQUEST_REQUIRED');
        $I->seeResponseContainsJson(['error' => ['params' => ['mine_id' => $mineId, 'from' => '2026-09-01', 'to' => '2026-09-07']]]);

        // Call for the detailed report.
        $due = gmdate('Y-m-d\TH:i:s\Z', time() + 3 * 86400);
        $I->sendPost('/v1/detail-requests', ['mine_id' => $mineId, 'date_from' => '2026-09-01', 'date_to' => '2026-09-07',
            'reason' => 'Output on 4 September is twice the 30-day average, the day before an inspection.', 'due_at' => $due]);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['status' => 'pending', 'is_fulfilled' => false, 'mine_id' => $mineId]);
        $requestId = $I->grabDataFromResponseByJsonPath('$.id')[0];
        $I->sendGet('/v1/production/detail', $range);
        $I->seeApiError(403, 'DETAIL_REQUEST_REQUIRED');   // asked, not answered

        // Only the mine answers; the mine head sees its own detail anyway.
        $I->sendPost("/v1/detail-requests/$requestId/respond", ['response_note' => 'Shift registers attached']);
        $I->seeApiError(403, 'FORBIDDEN');
        $I->amBearerOf(self::GEVRA_HEAD);
        $I->sendGet('/v1/production/detail', $range);
        $I->seeResponseCodeIs(200);
        $I->sendPost("/v1/detail-requests/$requestId/respond", ['response_note' => 'Shift registers and weighbridge slips attached.'],
            ['file' => codecept_data_dir('pixel.png')]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'submitted', 'is_fulfilled' => true, 'responded_by' => Auth::user(self::GEVRA_HEAD)->id]);
        $I->assertStringStartsWith('/v1/files/', $I->grabDataFromResponseByJsonPath('$.response_url')[0]);

        // Now government, and corporate SECL (same mine in scope), see the covered range - not beyond it.
        foreach ([Auth::GOVERNMENT, Auth::CORPORATE_SECL] as $who) {
            $I->amBearerOf($who);
            $I->sendGet('/v1/production/detail', $range);
            $I->seeResponseCodeIs(200);
            $I->seeResponseContainsJson(['request' => ['id' => $requestId, 'status' => 'submitted']]);
            $I->assertCount(21, $I->grabDataFromResponseByJsonPath('$.entries[*]'));
            $I->assertContains('2026-09-04', $I->grabDataFromResponseByJsonPath('$.charts.anomalies[*].date'));
            $I->sendGet('/v1/production/detail', ['to' => '2026-09-08'] + $range);
            $I->seeApiError(403, 'DETAIL_REQUEST_REQUIRED');
        }

        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendPost("/v1/detail-requests/$requestId/close", ['note' => 'Accepted']);
        $I->seeResponseContainsJson(['status' => 'closed']);
        $I->sendGet('/v1/production/detail', $range);
        $I->seeResponseCodeIs(200);
    }

    public function detailOfAMineOutOfScopeIs404(ApiTester $I): void
    {
        $gevra = Auth::user(self::GEVRA_HEAD)->mine_id;
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/production/detail', ['mine_id' => $gevra]);
        $I->seeApiError(404, 'NOT_FOUND');
        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendPost('/v1/detail-requests', ['mine_id' => Auth::user(Auth::MINE_HEAD_BHUBANESWARI)->mine_id, 'date_from' => '2026-09-01',
            'date_to' => '2026-09-02', 'reason' => 'Not a SECL mine at all.', 'due_at' => gmdate('c', time() + 86400)]);
        $I->seeApiError(404, 'NOT_FOUND');
    }

    public function requestValidationAndRoles(ApiTester $I): void
    {
        $mineId = Auth::user(self::GEVRA_HEAD)->mine_id;
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendPost('/v1/detail-requests', ['mine_id' => $mineId, 'date_from' => '2026-09-07', 'date_to' => '2026-09-01',
            'reason' => 'short', 'due_at' => '2020-01-01T00:00:00Z']);
        $I->seeResponseContainsJson(['error' => ['fields' => ['date_to' => ['BEFORE_START'], 'reason' => ['TOO_SHORT'], 'due_at' => ['IN_PAST']]]]);
        $I->amBearerOf(self::GEVRA_HEAD);
        $I->sendPost('/v1/detail-requests', ['mine_id' => $mineId]);
        $I->seeApiError(403, 'FORBIDDEN');
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendPost('/v1/production', ['date' => '2026-09-26', 'shift' => 'A']);
        $I->seeApiError(403, 'FORBIDDEN');
    }

    public function overdueRequestsEscalateWithAlerts(ApiTester $I): void
    {
        $mineId = Auth::user(self::GEVRA_HEAD)->mine_id;
        $request = new ProductionDetailRequest([
            'mine_id' => $mineId, 'requested_by' => Auth::user(Auth::GOVERNMENT)->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-03',
            'reason' => 'Breakdown hours unusually high.', 'status' => 'pending',
            'due_at' => gmdate('Y-m-d H:i:s+00', time() - 3600), 'created_at' => gmdate('Y-m-d H:i:s+00', time() - 5 * 86400),
        ]);
        $request->save(false);
        $alerts = fn() => Alert::find()->where(['code' => 'DETAIL_REQUEST_OVERDUE', 'entity_id' => $request->id])->all();

        // Reading the requests runs the deadline check: an hour past due -> overdue, alert level 1.
        $I->amBearerOf(self::GEVRA_HEAD);
        $I->sendGet('/v1/detail-requests');
        $I->seeResponseContainsJson([['id' => $request->id, 'status' => 'overdue']]);
        $I->assertCount(1, $alerts());
        $I->assertSame(1, (int) $alerts()[0]->escalation_level);
        $I->assertNull(\app\models\StatusHistory::find()->where(['entity' => 'production_detail_request', 'entity_id' => $request->id])->one()->user_id,
            'a system transition, not the viewer\'s');

        // Past the escalation window -> escalated, the same alert at level 2; idempotent after that.
        Yii::$app->db->createCommand()->update('production_detail_request', ['due_at' => gmdate('Y-m-d H:i:s+00', time() - 80 * 3600)], ['id' => $request->id])->execute();
        $I->assertSame(['overdue' => 0, 'escalated' => 1], DetailRequestService::escalateDue());
        $I->assertSame(['overdue' => 0, 'escalated' => 0], DetailRequestService::escalateDue());
        $I->assertCount(1, $alerts());
        $I->assertSame(2, (int) $alerts()[0]->escalation_level);

        // A late answer is still an answer, and it resolves the alert.
        $I->sendPost("/v1/detail-requests/{$request->id}/respond", ['response_note' => 'Late, with apologies: registers attached.']);
        $I->seeResponseContainsJson(['status' => 'submitted']);
        $I->assertSame(Alert::STATUS_RESOLVED, $alerts()[0]->status);
    }

    public function productionViewsAreOneRequestPerScreen(ApiTester $I): void
    {
        $I->amBearerOf(self::GEVRA_HEAD);
        $I->sendGet('/v1/views/production', ['month' => '2026-09']);
        $I->seeResponseCodeIs(200);
        $I->assertSame(['month', 'today', 'entries', 'charts', 'requests'], array_keys(json_decode($I->grabResponse(), true)));
        $I->assertContains('2026-09-04', $I->grabDataFromResponseByJsonPath('$.charts.anomalies[*].date'));
        $I->assertSame(['A', 'B', 'C'], $I->grabDataFromResponseByJsonPath('$.charts.shifts[*].shift'));

        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendGet('/v1/views/production-overview');
        $I->seeResponseCodeIs(200);
        $I->assertCount(17, $I->grabDataFromResponseByJsonPath('$.summary.mines[*]'));
        $I->sendGet('/v1/views/production');
        $I->seeApiError(403, 'FORBIDDEN');
    }
}
