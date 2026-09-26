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

    /** @return array<string, mixed> JSON request body (empty array when none). */
    protected function body(): array
    {
        $body = Yii::$app->request->getBodyParams();
        return is_array($body) ? $body : [];
    }
}
