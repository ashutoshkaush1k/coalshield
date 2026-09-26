<?php

declare(strict_types=1);

namespace app\components;

use app\models\User;
use bizley\jwt\JwtHttpBearerAuth;
use Yii;
use yii\filters\ContentNegotiator;
use yii\filters\Cors;
use yii\filters\VerbFilter;
use yii\rest\Controller;
use yii\web\Response;

/**
 * Base for every v1 controller: JSON only, CORS for the Vite dev origin, JWT bearer auth (except
 * actions listed in $publicActions), verb filtering. Controllers stay thin (brief rule 6) and check
 * permissions, never roles (rule 3).
 */
abstract class ApiController extends Controller
{
    /** @var string[] actions that need no token */
    protected array $publicActions = [];

    public function behaviors(): array
    {
        return [
            'contentNegotiator' => [
                'class' => ContentNegotiator::class,
                'formats' => ['application/json' => Response::FORMAT_JSON],
            ],
            'corsFilter' => [
                'class' => Cors::class,
                'cors' => [
                    'Origin' => Yii::$app->params['cors.origins'],
                    'Access-Control-Request-Method' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],
                    'Access-Control-Request-Headers' => ['Authorization', 'Content-Type', 'X-Api-Key'],
                    'Access-Control-Max-Age' => 3600,
                    'Access-Control-Expose-Headers' => ['X-Total-Count', 'X-Page', 'X-Per-Page'],
                ],
            ],
            'authenticator' => [
                // Token validated by the jwt component, then User::findIdentityByAccessToken() reads its claims.
                'class' => JwtHttpBearerAuth::class,
                'throwException' => false,
                'except' => array_merge(['options'], $this->publicActions),
            ],
            'verbFilter' => [
                'class' => VerbFilter::class,
                'actions' => $this->verbs(),
            ],
        ];
    }

    /** 403 FORBIDDEN unless the current user holds the permission (brief rule 3). */
    protected function requirePermission(string $permission): void
    {
        if (!Yii::$app->user->can($permission)) {
            throw ApiException::forbidden();
        }
    }

    protected function currentUser(): User
    {
        /** @var User $user */
        $user = Yii::$app->user->identity;
        return $user;
    }

    /** The mine named by a query parameter, scope-checked (404 when out of scope); null if absent. */
    protected function mineParam(string $name = 'mine_id'): ?\app\models\Mine
    {
        $value = Yii::$app->request->get($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (!ctype_digit((string) $value)) {
            throw ApiException::fields([$name => ['INVALID_VALUE']]);
        }
        return \app\models\Mine::findScoped((int) $value);
    }

    /** Every mine the caller may see, optionally narrowed to one state; ordered by id. */
    protected function visibleMines(?string $state = null): array
    {
        return \app\models\Mine::find()->forCurrentUser()
            ->andFilterWhere(['mine.state' => $state ?: null])
            ->orderBy(['mine.id' => SORT_ASC])->all();
    }

    /** Scope a listing to one mine when ?mine_id= is given (validated), else to the caller. */
    protected function scopedList(\app\components\ScopedActiveQuery $query, string $column = 'mine_id'): \app\components\ScopedActiveQuery
    {
        $query->forCurrentUser();
        $mine = $this->mineParam();
        if ($mine !== null) {
            $query->andWhere([$query->modelClass::tableName() . '.' . $column => $mine->id]);
        }
        return $query;
    }

    /** @return array<string, mixed> JSON request body (empty array when none). */
    protected function body(): array
    {
        $body = Yii::$app->request->getBodyParams();
        return is_array($body) ? $body : [];
    }
}
