<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\components\ApiException;
use app\components\HasStatusTransitions;
use app\components\StatusTransition;
use app\models\Mine;
use Codeception\Test\Unit;

/** A mine with a toy workflow, only to exercise the engine. */
class WorkflowMine extends Mine implements HasStatusTransitions
{
    /** @var list<array{from: string, to: string, user: ?int, context: array}> */
    public static array $history = [];

    public static function statusAttribute(): string
    {
        return 'status';
    }

    public static function transitions(): array
    {
        return ['active' => ['inactive'], 'inactive' => []];
    }

    public function recordTransition(string $from, string $to, ?int $userId, array $context): void
    {
        self::$history[] = ['from' => $from, 'to' => $to, 'user' => $userId, 'context' => $context];
    }
}

class StatusTransitionTest extends Unit
{
    protected function _before(): void
    {
        WorkflowMine::$history = [];
    }

    public function testAllowedTransitionIsSavedAndRecorded(): void
    {
        $mine = WorkflowMine::find()->where(['status' => 'active'])->one();
        StatusTransition::apply($mine, 'inactive', ['reason' => 'closed for test']);

        $this->assertSame('inactive', Mine::find()->where(['id' => $mine->id])->select('status')->scalar());
        $this->assertSame([['from' => 'active', 'to' => 'inactive', 'user' => null, 'context' => ['reason' => 'closed for test']]], WorkflowMine::$history);
    }

    public function testInvalidTransitionIs422WithParams(): void
    {
        $mine = WorkflowMine::find()->where(['status' => 'active'])->one();
        StatusTransition::apply($mine, 'inactive');
        try {
            StatusTransition::apply($mine, 'active');
            $this->fail('expected INVALID_TRANSITION');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->statusCode);
            $this->assertSame('INVALID_TRANSITION', $e->errorCode);
            $this->assertSame(['from' => 'inactive', 'to' => 'active'], $e->params);
        }
        $this->assertCount(1, WorkflowMine::$history);
    }

    public function testUnknownTargetIsRejected(): void
    {
        $mine = WorkflowMine::find()->where(['status' => 'active'])->one();
        $this->assertFalse(StatusTransition::canTransition($mine, 'exploded'));
        $this->expectException(ApiException::class);
        StatusTransition::apply($mine, 'exploded');
    }
}
