<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\components\RateLimiter;
use app\models\User;
use Yii;

/** POST /v1/auth/login -> {access_token, token_type, expires_in, user}. */
class AuthController extends ApiController
{
    protected array $publicActions = ['login'];

    protected function verbs(): array
    {
        return ['login' => ['POST']];
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
}
