<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\Mine;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/**
 * Mine scoping per role (brief rule 2) through GET /v1/mines and /v1/mines/{id}.
 * Out-of-scope records are 404, exactly like missing ones.
 */
class ScopingCest
{
    public function governmentSeesAllMines(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/mines', ['per_page' => 200]);
        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('X-Total-Count', (string) Mine::find()->count());
        $I->assertCount((int) Mine::find()->count(), $I->grabDataFromResponseByJsonPath('$[*].id'));
    }

    public function inspectorReadsLikeGovernment(ApiTester $I): void
    {
        $I->amBearerOf(Auth::INSPECTOR);
        $I->sendGet('/v1/mines');
        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('X-Total-Count', (string) Mine::find()->count());
    }

    public function corporateSeesOnlyItsSubsidiary(ApiTester $I): void
    {
        $user = Auth::user(Auth::CORPORATE_SECL);
        $own = Mine::find()->where(['subsidiary_id' => $user->subsidiary_id])->select('id')->column();
        $I->assertNotEmpty($own, 'the seeded preset has a SECL mine');

        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendGet('/v1/mines', ['per_page' => 200]);
        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('X-Total-Count', (string) count($own));
        $I->assertEqualsCanonicalizing(array_map('intval', $own), $I->grabDataFromResponseByJsonPath('$[*].id'));
        foreach ($I->grabDataFromResponseByJsonPath('$[*].operator') as $operator) {
            $I->assertSame('SECL', $operator);
        }
    }

    public function corporateGets404OutsideItsSubsidiary(ApiTester $I): void
    {
        $user = Auth::user(Auth::CORPORATE_SECL);
        $other = (int) Mine::find()->where(['<>', 'subsidiary_id', $user->subsidiary_id])->select('id')->scalar();
        $own = (int) Mine::find()->where(['subsidiary_id' => $user->subsidiary_id])->select('id')->scalar();

        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendGet("/v1/mines/$own");
        $I->seeResponseCodeIs(200);
        $I->sendGet("/v1/mines/$other");
        $I->seeApiError(404, 'NOT_FOUND');
    }

    public function mineHeadSeesOnlyOwnMine(ApiTester $I): void
    {
        $user = Auth::user(Auth::MINE_HEAD_MOONIDIH);
        $I->amBearerOf(Auth::MINE_HEAD_MOONIDIH);
        $I->sendGet('/v1/mines');
        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('X-Total-Count', '1');
        $I->seeResponseContainsJson([['id' => $user->mine_id, 'name' => 'Moonidih Coal Mine']]);

        $I->sendGet("/v1/mines/{$user->mine_id}");
        $I->seeResponseCodeIs(200);
        $I->seeResponseMatchesJsonType(['location' => ['type' => 'string', 'coordinates' => 'array']]);
    }

    public function mineHeadGets404ForAnotherMine(ApiTester $I): void
    {
        $user = Auth::user(Auth::MINE_HEAD_MOONIDIH);
        $other = (int) Mine::find()->where(['<>', 'id', $user->mine_id])->select('id')->scalar();
        $I->amBearerOf(Auth::MINE_HEAD_MOONIDIH);
        $I->sendGet("/v1/mines/$other");
        $I->seeApiError(404, 'NOT_FOUND');
    }

    /** Brief Phase 8: another mine's contractors, production, grievances and alerts are 404 for a mine head. */
    public function mineHeadGets404ForAnotherMinesRecords(ApiTester $I): void
    {
        $mineId = (int) Auth::user(Auth::MINE_HEAD_MOONIDIH)->mine_id;
        $db = \Yii::$app->db;
        $records = [
            'contractors' => $db->createCommand('SELECT contractor_id FROM contract WHERE contractor_id NOT IN
                (SELECT contractor_id FROM contract WHERE mine_id = :m) LIMIT 1', [':m' => $mineId])->queryScalar(),
            'production' => $db->createCommand('SELECT id FROM daily_production WHERE mine_id <> :m LIMIT 1', [':m' => $mineId])->queryScalar(),
            'grievances' => $db->createCommand('SELECT id FROM grievance WHERE mine_id <> :m LIMIT 1', [':m' => $mineId])->queryScalar(),
            'alerts' => $db->createCommand('SELECT id FROM alert WHERE mine_id <> :m LIMIT 1', [':m' => $mineId])->queryScalar(),
        ];
        $I->amBearerOf(Auth::MINE_HEAD_MOONIDIH);
        foreach ($records as $path => $id) {
            $I->assertNotFalse($id, "a $path record at another mine exists in the seed");
            $I->sendGet("/v1/$path/$id");
            $I->seeApiError(404, 'NOT_FOUND');
        }
    }

    public function outOfScopeLooksExactlyLikeMissing(ApiTester $I): void
    {
        $user = Auth::user(Auth::MINE_HEAD_MOONIDIH);
        $other = (int) Mine::find()->where(['<>', 'id', $user->mine_id])->select('id')->scalar();
        $I->amBearerOf(Auth::MINE_HEAD_MOONIDIH);

        $I->sendGet("/v1/mines/$other");
        $hidden = json_decode($I->grabResponse(), true);
        $I->sendGet('/v1/mines/999999');
        $missing = json_decode($I->grabResponse(), true);
        unset($hidden['error']['debug'], $missing['error']['debug']);
        $I->assertSame($missing, $hidden);
    }

    public function filterAndSortFollowTheConventions(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/mines', ['filter' => ['type' => 'underground'], 'sort' => '-name', 'per_page' => 200]);
        $I->seeResponseCodeIs(200);
        $expected = Mine::find()->where(['type' => 'underground'])->orderBy(['name' => SORT_DESC])->select('name')->column();
        $I->assertSame($expected, $I->grabDataFromResponseByJsonPath('$[*].name'));

        $I->sendGet('/v1/mines', ['per_page' => 2, 'page' => 2]);
        $I->seeHttpHeader('X-Page', '2');
        $I->seeHttpHeader('X-Per-Page', '2');

        $I->sendGet('/v1/mines', ['filter' => ['password_hash' => 'x']]);
        $I->seeApiError(422, 'VALIDATION_FAILED');
        $I->sendGet('/v1/mines', ['sort' => 'location']);
        $I->seeApiError(422, 'VALIDATION_FAILED');
    }

    public function minesNeedAToken(ApiTester $I): void
    {
        $I->sendGet('/v1/mines');
        $I->seeApiError(401, 'UNAUTHENTICATED');
    }
}
