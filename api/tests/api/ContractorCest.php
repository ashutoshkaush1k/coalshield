<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\Alert;
use app\models\Contract;
use app\services\ContractorAlertService;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;
use Yii;

/**
 * Contractor management (brief Phase 3): scoping through contracts, the S4 scenario, the summary
 * for multi-mine roles, mine-head management, documents, status changes and generated alerts.
 */
class ContractorCest
{
    private const S4_CONTRACTOR = 32;
    private const S4_MINE_HEAD = 'head.mp-sin-42@coalmine.in';

    public function s4IsFlaggedAndFirstForItsMineHead(ApiTester $I): void
    {
        $I->amBearerOf(self::S4_MINE_HEAD);
        $I->sendGet('/v1/contractors');
        $I->seeResponseCodeIs(200);
        $I->assertSame(self::S4_CONTRACTOR, $I->grabDataFromResponseByJsonPath('$[0].id')[0]);
        $I->seeResponseContainsJson([['id' => self::S4_CONTRACTOR, 'compliance' => ['band' => 'flagged']]]);
        $codes = array_column($I->grabDataFromResponseByJsonPath('$[0].compliance.reasons')[0], 'code');
        $I->assertContains('DOCS_MISSING', $codes);
        $I->assertContains('VIOLATIONS_PER_WORKER', $codes);
        $types = $I->grabDataFromResponseByJsonPath('$[0].compliance.missing_documents[*].doc_type');
        $I->assertContains('wage_register', $types);
        $I->assertContains('epf_challan', $types);
        // Every contractor listed works at this mine.
        $mineId = Auth::user(self::S4_MINE_HEAD)->mine_id;
        foreach ($I->grabDataFromResponseByJsonPath('$[*].id') as $id) {
            $I->assertTrue(Contract::find()->where(['contractor_id' => $id, 'mine_id' => $mineId])->exists());
        }
    }

    public function s4DetailCarriesItsAlertsAndViolations(ApiTester $I): void
    {
        $I->amBearerOf(self::S4_MINE_HEAD);
        $I->sendGet('/v1/contractors/' . self::S4_CONTRACTOR);
        $I->seeResponseCodeIs(200);
        $I->assertGreaterThan(50, count($I->grabDataFromResponseByJsonPath('$.violations[*]')));
        $I->assertContains('CONTRACTOR_DOC_MISSING', $I->grabDataFromResponseByJsonPath('$.alerts[*].code'));
        // S4's five contracts span several mines: the mine head sees only the one at its mine,
        // government sees all five.
        $mineId = Auth::user(self::S4_MINE_HEAD)->mine_id;
        foreach ($I->grabDataFromResponseByJsonPath('$.contracts[*].mine_id') as $contractMine) {
            $I->assertSame($mineId, $contractMine);
        }
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/contractors/' . self::S4_CONTRACTOR);
        $I->assertCount(5, $I->grabDataFromResponseByJsonPath('$.contracts[*]'));
        $I->seeResponseContainsJson(['compliance' => ['band' => 'flagged']]);
    }

    public function s4LeadsTheNationalRanking(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/contractors', ['per_page' => 5]);
        $I->assertSame(self::S4_CONTRACTOR, $I->grabDataFromResponseByJsonPath('$[0].id')[0]);
        $I->sendGet('/v1/contractors/summary');
        $I->seeResponseCodeIs(200);
        $I->assertSame(self::S4_CONTRACTOR, $I->grabDataFromResponseByJsonPath('$.flagged_contractors[0].contractor_id')[0]);
        $I->seeResponseContainsJson(['flagged_contractors' => [['contractor_id' => self::S4_CONTRACTOR, 'mines' => [['code' => 'MP-SIN-42']]]]]);
    }

    public function summaryIsForMultiMineRolesAndScoped(ApiTester $I): void
    {
        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendGet('/v1/contractors/summary');
        $I->seeResponseCodeIs(200);
        $secl = \app\models\Mine::find()->where(['subsidiary_id' => Auth::user(Auth::CORPORATE_SECL)->subsidiary_id])->select('id')->column();
        foreach ($I->grabDataFromResponseByJsonPath('$.mines[*].mine_id') as $mineId) {
            $I->assertContains($mineId, array_map('intval', $secl));
        }
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet('/v1/contractors/summary');
        $I->seeApiError(403, 'FORBIDDEN');
    }

    public function otherMinesContractorIs404(ApiTester $I): void
    {
        $mineId = Auth::user(Auth::MINE_HEAD_BHUBANESWARI)->mine_id;
        $foreign = (int) Yii::$app->db->createCommand('SELECT id FROM contractor k WHERE NOT EXISTS
            (SELECT 1 FROM contract c WHERE c.contractor_id = k.id AND c.mine_id = :m) ORDER BY id LIMIT 1', [':m' => $mineId])->queryScalar();
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendGet("/v1/contractors/$foreign");
        $I->seeApiError(404, 'NOT_FOUND');
        $contract = Contract::find()->where(['<>', 'mine_id', $mineId])->one();
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost("/v1/contracts/{$contract->id}/workers", ['name' => 'X Y', 'worker_code' => 'CWX0001', 'vt_cert_valid_to' => '2030-01-01', 'medical_exam_date' => '2026-09-01']);
        $I->seeApiError(404, 'NOT_FOUND');
    }

    public function mineHeadManagesContractorContractWorkersAndDocuments(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->amBearerOf(Auth::MINE_HEAD_BHUBANESWARI);
        $I->sendPost('/v1/contractors', ['name' => 'Test Haulage Co', 'registration_no' => 'REG-TEST-0001', 'labour_licence_no' => 'LL/TEST/0001',
            'licence_valid_to' => gmdate('Y-m-d', strtotime('+10 days')), 'epf_code' => 'EPF-TEST', 'esi_code' => 'ESI-TEST', 'contact' => '+91-00000-00000',
            'contract' => ['work_type' => 'transport', 'work_order_no' => 'WO/TEST/0001', 'value' => 1500000, 'start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'max_workers' => 1]]);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['name' => 'Test Haulage Co', 'status' => 'active', 'contracts' => [['mine_id' => 5, 'work_type' => 'transport']],
            'compliance' => ['licence' => ['state' => 'expiring', 'obligation' => 'LAB-02']]]);
        $contractorId = $I->grabDataFromResponseByJsonPath('$.id')[0];
        $contractId = $I->grabDataFromResponseByJsonPath('$.contracts[0].id')[0];

        foreach (['CW-T-01', 'CW-T-02'] as $code) {
            $I->sendPost("/v1/contracts/$contractId/workers", ['name' => "Worker $code", 'worker_code' => $code,
                'vt_cert_valid_to' => '2020-01-01', 'medical_exam_date' => gmdate('Y-m-d')]);
            $I->seeResponseCodeIs(201);
        }
        $I->seeResponseContainsJson(['vt_expired' => true, 'medical_overdue' => false]);

        // Generated, not loaded: licence expiring, VT expired, worker cap exceeded, missing documents.
        $raised = ContractorAlertService::run();
        foreach (['CONTRACTOR_LICENCE_EXPIRING', 'WORKER_VT_EXPIRED', 'CONTRACT_WORKER_CAP_EXCEEDED', 'CONTRACTOR_DOC_MISSING'] as $code) {
            $I->assertTrue(Alert::find()->where(['code' => $code, 'entity_type' => 'contract', 'entity_id' => $contractId])->exists(), $code);
        }
        $I->assertArrayHasKey('CONTRACT_WORKER_CAP_EXCEEDED', $raised);
        $I->assertSame([], ContractorAlertService::run(), 'idempotent');
        $cap = Alert::findOne(['code' => 'CONTRACT_WORKER_CAP_EXCEEDED', 'entity_id' => $contractId]);
        $I->assertEquals(['contract_id' => $contractId, 'active_workers' => 2, 'max_workers' => 1], $cap->params);
        $I->assertSame(5, (int) $cap->mine_id);

        // Upload a monthly document (multipart) and verify it.
        $I->deleteHeader('Content-Type');
        $I->sendPost("/v1/contracts/$contractId/documents", ['doc_type' => 'wage_register', 'period' => '2026-08'], ['file' => codecept_data_dir('pixel.png')]);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['doc_type' => 'wage_register', 'period' => '2026-08', 'verified' => false, 'mime' => 'image/png']);
        $docId = $I->grabDataFromResponseByJsonPath('$.id')[0];
        $I->assertStringStartsWith('/v1/files/', $I->grabDataFromResponseByJsonPath('$.url')[0]);
        $I->sendPost("/v1/contracts/$contractId/documents", ['doc_type' => 'wage_register', 'period' => '2026-08'], ['file' => codecept_data_dir('pixel.png')]);
        $I->seeResponseContainsJson(['error' => ['fields' => ['period' => ['ALREADY_UPLOADED']]]]);
        $I->sendPost("/v1/contractor-docs/$docId/verify");
        $I->seeResponseContainsJson(['verified' => true, 'verified_by' => Auth::user(Auth::MINE_HEAD_BHUBANESWARI)->id]);

        // Status change needs a reason and goes through the workflow.
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost("/v1/contractors/$contractorId/status", ['status' => 'suspended']);
        $I->seeResponseContainsJson(['error' => ['fields' => ['reason' => ['REQUIRED']]]]);
        $I->sendPost("/v1/contractors/$contractorId/status", ['status' => 'suspended', 'reason' => 'Two workers without VT certificates.']);
        $I->seeResponseContainsJson(['status' => 'suspended', 'compliance' => ['band' => 'flagged'], 'history' => [['from_status' => 'active', 'to_status' => 'suspended']]]);
        $I->sendPost("/v1/contractors/$contractorId/status", ['status' => 'suspended', 'reason' => 'again']);
        $I->seeApiError(422, 'INVALID_TRANSITION');

        // A contract with workers cannot be deleted.
        $I->sendDelete("/v1/contracts/$contractId");
        $I->seeApiError(422, 'IN_USE');
    }

    public function governmentReadsButCannotManage(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/contractors/' . self::S4_CONTRACTOR);
        $I->seeResponseCodeIs(200);
        $I->sendPost('/v1/contractors/' . self::S4_CONTRACTOR . '/status', ['status' => 'blacklisted', 'reason' => 'x']);
        $I->seeApiError(403, 'FORBIDDEN');
    }

    public function violationsAndActionsLinkToContractorsAtTheSameMine(ApiTester $I): void
    {
        $head = Auth::user(self::S4_MINE_HEAD);
        $violation = \app\models\Violation::find()->where(['mine_id' => $head->mine_id, 'resolved' => false])->orderBy(['contractor_id' => SORT_DESC])->one();
        $otherContractor = (int) Yii::$app->db->createCommand('SELECT contractor_id FROM contract WHERE mine_id <> :m AND contractor_id NOT IN
            (SELECT contractor_id FROM contract WHERE mine_id = :m) LIMIT 1', [':m' => $head->mine_id])->queryScalar();
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->amBearerOf(self::S4_MINE_HEAD);
        $I->sendPatch("/v1/violations/{$violation->id}/contractor", ['contractor_id' => self::S4_CONTRACTOR]);
        $I->seeResponseContainsJson(['id' => $violation->id, 'contractor_id' => self::S4_CONTRACTOR]);
        $I->sendPatch("/v1/violations/{$violation->id}/contractor", ['contractor_id' => $otherContractor]);
        $I->seeApiError(404, 'NOT_FOUND');
        $I->sendPost('/v1/corrective-actions', ['violation_id' => $violation->id, 'description' => 'Contractor to retrain the crew.',
            'due_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400), 'contractor_id' => self::S4_CONTRACTOR]);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['contractor_id' => self::S4_CONTRACTOR]);
    }
}
