<?php

declare(strict_types=1);

namespace app\modules\v1\controllers;

use app\components\ApiController;
use app\components\Format;
use app\models\AnomalyFlag;
use app\models\Mine;
use app\services\GovernanceRiskService;
use app\services\RiskModelService;
use Yii;
use yii\db\Query;

/**
 * Phase 7: what the automation found, for the screens.
 *
 *   GET /v1/anomalies?mine_id=&detector=&status=active|cleared|all&per_page=   the detectors' findings in scope
 *   GET /v1/mines/{id}/risk      the Governance Risk Index (with its components), the predictive
 *                                model's latest prediction (with its fleet rank and top factors)
 *                                and the mine's active findings
 *   GET /v1/risk/model           the model card: training data, split, test metrics, transfer note
 */
class RiskController extends ApiController
{
    protected function verbs(): array
    {
        return ['anomalies' => ['GET'], 'mine' => ['GET'], 'model' => ['GET']];
    }

    public function actionAnomalies(): array
    {
        $this->requirePermission('risk.view');
        $request = Yii::$app->request;
        $query = $this->scopedList(AnomalyFlag::find())->with('mine');
        $status = (string) ($request->get('status') ?? 'active');
        if ($status !== 'all') {
            $query->andWhere(['anomaly_flag.status' => $status === 'cleared' ? 'cleared' : 'active']);
        }
        if (($detector = $request->get('detector')) !== null && $detector !== '') {
            $query->andWhere(['anomaly_flag.detector' => (string) $detector]);
        }
        $perPage = max(1, min(200, (int) $request->get('per_page', 50)));
        return array_map(fn(AnomalyFlag $f) => $f->toArray(),
            $query->orderBy(['anomaly_flag.last_seen_at' => SORT_DESC, 'anomaly_flag.score' => SORT_DESC, 'anomaly_flag.id' => SORT_ASC])->limit($perPage)->all());
    }

    public function actionMine(int $id): array
    {
        $this->requirePermission('risk.view');
        $mine = Mine::findScoped($id);
        $gri = GovernanceRiskService::forMines([(int) $mine->id], $this->currentUser())[(int) $mine->id];
        $prediction = (new Query())->from('{{%mine_risk_prediction}}')->where(['mine_id' => $mine->id])->one();
        $out = ['mine_id' => (int) $mine->id, 'governance_risk' => $gri, 'prediction' => null];
        if ($prediction !== false && $prediction !== null) {
            $all = array_map('floatval', (new Query())->select('probability')->from('{{%mine_risk_prediction}}')->column());
            $below = count(array_filter($all, fn($p) => $p < (float) $prediction['probability']));
            $card = RiskModelService::card();
            $out['prediction'] = [
                'probability' => (float) $prediction['probability'],
                'band' => $prediction['band'],
                'fleet_percentile' => (int) round(100 * $below / max(1, count($all))),
                'factors' => Format::json($prediction['factors']),
                'predicted_at' => Format::utc($prediction['predicted_at']),
                'model_version' => $prediction['model_version'],
                'engine' => $prediction['engine'],
                'target' => $card['target'],
                'test_auc' => $card['test']['model']['auc'],
                'baseline_auc' => $card['test']['baseline']['auc'],
            ];
        }
        $out['patterns'] = array_map(fn(AnomalyFlag $f) => $f->toArray(),
            AnomalyFlag::find()->forCurrentUser()->andWhere(['anomaly_flag.mine_id' => $mine->id, 'anomaly_flag.status' => 'active'])
                ->orderBy(['anomaly_flag.score' => SORT_DESC, 'anomaly_flag.id' => SORT_ASC])->all());
        return $out;
    }

    public function actionModel(): array
    {
        $this->requirePermission('risk.view');
        return RiskModelService::card();
    }
}
