<?php

declare(strict_types=1);

namespace app\tests\api;

use app\components\DemoAccount;
use app\models\AuditLog;
use app\models\User;
use app\tests\Support\ApiTester;
use Yii;

/** GET / POST /v1/auth/demo - "Continue as admin (demo)" (DEMO_LOGIN_ENABLED). */
class DemoLoginCest
{
    public function offByDefault(ApiTester $I): void
    {
        Yii::$app->params['demo.loginEnabled'] = false;
        $I->sendGet('/v1/auth/demo');
        $I->seeApiError(404, 'NOT_FOUND');
        $I->sendPost('/v1/auth/demo');
        $I->seeApiError(404, 'NOT_FOUND');
    }

    public function onItSignsInTheDemoAdminForThirtyMinutes(ApiTester $I): void
    {
        Yii::$app->params['demo.loginEnabled'] = true;
        $I->sendGet('/v1/auth/demo');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['enabled' => true, 'session_minutes' => 30]);

        $I->sendPost('/v1/auth/demo');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['token_type' => 'bearer', 'expires_in' => 1800, 'demo' => true,
            'user' => ['email' => DemoAccount::EMAIL, 'full_name' => 'Demo Admin (DGMS)', 'role' => 'government', 'is_demo' => true, 'mine_id' => null]]);
        $token = $I->grabDataFromResponseByJsonPath('$.access_token')[0];
        $claims = Yii::$app->jwt->parse($token)->claims();
        $I->assertSame(1800, $claims->get('exp')->getTimestamp() - $claims->get('iat')->getTimestamp(), 'a 30-minute session');

        // A government view: every mine.
        $I->haveHttpHeader('Authorization', "Bearer $token");
        $I->sendGet('/v1/users/me');
        $I->seeResponseContainsJson(['role' => 'government', 'is_demo' => true]);
        $I->sendGet('/v1/mines', ['per_page' => 200]);
        $I->seeResponseCodeIs(200);
        $I->assertCount(74, json_decode($I->grabResponse(), true));
    }

    public function eachDemoSignInIsAudited(ApiTester $I): void
    {
        Yii::$app->params['demo.loginEnabled'] = true;
        $demo = User::findOne(['email' => DemoAccount::EMAIL]);
        $I->sendPost('/v1/auth/demo');
        $I->seeResponseCodeIs(200);
        /** @var AuditLog $entry */
        $entry = AuditLog::find()->where(['action' => 'demo_login'])->orderBy(['id' => SORT_DESC])->one();
        $I->assertNotNull($entry);
        $I->assertSame('user', $entry->entity);
        $I->assertSame((int) $demo->id, (int) $entry->entity_id);
        $I->assertSame((int) $demo->id, (int) $entry->user_id, 'the demo account is the actor');
    }

    public function anExpiredDemoSessionIsRefused(ApiTester $I): void
    {
        $demo = User::findOne(['email' => DemoAccount::EMAIL]);
        $issued = new \DateTimeImmutable('-31 minutes', new \DateTimeZone('UTC'));
        $I->haveHttpHeader('Authorization', 'Bearer ' . $demo->issueToken($issued, 1800));
        $I->sendGet('/v1/users/me');
        $I->seeApiError(401, 'UNAUTHENTICATED');
    }

    public function rateLimitedPerAddress(ApiTester $I): void
    {
        Yii::$app->params['demo.loginEnabled'] = true;
        Yii::$app->params['demo.perHour'] = 2;
        $I->sendPost('/v1/auth/demo');
        $I->seeResponseCodeIs(200);
        $I->sendPost('/v1/auth/demo');
        $I->seeResponseCodeIs(200);
        $I->sendPost('/v1/auth/demo');
        $I->seeApiError(429, 'RATE_LIMITED');
        $I->seeHttpHeader('Retry-After');
    }

    public function theDemoAccountHasNoUsablePassword(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/v1/auth/login', ['email' => DemoAccount::EMAIL, 'password' => 'demo123']);
        $I->seeApiError(401, 'INVALID_CREDENTIALS');
    }
}
