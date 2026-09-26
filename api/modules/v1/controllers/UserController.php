<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\models\User;

/** GET /v1/users/me (alias /v1/auth/me) and PATCH /v1/users/me (preferred_language only). */
class UserController extends ApiController
{
    protected function verbs(): array
    {
        return ['me' => ['GET'], 'update-me' => ['PATCH']];
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
}
