<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\Alert;
use app\models\Grievance;
use app\models\GrievanceAction;
use app\models\Observation;
use app\services\GrievanceService;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;
use Yii;

/**
 * Grievance handling (brief Phase 5): the public endpoints (no login, rate limit, file checks,
 * honeypot, tickets), sensitive routing (a mine head never sees a harassment grievance or one
 * against the mine head - not listed, not by id, not through its alert or the audit trail), the
 * complainant's identity never serialised to a mine head, the queue workflow, SLA escalation and
 * the analytics per role.
 */
class GrievanceCest
{
    private const GEVRA_HEAD = 'head.cg-krb-03@coalmine.in';   // CG-KRB-03: two harassment grievances in the seed

    private function publicForm(array $override = []): array
    {
        return $override + [
            'mine_id' => Auth::user(self::GEVRA_HEAD)->mine_id, 'submitter_type' => 'contract_worker', 'name' => 'Test Complainant',
            'contact' => '9000000001', 'category' => 'wages', 'language' => 'hi',
            'description' => 'ठेकेदार ने पिछले तीन हफ्तों से मजदूरी नहीं दी है।', 'website' => '',
        ];
    }

    public function publicSubmissionNeedsNoLoginAndCanBeTracked(ApiTester $I): void
    {
        $maxSeeded = (int) Yii::$app->db->createCommand("SELECT max(substr(ticket_no, 10)::int) FROM grievance WHERE ticket_no LIKE 'GRV-2026-%'")->queryScalar();
        $I->sendPost('/v1/grievances/public', $this->publicForm());
        $I->seeResponseCodeIs(201);
        $ticket = $I->grabDataFromResponseByJsonPath('$.ticket_no')[0];
        $I->assertMatchesRegularExpression('/^GRV-\d{4}-\d{6}$/', $ticket);
        $I->assertGreaterThan($maxSeeded, (int) substr($ticket, 9));
        $I->assertSame(['ticket_no', 'status', 'sla_due_at', 'created_at'], array_keys(json_decode($I->grabResponse(), true)));
        $grievance = Grievance::find()->where(['ticket_no' => $ticket])->one();
        $I->assertSame('received', $grievance->status);
        $I->assertSame(Auth::user(self::GEVRA_HEAD)->id, $grievance->assigned_to, 'routed to the mine head');
        $I->assertSame(168, (int) round((strtotime($grievance->sla_due_at) - strtotime($grievance->created_at)) / 3600), 'wages SLA from rules.yaml');

        $I->sendGet("/v1/grievances/track/$ticket");
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['ticket_no' => $ticket, 'status' => 'received', 'timeline' => [['action' => 'submit', 'status' => 'received']]]);
        $I->dontSeeResponseContains('Test Complainant');
        $I->dontSeeResponseContains('9000000001');
        $I->dontSeeResponseJsonMatchesJsonPath('$.description');
        $I->sendGet('/v1/grievances/track/GRV-2026-999999');
        $I->seeApiError(404, 'NOT_FOUND');

        // Anonymous: no name or contact is stored even if sent.
        $I->sendPost('/v1/grievances/public', $this->publicForm(['is_anonymous' => 'true', 'submitter_type' => 'employee']));
        $anon = Grievance::find()->where(['ticket_no' => $I->grabDataFromResponseByJsonPath('$.ticket_no')[0]])->one();
        $I->assertTrue((bool) $anon->is_anonymous);
        $I->assertNull($anon->name);
        $I->assertNull($anon->contact);
        $I->assertSame('anonymous', $anon->submitter_type);
    }

    public function publicSubmissionChecksHoneypotFilesAndFields(ApiTester $I): void
    {
        $before = (int) Grievance::find()->count();
        $I->sendPost('/v1/grievances/public', $this->publicForm(['website' => 'http://spam.example']));
        $I->seeApiError(400, 'SUBMISSION_REJECTED');
        $I->assertSame($before, (int) Grievance::find()->count(), 'the honeypot creates nothing');

        $I->sendPost('/v1/grievances/public', $this->publicForm(['category' => 'nonsense', 'description' => 'short', 'mine_id' => 999999]));
        $I->seeResponseContainsJson(['error' => ['code' => 'VALIDATION_FAILED', 'fields' => ['category' => ['INVALID_VALUE'], 'description' => ['TOO_SHORT'], 'mine_id' => ['REQUIRED']]]]);

        $text = codecept_output_dir('not-an-image.txt');
        file_put_contents($text, 'plain text, not an image or a PDF');
        // In the test all requests share one PHP process, and Yii caches the uploaded files per
        // process: clear that cache before a request with a file (a real request is a new process).
        \yii\web\UploadedFile::reset();
        $I->sendPost('/v1/grievances/public', $this->publicForm(), ['file' => $text]);
        $I->seeResponseContainsJson(['error' => ['fields' => ['file' => ['FILE_TYPE_NOT_ALLOWED']]]]);
        \yii\web\UploadedFile::reset();
        $I->sendPost('/v1/grievances/public', $this->publicForm(), ['file' => codecept_data_dir('pixel.png')]);
        $I->seeResponseCodeIs(201);
        $withFile = Grievance::find()->where(['ticket_no' => $I->grabDataFromResponseByJsonPath('$.ticket_no')[0]])->one();
        $I->assertNotNull($withFile->file_id);
        $I->assertNull($withFile->file->uploaded_by, 'no uploader account for a public file');
    }

    public function publicSubmissionIsRateLimited(ApiTester $I): void
    {
        $limit = (int) Yii::$app->params['grievance.submitPerHour'];
        for ($i = 0; $i < $limit; $i++) {
            $I->sendPost('/v1/grievances/public', $this->publicForm());
            $I->seeResponseCodeIs(201);
        }
        $I->sendPost('/v1/grievances/public', $this->publicForm());
        $I->seeApiError(429, 'RATE_LIMITED');
        $I->seeHttpHeaderOnce('Retry-After');
    }

    public function safetyGrievanceCreatesAnObservationUnlessSensitive(ApiTester $I): void
    {
        $I->sendPost('/v1/grievances/public', $this->publicForm(['category' => 'safety', 'safety_category' => 'ppe',
            'description' => 'No helmets issued to the new loaders on bench 4 this week.']));
        $safety = Grievance::find()->where(['ticket_no' => $I->grabDataFromResponseByJsonPath('$.ticket_no')[0]])->one();
        $observation = Observation::find()->where(['grievance_id' => $safety->id])->one();
        $I->assertNotNull($observation);
        $I->assertSame(['ppe', 'high', 'open', $safety->mine_id], [$observation->category, $observation->severity, $observation->status, (int) $observation->mine_id]);

        $I->sendPost('/v1/grievances/public', $this->publicForm(['category' => 'safety', 'safety_category' => 'ppe', 'against_mine_head' => 'true',
            'description' => 'The mine head told us to work without helmets on bench 4.']));
        $sensitive = Grievance::find()->where(['ticket_no' => $I->grabDataFromResponseByJsonPath('$.ticket_no')[0]])->one();
        $I->assertFalse(Observation::find()->where(['grievance_id' => $sensitive->id])->exists(), 'an observation would show it to the mine head');
        $I->assertSame(Auth::user(Auth::GOVERNMENT)->id, $sensitive->assigned_to, 'routed to the government');
    }

    public function sensitiveGrievancesDoNotExistForTheMineHead(ApiTester $I): void
    {
        $mineId = Auth::user(self::GEVRA_HEAD)->mine_id;
        $sensitive = Grievance::find()->where(['mine_id' => $mineId])->andWhere("category = 'harassment' OR against_mine_head")->all();
        $I->assertNotEmpty($sensitive, 'the seed has sensitive grievances at this mine');
        $sensitiveIds = array_map(fn($g) => (int) $g->id, $sensitive);

        // An SLA breach on one of them raises an alert at this mine - which the mine head must not see either.
        Yii::$app->db->createCommand()->update('grievance', ['status' => 'acknowledged', 'escalation_level' => 0,
            'sla_due_at' => gmdate('Y-m-d H:i:s+00', time() - 3600)], ['id' => $sensitiveIds[0]])->execute();
        GrievanceService::escalateDue();
        $alertId = (int) Alert::find()->where(['code' => 'GRIEVANCE_SLA_BREACHED', 'entity_id' => $sensitiveIds[0]])->one()->id;

        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/grievances', ['mine_id' => $mineId, 'per_page' => 200]);
        foreach ($sensitiveIds as $id) {
            $I->assertContains($id, $I->grabDataFromResponseByJsonPath('$[*].id'), 'government sees it');
        }

        $I->amBearerOf(self::GEVRA_HEAD);
        $I->sendGet('/v1/grievances', ['per_page' => 200]);
        $I->assertEmpty(array_intersect($sensitiveIds, $I->grabDataFromResponseByJsonPath('$[*].id')), 'not listed');
        $I->sendGet('/v1/grievances', ['sensitive' => 1]);
        $I->seeResponseEquals('[]');
        foreach ($sensitiveIds as $id) {
            $I->sendGet("/v1/grievances/$id");
            $I->seeApiError(404, 'NOT_FOUND');
            $I->sendPost("/v1/grievances/$id/transition", ['to' => 'resolved', 'note' => 'Trying anyway']);
            $I->seeApiError(404, 'NOT_FOUND');
        }
        $I->sendGet('/v1/alerts', ['mine_id' => $mineId, 'per_page' => 200]);
        $I->assertNotContains($alertId, $I->grabDataFromResponseByJsonPath('$[*].id'), 'nor its alert');
        $I->sendGet("/v1/alerts/$alertId");
        $I->seeApiError(404, 'NOT_FOUND');
        $I->sendGet("/v1/mines/$mineId");
        $openAlerts = $I->grabDataFromResponseByJsonPath('$.open_alerts')[0];
        $I->sendGet('/v1/alerts', ['mine_id' => $mineId, 'status' => 'open', 'per_page' => 200]);
        $I->assertSame(count($I->grabDataFromResponseByJsonPath('$[*]')), $openAlerts, 'the open-alert count matches what the mine head can open');
        $I->sendGet('/v1/audit', ['mine_id' => $mineId, 'per_page' => 200, 'entity' => 'grievance']);
        $I->assertEmpty(array_intersect($sensitiveIds, $I->grabDataFromResponseByJsonPath('$[*].entity_id')), 'nor its audit trail');
        $I->sendGet('/v1/audit', ['mine_id' => $mineId, 'per_page' => 200, 'entity' => 'alert']);
        $I->assertNotContains($alertId, $I->grabDataFromResponseByJsonPath('$[*].entity_id'));
        $I->sendGet('/v1/views/grievances');
        foreach ($sensitive as $g) {
            $I->dontSeeResponseContains($g->ticket_no);
        }

        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/audit', ['mine_id' => $mineId, 'per_page' => 200, 'entity' => 'grievance']);
        $I->assertNotEmpty(array_intersect($sensitiveIds, $I->grabDataFromResponseByJsonPath('$[*].entity_id')), 'government sees the trail');
    }

    public function complainantIdentityIsNeverSerialisedToAMineHead(ApiTester $I): void
    {
        $mineId = Auth::user(self::GEVRA_HEAD)->mine_id;
        $names = array_filter(Grievance::find()->select('name')->where(['mine_id' => $mineId])->column());
        $contacts = array_filter(Grievance::find()->select('contact')->where(['mine_id' => $mineId])->column());
        $I->assertNotEmpty($names);

        $I->amBearerOf(self::GEVRA_HEAD);
        $I->sendGet('/v1/grievances', ['per_page' => 200]);
        $I->assertNotEmpty($I->grabDataFromResponseByJsonPath('$[*].id'));
        $ids = $I->grabDataFromResponseByJsonPath('$[*].id');
        $responses = [$I->grabResponse()];
        foreach ($ids as $id) {
            $I->sendGet("/v1/grievances/$id");
            $I->dontSeeResponseJsonMatchesJsonPath('$.name');
            $I->dontSeeResponseJsonMatchesJsonPath('$.contact');
            $responses[] = $I->grabResponse();
        }
        $I->sendGet('/v1/views/grievances');
        $responses[] = $I->grabResponse();
        foreach ($responses as $body) {
            $I->assertStringNotContainsString('"name":', $body, 'no name field in a grievance payload for a mine head');
            $I->assertStringNotContainsString('"contact":', $body);
        }
        $I->sendGet('/v1/audit', ['mine_id' => $mineId, 'per_page' => 200]);
        $responses[] = $I->grabResponse();
        foreach ($responses as $body) {
            foreach ([...$names, ...$contacts] as $secret) {
                $I->assertStringNotContainsString($secret, $body, 'a complainant name or contact reached the mine head');
            }
        }

        // The regulator sees who complained; corporate only for grievances that are not sensitive.
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet("/v1/grievances/{$ids[0]}");
        $I->seeResponseJsonMatchesJsonPath('$.name');
        $sensitiveId = (int) Grievance::find()->where(['mine_id' => $mineId, 'category' => 'harassment'])->one()->id;
        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendGet("/v1/grievances/{$ids[0]}");
        $I->seeResponseJsonMatchesJsonPath('$.name');
        $I->sendGet("/v1/grievances/$sensitiveId");
        $I->seeResponseCodeIs(200);
        $I->dontSeeResponseJsonMatchesJsonPath('$.name');
        $I->dontSeeResponseJsonMatchesJsonPath('$.contact');
    }

    public function mineHeadWorksTheQueue(ApiTester $I): void
    {
        $I->sendPost('/v1/grievances/public', $this->publicForm());
        $id = (int) Grievance::find()->where(['ticket_no' => $I->grabDataFromResponseByJsonPath('$.ticket_no')[0]])->one()->id;

        $I->amBearerOf(self::GEVRA_HEAD);
        $I->sendPost("/v1/grievances/$id/transition", ['to' => 'acknowledged']);
        $I->seeResponseContainsJson(['status' => 'acknowledged']);
        $I->sendPost("/v1/grievances/$id/transition", ['to' => 'under_investigation', 'note' => 'Checked the wage register']);
        $I->sendPost("/v1/grievances/$id/transition", ['to' => 'resolved']);
        $I->seeResponseContainsJson(['error' => ['fields' => ['note' => ['REQUIRED']]]]);
        $I->sendPost("/v1/grievances/$id/transition", ['to' => 'resolved', 'note' => 'Wages paid on 26 September; contractor warned.']);
        $I->seeResponseContainsJson(['status' => 'resolved', 'resolution_note' => 'Wages paid on 26 September; contractor warned.']);
        $I->sendPost("/v1/grievances/$id/transition", ['to' => 'acknowledged']);
        $I->seeApiError(422, 'INVALID_TRANSITION');
        $I->sendGet("/v1/grievances/$id");
        $I->assertSame(['submit', 'acknowledge', 'investigate', 'resolve'], $I->grabDataFromResponseByJsonPath('$.timeline[*].action'));

        // Assign: only someone who may see it.
        $I->sendGet("/v1/grievances/$id/assignees");
        $I->assertContains(Auth::user(self::GEVRA_HEAD)->id, $I->grabDataFromResponseByJsonPath('$[*].id'));
        $I->sendPost("/v1/grievances/$id/assign", ['user_id' => Auth::user(Auth::MINE_HEAD_BHUBANESWARI)->id]);
        $I->seeResponseContainsJson(['error' => ['fields' => ['user_id' => ['INVALID_VALUE']]]]);

        // Reopening starts a new SLA period.
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendPost("/v1/grievances/$id/transition", ['to' => 'reopened', 'note' => 'Complainant says two workers are still unpaid.']);
        $I->seeResponseContainsJson(['status' => 'reopened', 'escalation_level' => 0]);
        $I->assertGreaterThan(time() + 160 * 3600, strtotime($I->grabDataFromResponseByJsonPath('$.sla_due_at')[0]));

        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendPost("/v1/grievances/$id/transition", ['to' => 'resolved', 'note' => 'corporate may only read']);
        $I->seeApiError(403, 'FORBIDDEN');
    }

    public function slaBreachEscalatesAndRaisesAnAlert(ApiTester $I): void
    {
        $I->sendPost('/v1/grievances/public', $this->publicForm());
        $g = Grievance::find()->where(['ticket_no' => $I->grabDataFromResponseByJsonPath('$.ticket_no')[0]])->one();
        Yii::$app->db->createCommand()->update('grievance', ['sla_due_at' => gmdate('Y-m-d H:i:s+00', time() - 3600)], ['id' => $g->id])->execute();

        $I->amBearerOf(self::GEVRA_HEAD);
        $I->sendGet('/v1/grievances', ['escalated' => 1]);   // reading runs the SLA check
        $I->assertContains((int) $g->id, $I->grabDataFromResponseByJsonPath('$[*].id'));
        $g->refresh();
        $I->assertSame(1, (int) $g->escalation_level);
        $alert = Alert::find()->where(['code' => 'GRIEVANCE_SLA_BREACHED', 'entity_type' => 'grievance', 'entity_id' => $g->id])->one();
        $I->assertNotNull($alert);
        $I->assertEquals(['grievance_id' => (int) $g->id, 'category' => 'wages'], array_intersect_key($alert->params, ['grievance_id' => 0, 'category' => 0]));
        $escalation = GrievanceAction::find()->where(['grievance_id' => $g->id, 'action' => 'escalate'])->one();
        $I->assertNull($escalation->actor_id, 'a system action');

        // Another full SLA period later: level 2, the same alert; then nothing more.
        Yii::$app->db->createCommand()->update('grievance', ['sla_due_at' => gmdate('Y-m-d H:i:s+00', time() - 200 * 3600)], ['id' => $g->id])->execute();
        // (Seeded grievances already past their second threshold escalate in the same pass.)
        $I->assertGreaterThanOrEqual(1, GrievanceService::escalateDue()['escalated']);
        $I->assertSame(['breached' => 0, 'escalated' => 0], GrievanceService::escalateDue(), 'idempotent');
        $I->assertSame(2, (int) Grievance::findOne($g->id)->escalation_level);
        $I->assertSame(2, (int) Alert::findOne($alert->id)->escalation_level);
        $I->assertSame(1, (int) Alert::find()->where(['code' => 'GRIEVANCE_SLA_BREACHED', 'entity_id' => $g->id])->count());
    }

    public function analyticsPerRole(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/grievances/stats');
        $I->seeResponseCodeIs(200);
        $I->assertSame((int) Grievance::find()->count(), $I->grabDataFromResponseByJsonPath('$.totals.total')[0]);
        $I->assertGreaterThan(0, $I->grabDataFromResponseByJsonPath('$.totals.avg_resolution_hours')[0]);
        $I->assertCount(7, $I->grabDataFromResponseByJsonPath('$.by_category[*]'));
        $I->assertSame('OD-SUN-07', $I->grabDataFromResponseByJsonPath('$.by_mine[0].code')[0], 'the S6 cluster leads the mine table');

        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendGet('/v1/grievances/stats');
        $secl = array_map('intval', Yii::$app->db->createCommand("SELECT m.id FROM mine m JOIN subsidiary s ON s.id = m.subsidiary_id WHERE s.code = 'SECL'")->queryColumn());
        foreach ($I->grabDataFromResponseByJsonPath('$.by_mine[*].mine_id') as $mineId) {
            $I->assertContains($mineId, $secl);
        }
        $I->assertSame((int) Grievance::find()->where(['mine_id' => $secl])->count(), $I->grabDataFromResponseByJsonPath('$.totals.total')[0]);

        $I->amBearerOf(self::GEVRA_HEAD);
        $I->sendGet('/v1/grievances/stats');
        $I->seeApiError(403, 'FORBIDDEN');
    }
}
