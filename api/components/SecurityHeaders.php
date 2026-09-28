<?php

declare(strict_types=1);

namespace app\components;

use yii\base\Event;
use yii\web\Response;

/**
 * Response headers for every API answer (Phase 8 security pass, docs/SECURITY.md).
 *
 *   X-Content-Type-Options: nosniff     a file is never re-interpreted as something else
 *   X-Frame-Options: DENY               the API is never framed
 *   Referrer-Policy: no-referrer
 *   Content-Security-Policy             JSON answers: nothing may load or run from them
 *   Cache-Control: no-store             JSON answers carry account-scoped data
 *
 * A stored file (signed link, e.g. a proof photo or a PDF) keeps its own caching and no CSP, so the
 * browser can show it; nosniff and the frame ban still apply.
 */
final class SecurityHeaders
{
    public static function apply(Event $event): void
    {
        /** @var Response $response */
        $response = $event->sender;
        $h = $response->getHeaders();
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'no-referrer');
        $h->remove('X-Powered-By');
        if ($response->format === Response::FORMAT_JSON) {
            $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
            if (!$h->has('Cache-Control')) {
                $h->set('Cache-Control', 'no-store');
            }
        }
    }
}
