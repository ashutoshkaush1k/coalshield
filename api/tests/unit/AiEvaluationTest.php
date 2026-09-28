<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\commands\AiController;
use app\services\AiEvaluation;
use Codeception\Test\Unit;

/**
 * docs/AI_EVALUATION.md section 1 is the truth (Phase 7): the detector table there is recomputed
 * here from the seeded demo data and scenario_expectations.json, and must match to the character.
 * After a deliberate change to a detector or the generator: `php yii ai/evaluate --write`.
 */
class AiEvaluationTest extends Unit
{
    private array $result;

    protected function _before(): void
    {
        if ((getenv('TEST_PRESET') ?: 'demo') !== 'demo') {
            $this->markTestSkipped('the evaluation table is for the demo preset');
        }
        $this->result = AiEvaluation::score(...array_values(AiController::evaluation('php')));
    }

    public function testTableInTheDocsIsCurrent(): void
    {
        $doc = (string) file_get_contents(\Yii::getAlias('@app') . AiController::DOC);
        $start = strpos($doc, AiController::START);
        $end = strpos($doc, AiController::END);
        $this->assertNotFalse($start, 'start marker in docs/AI_EVALUATION.md');
        $this->assertNotFalse($end, 'end marker in docs/AI_EVALUATION.md');
        $inDoc = substr($doc, $start + strlen(AiController::START), $end - $start - strlen(AiController::START));
        $this->assertSame(AiEvaluation::markdown($this->result), ltrim(str_replace("\r\n", "\n", $inDoc), "\n"),
            'docs/AI_EVALUATION.md is out of date: run php yii ai/evaluate --write');
    }

    public function testEveryPositiveFoundAndEveryDecoyIgnored(): void
    {
        $this->assertCount(10, $this->result['scenarios']);
        foreach ($this->result['scenarios'] as $s) {
            $this->assertSame($s['expected'] === 'flag' ? 'flagged' : 'ignored', $s['result'], $s['scenario']);
        }
    }

    public function testEachDetectorIsScored(): void
    {
        $this->assertEqualsCanonicalizing(array_unique(array_values(AiEvaluation::DETECTOR_OF)),
            array_column($this->result['detectors'], 'detector'));
    }
}
