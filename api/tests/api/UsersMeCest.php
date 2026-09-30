<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\AuditLog;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/** GET / PATCH /v1/users/me, POST /v1/users/me/password */
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
        // The Profile page (Phase 6): company name, area, mine code.
        $I->seeResponseContainsJson(['subsidiary_code' => 'SECL', 'subsidiary_name' => 'South Eastern Coalfields Ltd', 'mine_code' => null]);
        $I->seeResponseJsonMatchesJsonPath('$.area_name');
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

    public function changePasswordThenSignInWithTheNewOne(ApiTester $I): void
    {
        $user = Auth::user(Auth::INSPECTOR);
        $I->amBearerOf(Auth::INSPECTOR);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/v1/users/me/password', ['current_password' => Auth::PASSWORD, 'new_password' => 'a-much-longer-test-passphrase']);
        $I->seeResponseCodeIs(204);

        $I->sendPost('/v1/auth/login', ['email' => Auth::INSPECTOR, 'password' => Auth::PASSWORD]);
        $I->seeApiError(401, 'INVALID_CREDENTIALS');
        $I->sendPost('/v1/auth/login', ['email' => Auth::INSPECTOR, 'password' => 'a-much-longer-test-passphrase']);
        $I->seeResponseCodeIs(200);

        /** @var AuditLog $entry */
        $entry = AuditLog::find()->where(['entity' => 'user', 'entity_id' => $user->id, 'action' => 'update'])->orderBy(['id' => SORT_DESC])->one();
        $I->assertNotNull($entry, 'the change is in the audit chain');
        $I->assertStringNotContainsString('a-much-longer-test-passphrase', json_encode([$entry->old_values, $entry->new_values]));
        $I->assertStringNotContainsString('$2y$', json_encode([$entry->old_values, $entry->new_values]), 'hash redacted');
    }

    public function changePasswordChecksTheCurrentOneAndTheLength(ApiTester $I): void
    {
        $I->amBearerOf(Auth::GOVERNMENT);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/v1/users/me/password', ['current_password' => 'not-the-password', 'new_password' => 'a-much-longer-test-passphrase']);
        $I->seeApiError(422, 'VALIDATION_FAILED');
        $I->seeResponseContainsJson(['error' => ['fields' => ['current_password' => ['WRONG_PASSWORD']]]]);

        $I->sendPost('/v1/users/me/password', ['current_password' => Auth::PASSWORD, 'new_password' => 'short-11ch!']);
        $I->seeApiError(422, 'VALIDATION_FAILED');
        $I->seeResponseContainsJson(['error' => ['params' => ['min_length' => 12], 'fields' => ['new_password' => ['TOO_SHORT']]]]);

        $I->sendPost('/v1/users/me/password', []);
        $I->seeResponseContainsJson(['error' => ['fields' => ['current_password' => ['REQUIRED'], 'new_password' => ['REQUIRED']]]]);

        $I->sendPost('/v1/auth/login', ['email' => Auth::GOVERNMENT, 'password' => Auth::PASSWORD]);
        $I->seeResponseCodeIs(200);
    }

    public function changePasswordNeedsAToken(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/v1/users/me/password', ['current_password' => Auth::PASSWORD, 'new_password' => 'a-much-longer-test-passphrase']);
        $I->seeApiError(401, 'UNAUTHENTICATED');
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
