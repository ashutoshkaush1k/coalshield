<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\AuditLog;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/** GET / PATCH /v1/users/me */
class UsersMeCest
{
    public function meNeedsAToken(ApiTester $I): void
    {
        $I->sendGet('/v1/users/me');
        $I->seeApiError(401, 'UNAUTHENTICATED');
    }

    public function meReturnsOwnProfile(ApiTester $I): void
    {
        $I->amBearerOf(Auth::CORPORATE_SECL);
        $I->sendGet('/v1/users/me');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['email' => Auth::CORPORATE_SECL, 'role' => 'corporate', 'mine_id' => null]);
        $I->seeResponseMatchesJsonType(['subsidiary_id' => 'integer', 'created_at' => 'string:regex(~^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$~)']);
        $I->dontSeeResponseJsonMatchesJsonPath('$.password_hash');
    }

    public function authMeIsAnAlias(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->sendGet('/v1/auth/me');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['role' => 'government']);
    }

    public function patchChangesLanguageAndIsAudited(ApiTester $I): void
    {
        $user = Auth::user(Auth::MINE_HEAD_MOONIDIH);
        $I->amBearerOf(Auth::MINE_HEAD_MOONIDIH);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPatch('/v1/users/me', ['preferred_language' => 'bn']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['preferred_language' => 'bn']);

        $I->sendGet('/v1/users/me');
        $I->seeResponseContainsJson(['preferred_language' => 'bn']);

        /** @var AuditLog $entry */
        $entry = AuditLog::find()->where(['entity' => 'user', 'entity_id' => $user->id, 'action' => 'update'])->orderBy(['id' => SORT_DESC])->one();
        $I->assertNotNull($entry);
        $I->assertSame('bn', $entry->new_values['preferred_language']);
        $I->assertSame($user->id, $entry->user_id);
        $I->assertArrayNotHasKey('password_hash', $entry->new_values);
    }

    public function patchRejectsOtherFields(ApiTester $I): void
    {
        $I->amBearerOf(Auth::MINE_HEAD_MOONIDIH);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPatch('/v1/users/me', ['preferred_language' => 'hi', 'role' => 'government', 'mine_id' => 2]);
        $I->seeApiError(422, 'VALIDATION_FAILED');
        $I->seeResponseContainsJson(['error' => ['fields' => ['role' => ['READ_ONLY'], 'mine_id' => ['READ_ONLY']]]]);

        $I->sendGet('/v1/users/me');
        $I->seeResponseContainsJson(['role' => 'mine_head', 'mine_id' => 1]);
    }

    public function patchRejectsUnknownLanguage(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPatch('/v1/users/me', ['preferred_language' => 'fr']);
        $I->seeApiError(422, 'VALIDATION_FAILED');
        $I->seeResponseContainsJson(['error' => ['fields' => ['preferred_language' => ['INVALID_VALUE']]]]);
    }

    public function patchNeedsTheField(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPatch('/v1/users/me', []);
        $I->seeApiError(422, 'VALIDATION_FAILED');
        $I->seeResponseContainsJson(['error' => ['fields' => ['preferred_language' => ['REQUIRED']]]]);
    }
}
