<?php

/**
 * Components shared by the web and console applications.
 */

declare(strict_types=1);

use app\components\JwtClock;
use bizley\jwt\Jwt;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

$params = require __DIR__ . '/params.php';

return [
    'basePath' => dirname(__DIR__),
    'timeZone' => 'UTC',
    'language' => 'en',
    'params' => $params,
    'aliases' => [
        '@app' => dirname(__DIR__),
    ],
    'components' => [
        'db' => require __DIR__ . '/db.php',
        'cache' => yii\caching\FileCache::class,
        'authManager' => [
            'class' => yii\rbac\DbManager::class,
            'cache' => null,
        ],
        'security' => [
            'passwordHashCost' => (int) env('PASSWORD_HASH_COST', 10),
        ],
        'jwt' => [
            'class' => Jwt::class,
            'signer' => Jwt::HS256,
            'signingKey' => ['key' => (string) env('JWT_SECRET', ''), 'method' => Jwt::METHOD_PLAIN],
            'validationConstraints' => static function (Jwt $jwt) use ($params): array {
                $config = $jwt->getConfiguration();
                return [
                    new SignedWith($config->signer(), $config->verificationKey()),
                    new LooseValidAt(new JwtClock()),
                    new IssuedBy($params['jwt.issuer']),
                ];
            },
        ],
        'queue' => [
            'class' => yii\queue\db\Queue::class,
            'db' => 'db',
            'tableName' => '{{%queue}}',
            'channel' => 'default',
            'mutex' => yii\mutex\PgsqlMutex::class,
        ],
        'fileStorage' => [
            'class' => app\components\FileStorage::class,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                // logVars empty: never dump $_SERVER / $_ENV, they hold the .env secrets.
                // Client errors (4xx) are answers, not faults: not logged.
                [
                    'class' => yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                    'logVars' => [],
                    'except' => ['yii\web\HttpException:4*', 'JwtHttpBearerAuth'],
                ],
            ],
        ],
    ],
];
