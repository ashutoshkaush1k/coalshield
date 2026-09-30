<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\components\RateLimiter;
use app\models\User;
use Yii;

/**
 * GET /v1/users/me (alias /v1/auth/me), PATCH /v1/users/me (preferred_language only) and
 * POST /v1/users/me/password (change password, from the Profile page).
 */
class UserController extends ApiController
{
    protected function verbs(): array
    {
        return ['me' => ['GET'], 'update-me' => ['PATCH'], 'change-password' => ['POST']];
    }

    public function actionMe(): User
    {
        $this->requirePermission('user.viewOwn');
        return $this->currentUser();
    }

    public function actionUpdateMe(): User
    {
        $this->requirePermission('user.updateOwnLanguage');
        $body = $this->body();

        $readOnly = array_diff(array_keys($body), ['preferred_language']);
        if ($readOnly) {
            throw ApiException::fields(array_fill_keys(array_values($readOnly), ['READ_ONLY']));
        }
        if (!array_key_exists('preferred_language', $body)) {
            throw ApiException::fields(['preferred_language' => ['REQUIRED']]);
        }

        $user = $this->currentUser();
        $user->scenario = User::SCENARIO_UPDATE_ME;
        $user->preferred_language = is_string($body['preferred_language']) ? $body['preferred_language'] : '';
        if (!$user->save()) {
            throw ApiException::validation($user);
        }
        return $user;
    }

    /**
     * {current_password, new_password} -> 204. The new one needs auth.passwordMinLength (12)
     * characters. A wrong current password counts against the same per-account limit as a failed
     * sign-in (429 once over it). The change is in the audit chain with the hash redacted; tokens
     * already issued stay valid until they expire.
     */
    public function actionChangePassword(): void
    {
        $this->requirePermission('user.changeOwnPassword');
        $body = $this->body();
        $fields = [];
        foreach (['current_password', 'new_password'] as $name) {
            if (!isset($body[$name]) || !is_string($body[$name]) || $body[$name] === '') {
                $fields[$name] = ['REQUIRED'];
            }
        }
        if ($fields) {
            throw ApiException::fields($fields);
        }

        $user = $this->currentUser();
        $params = Yii::$app->params;
        $window = (int) $params['auth.failureWindowSeconds'];
        $bucket = 'auth.fail.email|' . mb_strtolower($user->email);
        if (RateLimiter::exceeded($bucket, (int) $params['auth.failuresPerAccount'], $window)) {
            $retry = $window - (time() % $window);
            Yii::$app->response->headers->set('Retry-After', (string) $retry);
            throw new ApiException(429, 'RATE_LIMITED', ['retry_after' => $retry]);
        }
        if (!$user->validatePassword($body['current_password'])) {
            try {
                RateLimiter::hit($bucket, PHP_INT_MAX, $window);
            } catch (ApiException) {
            }
            throw ApiException::fields(['current_password' => ['WRONG_PASSWORD']]);
        }
        $new = $body['new_password'];
        $min = (int) $params['auth.passwordMinLength'];
        if (mb_strlen($new) < $min) {
            throw new ApiException(422, 'VALIDATION_FAILED', ['min_length' => $min], ['new_password' => ['TOO_SHORT']]);
        }
        if (mb_strlen($new) > 128) {
            throw ApiException::fields(['new_password' => ['TOO_LONG']]);
        }
        if ($new === $body['current_password']) {
            throw ApiException::fields(['new_password' => ['SAME_AS_CURRENT']]);
        }
        $user->setPassword($new);
        $user->save(false);
        Yii::$app->response->statusCode = 204;
    }
}
