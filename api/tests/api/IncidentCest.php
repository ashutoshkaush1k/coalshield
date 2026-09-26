<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\Alert;
use app\models\Incident;
use app\models\Violation;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/** Incidents (HANDOFF C23): scoped list, detail, 48-hour reporting check with its obligation, links. */
class IncidentCest
{
    public function listAndDetailCarryTheReportingCheck(ApiTester $I): void
    {
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/incidents');
        $I->seeResponseCodeIs(200);
        foreach ($I->grabDataFromResponseByJsonPath('$[*].mine_id') as $mineId) {
            $I->assertSame(5, $mineId);
        }
        // S1: the dangerous occurrence on the HIGH demo mine, linked to a strata violation.
        $I->seeResponseContainsJson([['severity' => 'dangerous_occurrence', 'obligation_code' => 'RPT-05',
            'reporting_check' => ['code' => 'REPORTED_WITHIN_48H', 'params' => ['limit_hours' => 48, 'obligation_code' => 'RPT-05']]]]);
        $id = $I->grabDataFromResponseByJsonPath('$[0].id')[0];
        $I->sendGet("/v1/incidents/$id");
        $I->seeResponseMatchesJsonType(['relatedViolation' => 'array|null']);
    }

    public function lateReportsAreFlagged(ApiTester $I): void
    {
        $late = Incident::find()->where(['reported_within_48h' => false])->one();
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/incidents', ['late' => 1, 'per_page' => 200]);
        $I->seeResponseCodeIs(200);
        if ($late === null) {
            $I->seeResponseEquals('[]');
            return;
        }
        $I->seeResponseContainsJson([['id' => $late->id, 'reporting_check' => ['code' => 'REPORTED_AFTER_48H']]]);
    }

    public function reportingADangerousOccurrenceRaisesAnAlert(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $occurred = gmdate('Y-m-d\TH:i:s\Z', time() - 72 * 3600);
        $I->sendPost('/v1/incidents', [
            'mine_id' => 5, 'occurred_at' => $occurred, 'type' => 'gas_dust_fire', 'severity' => 'dangerous_occurrence',
            'persons_affected' => 3, 'description_code' => 'SPONTANEOUS_HEATING',
        ]);
        $I->seeResponseCodeIs(201);
        // Obligation follows the severity; a dangerous occurrence has nobody killed or injured;
        // 72 h after the event is a late report.
        $I->seeResponseContainsJson(['obligation_code' => 'RPT-05', 'persons_affected' => 0, 'reported_within_48h' => false,
            'reporting_check' => ['code' => 'REPORTED_AFTER_48H']]);
        $id = $I->grabDataFromResponseByJsonPath('$.id')[0];
        $I->assertNotNull(Alert::findOne(['code' => 'DANGEROUS_OCCURRENCE_REPORTED', 'entity_type' => 'incident', 'entity_id' => $id]));
    }

    public function validationAndLinks(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendPost('/v1/incidents', ['mine_id' => 5, 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'reported_at' => '2020-01-01T00:00:00Z',
            'type' => 'meteor', 'severity' => 'serious', 'description_code' => 'fall of roof']);
        $I->seeApiError(422, 'VALIDATION_FAILED');
        $I->seeResponseContainsJson(['error' => ['fields' => ['type' => ['INVALID_VALUE'], 'description_code' => ['INVALID_VALUE']]]]);

        $incident = Incident::find()->where(['mine_id' => 5])->one();
        $own = Violation::find()->where(['mine_id' => 5])->one();
        $other = Violation::find()->where(['mine_id' => 1])->one();
        $I->sendPatch("/v1/incidents/{$incident->id}/violation", ['related_violation_id' => $other->id]);
        $I->seeApiError(404, 'NOT_FOUND');
        $I->sendPatch("/v1/incidents/{$incident->id}/violation", ['related_violation_id' => $own->id]);
        $I->seeResponseContainsJson(['related_violation_id' => $own->id, 'relatedViolation' => ['id' => $own->id]]);
        $I->sendPatch("/v1/incidents/{$incident->id}/violation", ['related_violation_id' => null]);
        $I->seeResponseContainsJson(['related_violation_id' => null]);

        $foreign = Incident::find()->where(['<>', 'mine_id', 5])->one();
        $I->sendGet("/v1/incidents/{$foreign->id}");
        $I->seeApiError(404, 'NOT_FOUND');
    }
}
