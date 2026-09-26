<?php

declare(strict_types=1);

namespace app\tests\api;

use app\models\User;
use app\tests\Support\ApiTester;
use app\tests\Support\Helper\Auth;

/** POST /v1/auth/login */
class AuthCest
{
    public function _before(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    public function loginReturnsTokenAndUser(ApiTester $I): void
    {
        $I->sendPost('/v1/auth/login', ['email' => Auth::GOVERNMENT, 'password' => Auth::PASSWORD]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseMatchesJsonType([
            'access_token' => 'string',
            'token_type' => 'string',
            'expires_in' => 'integer',
            'user' => ['id' => 'integer', 'email' => 'string', 'role' => 'string', 'preferred_language' => 'string'],
        ]);
        $I->seeResponseContainsJson(['token_type' => 'bearer', 'user' => ['email' => Auth::GOVERNMENT, 'role' => 'government']]);
        $I->dontSeeResponseJsonMatchesJsonPath('$.user.password_hash');
        $I->dontSeeResponseJsonMatchesJsonPath('$.user.password');
    }

    public function emailIsCaseInsensitive(ApiTester $I): void
    {
        $I->sendPost('/v1/auth/login', ['email' => '  CORPORATE.SECL@coalmine.in ', 'password' => Auth::PASSWORD]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['user' => ['role' => 'corporate']]);
    }

    public function tokenWorksForMe(ApiTester $I): void
    {
        $I->sendPost('/v1/auth/login', ['email' => Auth::MINE_HEAD_MOONIDIH, 'password' => Auth::PASSWORD]);
        $token = $I->grabDataFromResponseByJsonPath('$.access_token')[0];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $token);
        $I->sendGet('/v1/users/me');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['email' => Auth::MINE_HEAD_MOONIDIH, 'role' => 'mine_head']);
    }

    public function wrongPasswordIs401(ApiTester $I): void
    {
        $I->sendPost('/v1/auth/login', ['email' => Auth::GOVERNMENT, 'password' => 'wrong']);
        $I->seeApiError(401, 'INVALID_CREDENTIALS');
    }

    public function unknownEmailIsTheSame401(ApiTester $I): void
    {
        $I->sendPost('/v1/auth/login', ['email' => 'nobody@example.org', 'password' => Auth::PASSWORD]);
        $I->seeApiError(401, 'INVALID_CREDENTIALS');
    }

    public function inactiveUserCannotLogIn(ApiTester $I): void
    {
        $user = Auth::user(Auth::INSPECTOR);
        $user->status = User::STATUS_INACTIVE;
        $I->assertTrue($user->save());
        $I->sendPost('/v1/auth/login', ['email' => Auth::INSPECTOR, 'password' => Auth::PASSWORD]);
        $I->seeApiError(401, 'INVALID_CREDENTIALS');
    }

    public function missingFieldsAre422(ApiTester $I): void
    {
        $I->sendPost('/v1/auth/login', ['email' => '']);
        $I->seeApiError(422, 'VALIDATION_FAILED');
        $I->seeResponseContainsJson(['error' => ['fields' => ['email' => ['REQUIRED'], 'password' => ['REQUIRED']]]]);
    }

    public function loginOnlyAcceptsPost(ApiTester $I): void
    {
        $I->sendGet('/v1/auth/login');
        $I->seeResponseCodeIsClientError();
        $I->seeResponseIsJson();
    }

    public function garbageTokenIs401(ApiTester $I): void
    {
        $I->haveHttpHeader('Authorization', 'Bearer not.a.token');
        $I->sendGet('/v1/users/me');
        $I->seeApiError(401, 'UNAUTHENTICATED');
    }

    public function tokenSignedWithAnotherKeyIs401(ApiTester $I): void
    {
        $token = Auth::token(Auth::GOVERNMENT);
        [$header, $payload] = explode('.', $token);
        $forged = $header . '.' . $payload . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", 'another-key', true)), '+/', '-_'), '=');
        $I->haveHttpHeader('Authorization', 'Bearer ' . $forged);
        $I->sendGet('/v1/users/me');
        $I->seeApiError(401, 'UNAUTHENTICATED');
    }
}
