<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\components\Rules;
use app\services\SensorService;
use Codeception\Test\Unit;

/** Legal limits come from data/schema/rules.yaml, never from code (HANDOFF item 6). */
class SensorRulesTest extends Unit
{
    public function testLimitsAreReadFromRulesYaml(): void
    {
        $sensors = Rules::sensors();
        $this->assertSame(1.25, $sensors['ch4']['limit']);
        $this->assertSame('SAF-11', $sensors['ch4']['obligation']);
        $this->assertSame(0.75, $sensors['ch4_return_air']['limit']);
        $this->assertSame(2.0, $sensors['dust']['limit']);
        $this->assertSame('rolling_8h_mean', $sensors['dust']['compare']);
        $this->assertSame(33.5, $sensors['temperature']['limit']);
        $this->assertNull($sensors['co']['limit'], 'TODO-VERIFY in rules.yaml means no limit');
        $this->assertNull($sensors['humidity']['limit']);
    }

    public function testBreachIsStrictlyAboveTheLimit(): void
    {
        $this->assertFalse(SensorService::isBreach('ch4', 1.25));
        $this->assertTrue(SensorService::isBreach('ch4', 1.26));
        $this->assertTrue(SensorService::isBreach('temperature', 33.6));
        $this->assertNull(SensorService::isBreach('co', 900.0));
        $this->assertNull(SensorService::isBreach('humidity', 99.0));
    }

    public function testDustUsesTheRollingMean(): void
    {
        $this->assertFalse(SensorService::isBreach('dust', 5.0, 1.9));
        $this->assertTrue(SensorService::isBreach('dust', 1.0, 2.1));
    }

    public function testSeverityByMargin(): void
    {
        $this->assertSame('low', SensorService::severityForMargin('ch4', 1.3));     // 4 % over
        $this->assertSame('medium', SensorService::severityForMargin('ch4', 1.4));  // 12 % over
        $this->assertSame('high', SensorService::severityForMargin('ch4', 1.7));    // 36 % over
    }

    public function testElevenCategories(): void
    {
        $this->assertCount(11, Rules::categories());
        $this->assertContains('machinery', Rules::categories());
    }
}
