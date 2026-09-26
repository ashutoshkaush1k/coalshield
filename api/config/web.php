<?php

/**
 * Web application: a pure JSON API under /v1 (brief rule 8). No sessions, no cookies, no views.
 */

declare(strict_types=1);

$common = require __DIR__ . '/common.php';

return yii\helpers\ArrayHelper::merge($common, [
    'id' => 'coalshield-api',
    'bootstrap' => ['log'],
    'modules' => [
        'v1' => ['class' => app\modules\v1\Module::class],
    ],
    'components' => [
        'request' => [
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
            'parsers' => [
                'application/json' => yii\web\JsonParser::class,
            ],
        ],
        'response' => [
            'format' => yii\web\Response::FORMAT_JSON,
            'charset' => 'UTF-8',
            'formatters' => [
                yii\web\Response::FORMAT_JSON => [
                    'class' => yii\web\JsonResponseFormatter::class,
                    'encodeOptions' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
                ],
            ],
        ],
        'user' => [
            'identityClass' => app\models\User::class,
            'enableSession' => false,
            'loginUrl' => null,
        ],
        'errorHandler' => [
            'class' => app\components\ApiErrorHandler::class,
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => true,
            'rules' => require __DIR__ . '/routes.php',
        ],
    ],
]);
