<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\components\StatusTransition;
use app\models\Alert;
use app\models\Contract;
use app\models\Contractor;
use app\models\ContractorDoc;
use app\models\ContractWorker;
use app\models\Mine;
use app\models\User;
use app\models\Violation;
use app\services\ContractorService;
use Yii;

/**
 * Contractors (brief Phase 3). Everyone reads the contractors of the mines in scope, each scored on
 * its contracts in that scope; multi-mine roles also get the per-mine summary. A mine head
 * manages (contractor.manage): registers a contractor together with its first contract at the
 * mine, edits it, changes its status with a reason.
 *
 *   GET   /v1/contractors?mine_id=&band=&status=   worst first, with score, band, reasons
 *   GET   /v1/contractors/summary?state=           per mine: count, compliance %, flagged, blacklisted
 *   GET   /v1/contractors/{id}                     detail: contracts, workers, documents, violations, alerts
 *   POST  /v1/contractors                          {name, ..., contract: {work_type, ...}}
 *   PATCH /v1/contractors/{id}
 *   POST  /v1/contractors/{id}/status              {status, reason}
 */
class ContractorController extends ApiController
{
    protected function verbs(): array
    {
        return ['index' => ['GET'], 'summary' => ['GET'], 'view' => ['GET'], 'create' => ['POST'], 'update' => ['PATCH'], 'status' => ['POST']];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('contractor.view');
        $request = Yii::$app->request;
        $mine = $this->mineParam();
        $mineIds = $mine !== null ? [(int) $mine->id] : $this->scopeMineIds();
        $query = Contractor::find()->forCurrentUser()->andFilterWhere(['contractor.status' => $request->get('status') ?: null]);
        if ($mine !== null) {
            $query->andWhere(['in', 'contractor.id', (new \yii\db\Query())->select('contractor_id')->from('{{%contract}}')->where(['mine_id' => $mine->id])]);
        }
        $contractors = $query->indexBy('id')->all();
        $evals = ContractorService::worstFirst(ContractorService::evaluate(array_values($contractors), $mineIds));
        if (($band = $request->get('band')) !== null && $band !== '') {
            $evals = array_values(array_filter($evals, fn($e) => $e['band'] === $band));
        }
        $total = count($evals);
        $perPage = max(1, min((int) ($request->get('per_page') ?? 100), 200));
        $page = max(1, (int) $request->get('page', 1));
        $headers = Yii::$app->response->headers;
        $headers->set('X-Total-Count', (string) $total);
        $headers->set('X-Page', (string) $page);
        $headers->set('X-Per-Page', (string) $perPage);
        return array_map(fn($e) => $contractors[$e['contractor_id']]->toArray() + ['compliance' => $e],
            array_slice($evals, ($page - 1) * $perPage, $perPage));
    }

    public function actionSummary(): array
    {
        $this->requirePermission('contractor.summary');
        $mines = $this->visibleMines(Yii::$app->request->get('state'));
        return ContractorService::summary(array_map(fn(Mine $m) => (int) $m->id, $mines));
    }

    public function actionView(int $id): array
    {
        $this->requirePermission('contractor.view');
        $contractor = Contractor::findScoped($id);
        $mineIds = $this->scopeMineIds();
        $contracts = Contract::find()->forCurrentUser()->andWhere(['contract.contractor_id' => $id])->with('mine')->orderBy(['contract.start_date' => SORT_DESC])->all();
        $contractIds = array_map(fn($c) => (int) $c->id, $contracts);
        $evaluation = ContractorService::evaluate([$contractor], $mineIds)[$id];
        return $contractor->toArray() + [
            'compliance' => $evaluation,
            'contracts' => array_map(fn($c) => $c->toArray(), $contracts),
            'workers' => array_map(fn($w) => $w->toArray(),
                ContractWorker::find()->where(['contract_id' => $contractIds])->orderBy(['active' => SORT_DESC, 'worker_code' => SORT_ASC])->all()),
            'documents' => array_map(fn($d) => $d->toArray(),
                ContractorDoc::find()->where(['contract_id' => $contractIds])->with('file')->orderBy(['period' => SORT_DESC, 'doc_type' => SORT_ASC])->all()),
            'violations' => array_map(fn($v) => $v->toArray(),
                Violation::find()->where(['contractor_id' => $id])->andFilterWhere(['mine_id' => $mineIds])
                    ->orderBy(['detected_at' => SORT_DESC])->limit(200)->all()),
            'alerts' => array_map(fn($a) => $a->toArray(),
                Alert::find()->where(['entity_type' => 'contract', 'entity_id' => $contractIds])->with('mine')
                    ->orderBy(['created_at' => SORT_DESC])->limit(100)->all()),
            'history' => array_map(fn($h) => $h->toArray(), \app\models\StatusHistory::forEntity($contractor)),
        ];
    }

    /** A mine head registers a contractor with its first contract at the mine (the contract is what puts it in scope). */
    public function actionCreate(): array
    {
        $this->requirePermission('contractor.manage');
        $user = $this->currentUser();
        $body = $this->body();
        $contractBody = is_array($body['contract'] ?? null) ? $body['contract'] : null;
        if ($contractBody === null) {
            throw ApiException::fields(['contract' => ['REQUIRED']]);
        }
        $contractor = new Contractor(['status' => 'active']);
        $contractor->setAttributes(array_intersect_key($body, array_flip(Contractor::EDITABLE)), false);
        $transaction = Yii::$app->db->beginTransaction();
        try {
            if (!$contractor->save()) {
                throw ApiException::validation($contractor);
            }
            $contract = new Contract(['contractor_id' => $contractor->id, 'mine_id' => $this->ownMineId($user)]);
            $contract->setAttributes(array_intersect_key($contractBody, array_flip(Contract::EDITABLE)), false);
            if (!$contract->save()) {
                $fields = [];
                foreach ($contract->getErrors() as $attr => $codes) {
                    $fields["contract.$attr"] = $codes;
                }
                throw ApiException::fields($fields);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        Yii::$app->response->statusCode = 201;
        return $this->actionView((int) $contractor->id);
    }

    public function actionUpdate(int $id): array
    {
        $this->requirePermission('contractor.manage');
        $contractor = Contractor::findScoped($id);
        $body = $this->body();
        $readOnly = array_diff(array_keys($body), Contractor::EDITABLE);
        if ($readOnly) {
            throw ApiException::fields(array_fill_keys(array_values($readOnly), ['READ_ONLY']));
        }
        $contractor->setAttributes($body, false);
        if (!$contractor->save()) {
            throw ApiException::validation($contractor);
        }
        return $this->actionView($id);
    }

    public function actionStatus(int $id): array
    {
        $this->requirePermission('contractor.manage');
        $contractor = Contractor::findScoped($id);
        $body = $this->body();
        $to = is_string($body['status'] ?? null) ? $body['status'] : '';
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') {
            throw ApiException::fields(['reason' => ['REQUIRED']]);
        }
        StatusTransition::apply($contractor, $to, ['reason' => $reason]);
        return $this->actionView($id);
    }

    /** Mine ids in the caller's scope; null means every mine. */
    private function scopeMineIds(): ?array
    {
        $role = $this->currentUser()->role;
        if ($role === User::ROLE_GOVERNMENT || $role === User::ROLE_INSPECTOR) {
            return null;
        }
        return array_map(fn(Mine $m) => (int) $m->id, $this->visibleMines());
    }

    private function ownMineId(User $user): int
    {
        if ($user->mine_id === null) {
            throw ApiException::forbidden();
        }
        return (int) $user->mine_id;
    }
}
