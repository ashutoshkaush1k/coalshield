<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\components\AuditChain;
use app\components\DemoAccount;
use app\components\RateLimiter;
use app\models\User;
use Yii;

/**
 * POST /v1/auth/login -> {access_token, token_type, expires_in, user}.
 * GET / POST /v1/auth/demo - "Continue as admin (demo)", only with DEMO_LOGIN_ENABLED=true (else 404).
 */
class AuthController extends ApiController
{
    protected array $publicActions = ['login', 'demo', 'demo-status'];

    protected function verbs(): array
    {
        return ['login' => ['POST'], 'demo' => ['POST'], 'demo-status' => ['GET']];
    }

    public function actionLogin(): array
    {
        $body = $this->body();
        $fields = [];
        foreach (['email', 'password'] as $name) {
            if (!isset($body[$name]) || !is_string($body[$name]) || trim($body[$name]) === '') {
                $fields[$name] = ['REQUIRED'];
            }
        }
        if ($fields) {
            throw ApiException::fields($fields);
        }

        // Phase 8: failed sign-ins are limited per account and per address (15-minute windows), so a
        // password cannot be guessed at speed; a correct password is refused too while the limit holds.
        $email = mb_strtolower(trim($body['email']));
        $params = Yii::$app->params;
        $window = (int) $params['auth.failureWindowSeconds'];
        $buckets = ['auth.fail.email|' . $email => (int) $params['auth.failuresPerAccount'],
            'auth.fail.ip|' . RateLimiter::clientKey() => (int) $params['auth.failuresPerAddress']];
        foreach ($buckets as $bucket => $limit) {
            if (RateLimiter::exceeded($bucket, $limit, $window)) {
                $retry = $window - (time() % $window);
                Yii::$app->response->headers->set('Retry-After', (string) $retry);
                throw new ApiException(429, 'RATE_LIMITED', ['retry_after' => $retry]);
            }
        }
        $user = User::findOne(['email' => $email]);
        // Same answer for unknown email, wrong password and inactive account.
        if ($user === null || $user->status !== User::STATUS_ACTIVE || !$user->validatePassword($body['password'])) {
            foreach ($buckets as $bucket => $limit) {
                try {
                    RateLimiter::hit($bucket, PHP_INT_MAX, $window);   // count the failure; the check above enforces it
                } catch (ApiException) {
                }
            }
            throw new ApiException(401, 'INVALID_CREDENTIALS');
        }

        return [
            'access_token' => $user->issueToken(),
            'token_type' => 'bearer',
            'expires_in' => (int) Yii::$app->params['jwt.ttlHours'] * 3600,
            'user' => $user->toArray(),
        ];
    }

    /** GET /v1/auth/demo - whether the login page offers "Continue as admin (demo)": 200, or 404 when off. */
    public function actionDemoStatus(): array
    {
        if (!DemoAccount::enabled()) {
            throw ApiException::notFound();
        }
        return ['enabled' => true, 'session_minutes' => (int) Yii::$app->params['demo.sessionMinutes']];
    }

    /**
     * POST /v1/auth/demo - signs in the demo admin account (government role, every mine) with no
     * password: a session of demo.sessionMinutes. At most demo.perHour per address per hour
     * (429 RATE_LIMITED). Each sign-in is in the audit trail as "demo_login".
     */
    public function actionDemo(): array
    {
        if (!DemoAccount::enabled()) {
            throw ApiException::notFound();
        }
        $params = Yii::$app->params;
        RateLimiter::hit('auth.demo|' . RateLimiter::clientKey(), (int) $params['demo.perHour'], 3600);
        $user = User::findOne(['email' => DemoAccount::EMAIL, 'status' => User::STATUS_ACTIVE]);
        if ($user === null) {
            throw ApiException::notFound();   // the database has no demo account (yii demo/account)
        }
        $ttl = (int) $params['demo.sessionMinutes'] * 60;
        Yii::$app->user->setIdentity($user);   // the audit entry's actor
        AuditChain::append('user', (int) $user->id, 'demo_login', null, ['session_minutes' => (int) $params['demo.sessionMinutes']]);
        return [
            'access_token' => $user->issueToken(null, $ttl),
            'token_type' => 'bearer',
            'expires_in' => $ttl,
            'demo' => true,
            'user' => $user->toArray(),
        ];
    }
}
