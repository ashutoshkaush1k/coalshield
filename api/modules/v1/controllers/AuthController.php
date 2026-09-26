<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
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

        $user = User::findOne(['email' => mb_strtolower(trim($body['email']))]);
        // Same answer for unknown email, wrong password and inactive account.
        if ($user === null || $user->status !== User::STATUS_ACTIVE || !$user->validatePassword($body['password'])) {
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
