<?php

declare(strict_types=1);

namespace app\tests\api;

use app\tests\Support\ApiTester;
use Yii;
use yii\db\Query;

class HealthCest
{
    public function healthIsPublic(ApiTester $I): void
    {
        $I->sendGet('/v1/health');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'ok', 'database' => 'ok']);
    }

    /** POST /v1/system/jobs does not exist without JOBS_TOKEN (the laptop). */
    public function jobsTriggerIsOffWithoutAToken(ApiTester $I): void
    {
        Yii::$app->params['jobs.token'] = '';
        $I->haveHttpHeader('X-Jobs-Token', '');
        $I->sendPost('/v1/system/jobs');
        $I->seeApiError(404, 'NOT_FOUND');
    }

    /** Online (JOBS_TOKEN set): the right token runs the jobs, anything else is refused. */
    public function jobsTriggerNeedsTheToken(ApiTester $I): void
    {
        Yii::$app->params['jobs.token'] = 'test-only-token-0123456789';
        $I->sendPost('/v1/system/jobs');
        $I->seeApiError(403, 'FORBIDDEN');
        $I->haveHttpHeader('X-Jobs-Token', 'test-only-token-wrong');
        $I->sendPost('/v1/system/jobs');
        $I->seeApiError(403, 'FORBIDDEN');

        $I->haveHttpHeader('X-Jobs-Token', 'test-only-token-0123456789');
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/v1/system/jobs', ['jobs' => ['nope']]);
        $I->seeApiError(422, 'VALIDATION_FAILED');

        $before = (int) (new Query())->from('{{%job_run}}')->max('id');
        $I->sendPost('/v1/system/jobs', ['jobs' => ['grievance', 'contractor']]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['failed' => 0, 'jobs' => ['contractor' => ['status' => 'ok'], 'grievance' => ['status' => 'ok']]]);
        $runs = (new Query())->select('job')->from('{{%job_run}}')->where(['>', 'id', $before])->orderBy('id')->column();
        $I->assertSame(['contractor', 'grievance'], $runs, 'recorded in job_run, in jobs/all order');
    }

    public function unknownRouteUsesTheErrorFormat(ApiTester $I): void
    {
        $I->sendGet('/v1/does-not-exist');
        $I->seeApiError(404, 'NOT_FOUND');
    }
}
