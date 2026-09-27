<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\ApiException;
use app\components\ListingQuery;
use app\models\Contract;
use app\models\Contractor;
use app\models\ContractorDoc;
use app\models\ContractWorker;
use Yii;
use yii\web\UploadedFile;

/**
 * Contracts, their workers and monthly documents - all scoped by contract.mine_id; changes need
 * contractor.manage (the mine head). A contract is always at the mine head's own mine.
 *
 *   GET    /v1/contracts?contractor_id=        POST /v1/contracts {contractor_id, work_type, ...}
 *   PATCH  /v1/contracts/{id}                  DELETE /v1/contracts/{id} (only without workers or documents)
 *   POST   /v1/contracts/{id}/workers          PATCH / DELETE /v1/contract-workers/{id}
 *   POST   /v1/contracts/{id}/documents        multipart: doc_type, period (YYYY-MM), file
 *   POST   /v1/contractor-docs/{id}/verify     DELETE /v1/contractor-docs/{id}
 */
class ContractController extends ApiController
{
    protected function verbs(): array
    {
        return [
            'index' => ['GET'], 'create' => ['POST'], 'update' => ['PATCH'], 'delete' => ['DELETE'],
            'add-worker' => ['POST'], 'update-worker' => ['PATCH'], 'delete-worker' => ['DELETE'],
            'upload' => ['POST'], 'verify' => ['POST'], 'delete-document' => ['DELETE'],
        ];
    }

    public function actionIndex(): array
    {
        $this->requirePermission('contractor.view');
        return ListingQuery::apply($this->scopedList(Contract::find())->with('mine'),
            ['contractor_id', 'work_type'], ['start_date', 'end_date', 'id'], '-start_date');
    }

    public function actionCreate(): Contract
    {
        $this->requirePermission('contractor.manage');
        $body = $this->body();
        if (!isset($body['contractor_id']) || !is_numeric($body['contractor_id'])) {
            throw ApiException::fields(['contractor_id' => ['REQUIRED']]);
        }
        $contractor = Contractor::findScoped((int) $body['contractor_id']);
        $user = $this->currentUser();
        if ($user->mine_id === null) {
            throw ApiException::forbidden();
        }
        $contract = new Contract(['contractor_id' => $contractor->id, 'mine_id' => $user->mine_id]);
        $contract->setAttributes(array_intersect_key($body, array_flip(Contract::EDITABLE)), false);
        if (!$contract->save()) {
            throw ApiException::validation($contract);
        }
        Yii::$app->response->statusCode = 201;
        return $contract;
    }

    public function actionUpdate(int $id): Contract
    {
        $this->requirePermission('contractor.manage');
        $contract = Contract::findScoped($id);
        $body = $this->body();
        $readOnly = array_diff(array_keys($body), Contract::EDITABLE);
        if ($readOnly) {
            throw ApiException::fields(array_fill_keys(array_values($readOnly), ['READ_ONLY']));
        }
        $contract->setAttributes($body, false);
        if (!$contract->save()) {
            throw ApiException::validation($contract);
        }
        return $contract;
    }

    public function actionDelete(int $id): void
    {
        $this->requirePermission('contractor.manage');
        $contract = Contract::findScoped($id);
        if ($contract->getWorkers()->exists() || $contract->getDocuments()->exists()) {
            throw new ApiException(422, 'IN_USE', ['workers' => (int) $contract->getWorkers()->count(), 'documents' => (int) $contract->getDocuments()->count()]);
        }
        $contract->delete();
        Yii::$app->response->statusCode = 204;
    }

    public function actionAddWorker(int $id): ContractWorker
    {
        $this->requirePermission('contractor.manage');
        $contract = Contract::findScoped($id);
        $worker = new ContractWorker(['contract_id' => $contract->id, 'active' => true]);
        $worker->setAttributes(array_intersect_key($this->body(), array_flip(ContractWorker::EDITABLE)), false);
        if (!$worker->save()) {
            throw ApiException::validation($worker);
        }
        Yii::$app->response->statusCode = 201;
        return $worker;
    }

    public function actionUpdateWorker(int $id): ContractWorker
    {
        $this->requirePermission('contractor.manage');
        $worker = ContractWorker::findScoped($id);
        $body = $this->body();
        $readOnly = array_diff(array_keys($body), ContractWorker::EDITABLE);
        if ($readOnly) {
            throw ApiException::fields(array_fill_keys(array_values($readOnly), ['READ_ONLY']));
        }
        $worker->setAttributes($body, false);
        if (!$worker->save()) {
            throw ApiException::validation($worker);
        }
        return $worker;
    }

    public function actionDeleteWorker(int $id): void
    {
        $this->requirePermission('contractor.manage');
        ContractWorker::findScoped($id)->delete();
        Yii::$app->response->statusCode = 204;
    }

    /** Multipart upload of a monthly document; the file goes through FileStorage (size, type, sha256). */
    public function actionUpload(int $id): ContractorDoc
    {
        $this->requirePermission('contractor.manage');
        $contract = Contract::findScoped($id);
        $request = Yii::$app->request;
        $file = UploadedFile::getInstanceByName('file');
        if ($file === null) {
            throw ApiException::fields(['file' => ['REQUIRED']]);
        }
        $doc = new ContractorDoc([
            'contract_id' => $contract->id,
            'doc_type' => (string) $request->post('doc_type', ''),
            'period' => (string) $request->post('period', ''),
            'verified' => false,
            'file_id' => 0,
        ]);
        if (!$doc->validate(['doc_type', 'period'])) {
            throw ApiException::validation($doc);
        }
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $stored = Yii::$app->fileStorage->storeUpload($file, 'contractor_compliance_doc', 0, (int) $this->currentUser()->id);
            $doc->file_id = $stored->id;
            if (!$doc->save()) {
                throw ApiException::validation($doc);
            }
            $stored->entity_id = $doc->id;
            $stored->save(false);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        Yii::$app->response->statusCode = 201;
        return $doc;
    }

    public function actionVerify(int $id): ContractorDoc
    {
        $this->requirePermission('contractor.manage');
        $doc = ContractorDoc::findScoped($id);
        if ($doc->verified) {
            throw new ApiException(422, 'ALREADY_VERIFIED');
        }
        $doc->verified = true;
        $doc->verified_by = $this->currentUser()->id;
        $doc->save(false);
        return $doc;
    }

    public function actionDeleteDocument(int $id): void
    {
        $this->requirePermission('contractor.manage');
        ContractorDoc::findScoped($id)->delete();
        Yii::$app->response->statusCode = 204;
    }
}
