<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\models\Mine;
use Yii;
use yii\web\Response;

/**
 * Map outlines for the offline map (Phase 5B), from the data track - no internet needed:
 *
 *   GET /v1/geo/states      data/reference/state_boundaries.geojson (DataMeet States, CC BY 4.0)
 *   GET /v1/geo/districts   data/reference/map_districts.geojson   (DataMeet Census 2011 districts
 *                           containing the roster's mines, CC BY 2.5 IN), limited to the districts
 *                           of the mines in the caller's scope
 *
 * Served with an ETag, so a browser keeps them and asks again only with If-None-Match (304).
 * The mines themselves come from GET /v1/views/map, which is scoped per role.
 */
class GeoController extends ApiController
{
    private const FILES = ['states' => 'state_boundaries.geojson', 'districts' => 'map_districts.geojson'];

    protected function verbs(): array
    {
        return ['boundaries' => ['GET']];
    }

    public function actionBoundaries(string $kind): Response
    {
        $this->requirePermission('mine.view');
        if (!isset(self::FILES[$kind])) {
            throw ApiException::notFound();
        }
        $dir = (string) Yii::$app->params['dataReferenceDir'];
        if (!preg_match('~^([a-zA-Z]:)?[/\\\\]~', $dir)) {
            $dir = Yii::getAlias('@app') . '/' . $dir;
        }
        $path = $dir . '/' . self::FILES[$kind];
        if (!is_file($path)) {
            throw new ApiException(503, 'BOUNDARIES_MISSING', ['file' => self::FILES[$kind]]);
        }
        // Districts are limited to those holding a mine in scope; the ETag covers that set.
        $codes = null;
        if ($kind === 'districts') {
            $codes = Mine::find()->forCurrentUser()->select('mine.code')->orderBy(['mine.code' => SORT_ASC])->column();
        }
        $etag = '"' . sha1(self::FILES[$kind] . '|' . filesize($path) . '|' . filemtime($path) . '|' . implode(',', $codes ?? [])) . '"';
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/geo+json; charset=UTF-8');
        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', 'private, max-age=86400');
        if (trim((string) Yii::$app->request->headers->get('If-None-Match')) === $etag) {
            $response->statusCode = 304;
            $response->content = '';
            return $response;
        }
        $content = (string) file_get_contents($path);
        if ($codes !== null) {
            $geo = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            // Keep a district when it holds a mine in scope, and list only those mines: a district
            // shared with another company's mine must not reveal that mine's code.
            $inScope = array_flip($codes);
            $features = [];
            foreach ($geo['features'] as $f) {
                $mines = array_values(array_filter($f['properties']['mines'] ?? [], fn($c) => isset($inScope[$c])));
                if ($mines !== []) {
                    $f['properties']['mines'] = $mines;
                    $features[] = $f;
                }
            }
            $geo['features'] = $features;
            $content = json_encode($geo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }
        $response->content = $content;
        return $response;
    }
}
