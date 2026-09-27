<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\AccessRule;
use app\components\ApiController;
use app\components\ApiException;
use app\models\DailyProduction;
use app\models\Mine;
use app\services\DetailRequestService;
use app\services\ProductionService;
use Yii;

/**
 * Production reporting (brief Phase 4).
 *
 *   GET    /v1/production?mine_id=&from=&to=         entries (detail - AccessRule)
 *   GET    /v1/production/detail?mine_id=&from=&to=  entries with their edit log, and charts (detail)
 *   GET    /v1/production/summary?state=&date=       numbers only, per mine (multi-mine roles)
 *   POST   /v1/production                            a mine head's draft for its own mine
 *   GET    /v1/production/{id}                       one entry with its edit log (detail)
 *   PATCH  /v1/production/{id}                       a draft freely; submitted / locked with `reason`
 *   POST   /v1/production/{id}/submit                draft -> submitted
 *   DELETE /v1/production/{id}                       a draft only
 */
class ProductionController extends ApiController
{
    public const MAX_RANGE_DAYS = 92;

    protected function verbs(): array
    {
        return [
            'index' => ['GET'], 'detail' => ['GET'], 'summary' => ['GET'], 'view' => ['GET'],
            'create' => ['POST'], 'update' => ['PATCH'], 'submit' => ['POST'], 'delete' => ['DELETE'],
        ];
    }

    public function actionIndex(): array
    {
        [$mine, $from, $to] = $this->range();
        AccessRule::assertProductionDetail((int) $mine->id, $from, $to);
        return array_map(fn(DailyProduction $e) => $e->toArray([], ['edits']), $this->entries((int) $mine->id, $from, $to));
    }

    public function actionDetail(): array
    {
        [$mine, $from, $to] = $this->range();
        $request = AccessRule::assertProductionDetail((int) $mine->id, $from, $to);
        $entries = DailyProduction::find()->where(['mine_id' => $mine->id])->andWhere(['between', 'date', $from, $to])
            ->with('edits.editor')->orderBy(['date' => SORT_ASC, 'shift' => SORT_ASC])->all();
        return [
            'mine' => ['id' => (int) $mine->id, 'code' => $mine->code, 'name' => $mine->name, 'state' => $mine->state],
            'from' => $from,
            'to' => $to,
            'request' => $request?->toArray(),
            'entries' => array_map(fn(DailyProduction $e) => $e->toArray([], ['edits']), $entries),
            'charts' => ProductionService::charts((int) $mine->id, $from, $to),
        ];
    }

    public function actionSummary(): array
    {
        $this->requirePermission('production.summary');
        DetailRequestService::escalateDue();
        $date = $this->dateParam('date') ?? ProductionService::today();
        return ProductionService::summary($this->visibleMines(Yii::$app->request->get('state')), $date);
    }

    public function actionView(int $id): array
    {
        $entry = DailyProduction::findScoped($id);
        AccessRule::assertProductionDetail((int) $entry->mine_id, $entry->date, $entry->date);
        return $entry->toArray([], ['edits']);
    }

    public function actionCreate(): array
    {
        $this->requirePermission('production.manage');
        $user = $this->currentUser();
        if ($user->mine_id === null) {
            throw ApiException::forbidden();
        }
        Yii::$app->response->statusCode = 201;
        return ProductionService::create($user, $this->body())->toArray();
    }

    public function actionUpdate(int $id): array
    {
        $this->requirePermission('production.manage');
        $entry = DailyProduction::findScoped($id);
        $body = $this->body();
        $entry = $entry->isDraft()
            ? ProductionService::updateDraft($entry, $body)
            : ProductionService::editWithReason($entry, $body, (string) ($body['reason'] ?? ''), $this->currentUser());
        return $entry->toArray([], ['edits']);
    }

    public function actionSubmit(int $id): array
    {
        $this->requirePermission('production.manage');
        return ProductionService::submit(DailyProduction::findScoped($id), $this->currentUser())->toArray();
    }

    public function actionDelete(int $id): void
    {
        $this->requirePermission('production.manage');
        ProductionService::deleteDraft(DailyProduction::findScoped($id));
        Yii::$app->response->statusCode = 204;
    }

    /** @return DailyProduction[] */
    private function entries(int $mineId, string $from, string $to): array
    {
        return DailyProduction::find()->where(['mine_id' => $mineId])->andWhere(['between', 'date', $from, $to])
            ->with('edits.editor')->orderBy(['date' => SORT_DESC, 'shift' => SORT_ASC])->all();
    }

    /**
     * The mine (?mine_id=, scope-checked; a mine head's own by default) and the date range
     * (?from=&to=, the current month by default).
     * @return array{0: Mine, 1: string, 2: string}
     */
    private function range(): array
    {
        $mine = $this->mineParam();
        if ($mine === null) {
            $own = $this->currentUser()->mine_id;
            if ($own === null) {
                throw ApiException::fields(['mine_id' => ['REQUIRED']]);
            }
            $mine = Mine::findScoped((int) $own);
        }
        $today = ProductionService::today();
        $from = $this->dateParam('from') ?? substr($today, 0, 8) . '01';
        $to = $this->dateParam('to') ?? $today;
        if ($to < $from) {
            throw ApiException::fields(['to' => ['BEFORE_START']]);
        }
        if ((new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days >= self::MAX_RANGE_DAYS) {
            throw ApiException::fields(['to' => ['RANGE_TOO_LONG']]);
        }
        return [$mine, $from, $to];
    }

    private function dateParam(string $name): ?string
    {
        $value = Yii::$app->request->get($name);
        if ($value === null || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ApiException::fields([$name => ['INVALID_DATE']]);
        }
        return $value;
    }
}
