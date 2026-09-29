<?php

declare(strict_types=1);

namespace app\tests\api;

use app\components\AccessRule;
use app\models\Contractor;
use app\models\Grievance;
use app\models\Mine;
use app\models\Obligation;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/**
 * GET /v1/search: government and corporate only; every group scoped like its own list endpoint;
 * at most 5 per group; read-only.
 */
class SearchCest
{
    public function needsAuthentication(ApiTester $I): void
    {
        $I->sendGet('/v1/search', ['q' => 'coal']);
        $I->seeResponseCodeIs(401);
    }

    public function mineHeadAndInspectorGet403(ApiTester $I): void
    {
        foreach ([Auth::MINE_HEAD_BHUBANESWARI, Auth::INSPECTOR] as $who) {
            $I->amBearerOf($who);
            $I->sendGet('/v1/search', ['q' => 'coal']);
            $I->seeApiError(403, 'FORBIDDEN');
        }
    }

    public function shortQueryReturnsEmptyGroups(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/search', ['q' => 'a']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['mines' => [], 'contractors' => [], 'grievances' => [], 'obligations' => []]);
    }

    public function governmentFindsMinesByCodeNameAndPlace(ApiTester $I): void
    {
        $mine = Mine::find()->orderBy('id')->one();
        $I->amBearerOf(Auth::GOVERNMENT);
        foreach ([$mine->code, mb_strtolower($mine->code), $mine->name, $mine->district] as $q) {
            $I->sendGet('/v1/search', ['q' => $q]);
            $I->seeResponseCodeIs(200);
            $ids = $I->grabDataFromResponseByJsonPath('$.mines[*].id');
            $I->assertLessThanOrEqual(5, count($ids));
            if ($q !== $mine->district) {
                $I->assertContains((int) $mine->id, $ids, "search '$q' finds {$mine->code}");
            }
        }
    }

    public function atMostFivePerGroup(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/search', ['q' => 'coal']);
        $I->seeResponseCodeIs(200);
        $I->assertGreaterThanOrEqual(5, (int) Mine::find()->where(['ilike', 'name', 'coal'])->count());
        $I->assertCount(5, $I->grabDataFromResponseByJsonPath('$.mines[*].id'));
    }

    public function corporateFindsOnlyItsOwnMines(ApiTester $I): void
    {
        $user = Auth::user(Auth::CORPORATE_SECL);
        $own = Mine::find()->where(['subsidiary_id' => $user->subsidiary_id])->one();
        $other = Mine::find()->where(['<>', 'subsidiary_id', $user->subsidiary_id])->one();

        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendGet('/v1/search', ['q' => $own->code]);
        $I->assertContains((int) $own->id, $I->grabDataFromResponseByJsonPath('$.mines[*].id'));
        $I->sendGet('/v1/search', ['q' => $other->code]);
        $I->assertNotContains((int) $other->id, $I->grabDataFromResponseByJsonPath('$.mines[*].id'));
        $I->sendGet('/v1/search', ['q' => 'coal']);
        foreach ($I->grabDataFromResponseByJsonPath('$.mines[*].operator') as $operator) {
            $I->assertSame('SECL', $operator);
        }

        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/search', ['q' => $other->code]);
        $I->assertContains((int) $other->id, $I->grabDataFromResponseByJsonPath('$.mines[*].id'));
    }

    public function corporateGrievancesStayWithinItsMines(ApiTester $I): void
    {
        $user = Auth::user(Auth::CORPORATE_SECL);
        $ownMines = array_map('intval', Mine::find()->where(['subsidiary_id' => $user->subsidiary_id])->select('id')->column());
        $outside = Grievance::find()->where(['not in', 'mine_id', $ownMines])->one();
        $I->assertNotNull($outside, 'the seed has grievances at other companies');

        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendGet('/v1/search', ['q' => $outside->ticket_no]);
        $I->seeResponseCodeIs(200);
        $I->assertSame([], $I->grabDataFromResponseByJsonPath('$.grievances[*].id'));
        $I->sendGet('/v1/search', ['q' => 'GRV']);
        foreach ($I->grabDataFromResponseByJsonPath('$.grievances[*].mine_id') as $mineId) {
            $I->assertContains((int) $mineId, $ownMines);
        }

        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/search', ['q' => $outside->ticket_no]);
        $I->assertContains((int) $outside->id, $I->grabDataFromResponseByJsonPath('$.grievances[*].id'));
    }

    public function sensitiveGrievancesOnlyForRolesThatSeeThem(ApiTester $I): void
    {
        $sensitive = Grievance::find()->where(new \yii\db\Expression(AccessRule::sensitiveGrievanceSql('{{%grievance}}')))->one();
        $I->assertNotNull($sensitive, 'the seed has a sensitive grievance');
        // The regulator may see it, so search finds it.
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/search', ['q' => $sensitive->ticket_no]);
        $I->assertContains((int) $sensitive->id, $I->grabDataFromResponseByJsonPath('$.grievances[*].id'));
        // The one role that may not see sensitive grievances cannot search at all.
        $I->amBearerOf(Auth::MINE_HEAD_MOONIDIH);
        $I->sendGet('/v1/search', ['q' => $sensitive->ticket_no]);
        $I->seeApiError(403, 'FORBIDDEN');
    }

    public function findsContractorsByRegistrationAndObligationsByCode(ApiTester $I): void
    {
        $contractor = Contractor::find()->orderBy('id')->one();
        $obligation = Obligation::find()->orderBy('id')->one();
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/search', ['q' => $contractor->registration_no]);
        $I->assertContains((int) $contractor->id, $I->grabDataFromResponseByJsonPath('$.contractors[*].id'));
        $I->sendGet('/v1/search', ['q' => $obligation->code]);
        $I->assertContains($obligation->code, $I->grabDataFromResponseByJsonPath('$.obligations[*].code'));
    }

    public function isReadOnly(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendPost('/v1/search', ['q' => 'coal']);
        $I->seeResponseCodeIsClientError();
    }
}
