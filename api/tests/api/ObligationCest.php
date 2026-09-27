<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\Alert;
use app\models\ObligationTask;
use app\models\StatusHistory;
use app\services\ObligationService;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;
use Yii;

/**
 * The statutory obligation register and the map (Phase 5B): evidence from the mine head, review
 * by government or inspector (accept, or reject with a reason), reminders, overdue and escalation
 * as {code, params} alerts, citations on every obligation, statutory compliance per mine and
 * company, scoping, the offline map's outlines, and - untouched - the compliance score.
 */
class ObligationCest
{
    private const HEAD = Auth::MINE_HEAD_BHUBANESWARI;

    private function openTask(string $status = 'open'): ObligationTask
    {
        $task = ObligationTask::find()->where(['mine_id' => Auth::user(self::HEAD)->mine_id, 'status' => $status])->orderBy('due_at')->one();
        if ($task === null) {
            throw new \RuntimeException("no $status task at the test mine");
        }
        return $task;
    }

    public function mineHeadSubmitsEvidence(ApiTester $I): void
    {
        $task = $this->openTask();
        $I->amBearerOf(self::HEAD);
        $I->sendPost("/v1/obligation-tasks/{$task->id}/submissions", ['note' => 'no file']);
        $I->seeResponseContainsJson(['error' => ['fields' => ['file' => ['REQUIRED']]]]);
        \yii\web\UploadedFile::reset();
        $I->sendPost("/v1/obligation-tasks/{$task->id}/submissions", ['note' => 'Minutes of the meeting attached.'], ['file' => codecept_data_dir('pixel.png')]);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['status' => 'submitted', 'latest_submission' => ['status' => 'pending', 'note' => 'Minutes of the meeting attached.']]);
        $I->assertStringStartsWith('/v1/files/', $I->grabDataFromResponseByJsonPath('$.latest_submission.file_url')[0]);
        $I->seeResponseJsonMatchesJsonPath('$.obligation.citation.quote');

        // Another mine's task does not exist for this account; government does not submit.
        $other = ObligationTask::find()->where(['<>', 'mine_id', Auth::user(self::HEAD)->mine_id])->andWhere(['status' => 'open'])->one();
        \yii\web\UploadedFile::reset();
        $I->sendPost("/v1/obligation-tasks/{$other->id}/submissions", ['note' => 'x'], ['file' => codecept_data_dir('pixel.png')]);
        $I->seeApiError(404, 'NOT_FOUND');
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendPost("/v1/obligation-tasks/{$other->id}/submissions", ['note' => 'x']);
        $I->seeApiError(403, 'FORBIDDEN');
    }

    public function governmentRejectsWithAReasonAndInspectorAccepts(ApiTester $I): void
    {
        $task = $this->openTask('overdue');
        ObligationService::check();   // the overdue task has its alert
        $I->assertNotNull(Alert::find()->where(['code' => 'OBLIGATION_OVERDUE', 'entity_id' => $task->id])->andWhere(['<>', 'status', 'resolved'])->one());
        $I->amBearerOf(self::HEAD);
        \yii\web\UploadedFile::reset();
        $I->sendPost("/v1/obligation-tasks/{$task->id}/submissions", ['note' => 'Late, attached now.'], ['file' => codecept_data_dir('pixel.png')]);
        $submissionId = $I->grabDataFromResponseByJsonPath('$.latest_submission.id')[0];

        $I->sendPost("/v1/obligation-submissions/$submissionId/review", ['decision' => 'accept']);
        $I->seeApiError(403, 'FORBIDDEN');
        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendPost("/v1/obligation-submissions/$submissionId/review", ['decision' => 'accept']);
        $I->seeApiError(403, 'FORBIDDEN');   // corporate reads the register but does not review

        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendPost("/v1/obligation-submissions/$submissionId/review", ['decision' => 'reject']);
        $I->seeResponseContainsJson(['error' => ['fields' => ['note' => ['REASON_REQUIRED']]]]);
        $I->sendPost("/v1/obligation-submissions/$submissionId/review", ['decision' => 'reject', 'note' => 'The report is unsigned; upload the signed copy.']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'rejected', 'latest_submission' => ['status' => 'rejected', 'review_note' => 'The report is unsigned; upload the signed copy.']]);
        $I->sendPost("/v1/obligation-submissions/$submissionId/review", ['decision' => 'accept']);
        $I->seeApiError(422, 'ALREADY_REVIEWED');

        $I->amBearerOf(self::HEAD);
        \yii\web\UploadedFile::reset();
        $I->sendPost("/v1/obligation-tasks/{$task->id}/submissions", ['note' => 'Signed copy.'], ['file' => codecept_data_dir('pixel.png')]);
        $again = $I->grabDataFromResponseByJsonPath('$.latest_submission.id')[0];
        $I->amBearerOf(Auth::INSPECTOR);
        $I->sendPost("/v1/obligation-submissions/$again/review", ['decision' => 'accept']);
        $I->seeResponseContainsJson(['status' => 'accepted']);
        $I->assertNotNull($I->grabDataFromResponseByJsonPath('$.accepted_at')[0]);
        $I->assertCount(2, $I->grabDataFromResponseByJsonPath('$.submissions[*]'));
        $I->assertNull(Alert::find()->where(['code' => 'OBLIGATION_OVERDUE', 'entity_id' => $task->id])->andWhere(['<>', 'status', 'resolved'])->one(),
            'accepted evidence resolves the overdue alert');
    }

    public function governmentWaivesWithAReason(ApiTester $I): void
    {
        $task = $this->openTask('overdue');
        ObligationService::check();   // the overdue task has its alert
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/obligations/summary');
        $dueBefore = $I->grabDataFromResponseByJsonPath('$.totals.due')[0];

        foreach ([self::HEAD, Auth::INSPECTOR, Auth::CORPORATE_SECL] as $who) {
            $I->amBearerOf($who);
            $I->sendPost("/v1/obligation-tasks/{$task->id}/waive", ['reason' => 'Mine closed for the period.']);
            $I->seeApiError(403, 'FORBIDDEN');
        }
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendPost("/v1/obligation-tasks/{$task->id}/waive", []);
        $I->seeResponseContainsJson(['error' => ['fields' => ['reason' => ['REASON_REQUIRED']]]]);
        $I->sendPost("/v1/obligation-tasks/{$task->id}/waive", ['reason' => 'Mine closed for the whole period by a DGMS prohibition order.']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'waived']);
        $I->seeResponseContainsJson(['history' => [['to_status' => 'waived', 'context' => ['reason' => 'Mine closed for the whole period by a DGMS prohibition order.']]]]);
        $I->assertNull(Alert::find()->where(['code' => 'OBLIGATION_OVERDUE', 'entity_id' => $task->id])->andWhere(['<>', 'status', 'resolved'])->one(),
            'waiving resolves the overdue alert');
        if (strtotime((string) $task->due_at) >= time() - ObligationService::WINDOW_DAYS * 86400) {
            $I->sendGet('/v1/obligations/summary');
            $I->assertSame($dueBefore - 1, $I->grabDataFromResponseByJsonPath('$.totals.due')[0], 'a waived task leaves statutory compliance');
        }
        $I->sendPost("/v1/obligation-tasks/{$task->id}/waive", ['reason' => 'Again, to be sure.']);
        $I->seeApiError(422, 'INVALID_TRANSITION');
        $I->assertSame(0, ObligationService::check()['overdue'], 'a waived task is not made overdue again');
    }

    public function overdueEscalatesAndRemindersComeFirst(ApiTester $I): void
    {
        $task = $this->openTask();
        $db = Yii::$app->db;
        // Due in a day: a reminder for the mine, once.
        $db->createCommand()->update('obligation_task', ['due_at' => gmdate('Y-m-d H:i:s+00', time() + 86400)], ['id' => $task->id])->execute();
        $I->assertGreaterThanOrEqual(1, ObligationService::check()['reminders']);
        // One reminder per mine and due time; the mine may have others due within the window too.
        $reminders = array_filter(Alert::find()->where(['code' => 'OBLIGATION_DUE_SOON', 'mine_id' => $task->mine_id])->all(),
            fn(Alert $a) => in_array((int) $task->id, $a->params['task_ids'], true));
        $I->assertCount(1, $reminders);
        $reminder = reset($reminders);
        $I->assertContains($task->obligation->code, $reminder->params['obligations']);
        $I->assertSame(0, ObligationService::check()['reminders'], 'idempotent');

        // Past due: overdue, OBLIGATION_OVERDUE level 1 with the citation in its params.
        $db->createCommand()->update('obligation_task', ['due_at' => gmdate('Y-m-d H:i:s+00', time() - 3600)], ['id' => $task->id])->execute();
        ObligationService::check();
        $task->refresh();
        $I->assertSame(['overdue', 1], [$task->status, (int) $task->escalation_level]);
        $alert = Alert::find()->where(['code' => 'OBLIGATION_OVERDUE', 'entity_type' => 'obligation_task', 'entity_id' => $task->id])->one();
        $I->assertSame(1, (int) $alert->escalation_level);
        $I->assertEquals(['task_id' => (int) $task->id, 'obligation' => $task->obligation->code, 'period' => $task->period,
            'instrument' => $task->obligation->instrument, 'clause' => $task->obligation->clause],
            array_intersect_key($alert->params, array_flip(['task_id', 'obligation', 'period', 'instrument', 'clause'])));
        $I->assertNull(StatusHistory::find()->where(['entity' => 'obligation_task', 'entity_id' => $task->id])->orderBy(['id' => SORT_DESC])->one()->user_id, 'a system action');

        // A week later still nothing: escalated, the same alert at level 2; then nothing more.
        $db->createCommand()->update('obligation_task', ['due_at' => gmdate('Y-m-d H:i:s+00', time() - 200 * 3600)], ['id' => $task->id])->execute();
        ObligationService::check();
        $task->refresh();
        $I->assertSame(['escalated', 2], [$task->status, (int) $task->escalation_level]);
        $I->assertSame(2, (int) Alert::findOne($alert->id)->escalation_level);
        $I->assertSame(['created' => 0, 'reminders' => 0, 'overdue' => 0, 'escalated' => 0], ObligationService::check());
        $I->assertSame(1, (int) Alert::find()->where(['code' => 'OBLIGATION_OVERDUE', 'entity_id' => $task->id])->count());

        // The mine head sees it with its alert.
        $I->amBearerOf(self::HEAD);
        $I->sendGet('/v1/alerts', ['per_page' => 200]);
        $I->assertContains((int) $alert->id, $I->grabDataFromResponseByJsonPath('$[*].id'));
    }

    public function registerViewsPerRoleWithCitations(ApiTester $I): void
    {
        $I->amBearerOf(self::HEAD);
        $I->sendGet('/v1/views/obligations');
        $I->seeResponseCodeIs(200);
        $I->assertSame(['summary', 'due_soon', 'overdue', 'submitted', 'open', 'accepted'], array_keys(json_decode($I->grabResponse(), true)));
        $mineId = Auth::user(self::HEAD)->mine_id;
        foreach ($I->grabDataFromResponseByJsonPath('$..mine_id') as $m) {
            $I->assertSame($mineId, $m);
        }
        $citations = $I->grabDataFromResponseByJsonPath('$.open[*].obligation.citation');
        $I->assertNotEmpty($citations);
        foreach ($citations as $c) {
            $I->assertNotEmpty($c['instrument']);
            $I->assertNotEmpty($c['clause']);
            $I->assertNotEmpty($c['quote']);
        }
        $I->sendGet('/v1/obligations/summary');
        $I->seeApiError(403, 'FORBIDDEN');

        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/views/obligations');
        $I->seeResponseCodeIs(200);
        $I->assertCount(74, $I->grabDataFromResponseByJsonPath('$.summary.by_mine[*]'));
        $I->assertGreaterThanOrEqual(9, count($I->grabDataFromResponseByJsonPath('$.summary.by_company[*]')));
        $pct = $I->grabDataFromResponseByJsonPath('$.summary.totals.compliance_pct')[0];
        $I->assertGreaterThan(50, $pct);
        $I->assertLessThan(100, $pct);
        $I->seeResponseJsonMatchesJsonPath('$.summary.most_overdue[0].obligation.citation.quote');

        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendGet('/v1/obligations/summary');
        $I->assertSame(['SECL'], $I->grabDataFromResponseByJsonPath('$.by_company[*].company'));
        $I->assertCount(17, $I->grabDataFromResponseByJsonPath('$.by_mine[*]'));

        // The catalogue: every obligation cited; RPT-08 unverified and never on the register.
        $I->sendGet('/v1/obligations');
        $I->assertCount(40, $I->grabDataFromResponseByJsonPath('$[*]'));
        $I->seeResponseContainsJson([['code' => 'RPT-08', 'verified' => false, 'generates_tasks' => false]]);
        $I->assertSame(0, (int) ObligationTask::find()->innerJoinWith('obligation')->where(['obligation.code' => 'RPT-08'])->count());
    }

    public function mapIsScopedAndItsOutlinesAreLocal(ApiTester $I): void
    {
        foreach ([[Auth::GOVERNMENT, 74], [Auth::CORPORATE_SECL, 17], [self::HEAD, 1]] as [$who, $n]) {
            $I->amBearerOf($who);
            $I->sendGet('/v1/views/map');
            $I->seeResponseCodeIs(200);
            $I->assertCount($n, $I->grabDataFromResponseByJsonPath('$.mines.features[*]'), $who);
            $I->seeResponseJsonMatchesJsonPath('$.mines.features[0].properties.location_quality');
        }
        $I->amBearerOf(self::HEAD);
        $I->sendGet('/v1/views/map');
        $feature = $I->grabDataFromResponseByJsonPath('$.mines.features[0]')[0];
        $I->sendGet("/v1/mines/{$feature['properties']['id']}");
        $I->assertSame($I->grabDataFromResponseByJsonPath('$.compliance.risk_level')[0], $feature['properties']['risk_level'], 'band on the map = compliance band');

        // Every mine: the band and the open-alert count on the map are the mine list's.
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/mines?per_page=200');
        $list = [];
        foreach (json_decode($I->grabResponse(), true) as $m) {
            $list[$m['code']] = [$m['compliance']['risk_level'], $m['open_alerts']];
        }
        $I->sendGet('/v1/views/map');
        foreach ($I->grabDataFromResponseByJsonPath('$.mines.features[*].properties') as $p) {
            $I->assertSame($list[$p['code']], [$p['risk_level'], $p['open_alerts']], $p['code']);
        }

        $I->sendGet('/v1/geo/states');
        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'application/geo+json; charset=UTF-8');
        $I->assertCount(36, $I->grabDataFromResponseByJsonPath('$.features[*]'));
        $etag = $I->grabHttpHeader('ETag');
        $I->haveHttpHeader('If-None-Match', $etag);
        $I->sendGet('/v1/geo/states');
        $I->seeResponseCodeIs(304);
        $I->deleteHeader('If-None-Match');

        // District outlines: those holding a mine in scope, listing only mines in scope.
        $scopeCodes = function () use ($I): array {
            $I->sendGet('/v1/mines?per_page=200');
            return array_column(json_decode($I->grabResponse(), true), 'code');
        };
        foreach ([[Auth::GOVERNMENT, 28], [Auth::CORPORATE_SECL, null], [self::HEAD, 1]] as [$who, $n]) {
            $I->amBearerOf($who);
            $codes = $scopeCodes();
            $I->sendGet('/v1/geo/districts');
            $I->seeResponseCodeIs(200);
            $features = $I->grabDataFromResponseByJsonPath('$.features[*].properties');
            if ($n !== null) {
                $I->assertCount($n, $features, $who);
            }
            $listed = array_merge(...array_column($features, 'mines'));
            sort($listed);
            sort($codes);
            $I->assertSame($codes, $listed, "$who: every mine in scope has its district, and no other mine is named");
        }
        $I->sendGet('/v1/geo/rivers');
        $I->seeResponseCodeIs(404);
    }

    public function statutoryComplianceLeavesTheScoreAlone(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/dashboard');
        $before = $I->grabDataFromResponseByJsonPath('$.stats')[0];
        // Every open task of the five demo mines past due, escalated, with alerts - the compliance score must not move.
        Yii::$app->db->createCommand()->update('obligation_task', ['due_at' => gmdate('Y-m-d H:i:s+00', time() - 400 * 3600)],
            ['status' => ['open', 'rejected'], 'mine_id' => [1, 2, 3, 4, 5]])->execute();
        ObligationService::check();
        $I->sendGet('/v1/dashboard');
        $after = $I->grabDataFromResponseByJsonPath('$.stats')[0];
        $I->assertSame($before['average_score'] ?? null, $after['average_score'] ?? null);
        $I->assertEquals(array_intersect_key($before, array_flip(['high_risk', 'medium_risk', 'low_risk', 'average_score'])),
            array_intersect_key($after, array_flip(['high_risk', 'medium_risk', 'low_risk', 'average_score'])));
    }
}
