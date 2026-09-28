<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\services\AnomalyService;
use app\services\RiskModelService;
use Codeception\Test\Unit;

/**
 * The PHP twins answer exactly as the ai-service does (Phase 7), so the fallback changes nothing
 * but the engine label. Both sides are checked against the same files in tests/_data/detectors:
 * *.input.json dumped from the demo database (`yii ai/fixtures`), *.expected.json written by the
 * Python side (ai-service/tests/make_expected.py), which pytest checks in turn.
 */
class DetectorParityTest extends Unit
{
    private const DIR = __DIR__ . '/../_data/detectors/';

    public static function detectors(): array
    {
        return array_map(fn($name) => [$name], array_keys(AnomalyService::PHP));
    }

    /** @dataProvider detectors */
    public function testPhpDetectorMatchesPython(string $name): void
    {
        $payload = self::load("$name.input.json");
        $expected = self::load("$name.expected.json")['flags'];
        $actual = (AnomalyService::PHP[$name])::detect($payload);
        $this->assertCount(count($expected), $actual, "$name: number of flags");
        $this->assertSameJson($expected, $actual, $name);
    }

    public function testPhpModelMatchesPython(): void
    {
        $input = self::load('risk_predict.input.json');
        $features = [];
        foreach ($input['mines'] as $m) {
            $features[$m['mine_id']] = $m['features'];
        }
        $expected = self::load('risk_predict.expected.json');
        $actual = RiskModelService::predict($features, 'php');
        $this->assertSame('php', $actual['engine']);
        unset($actual['engine']);
        $this->assertSameJson($expected, $actual, 'risk_predict');
    }

    private static function load(string $file): array
    {
        return json_decode((string) file_get_contents(self::DIR . $file), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Equal as JSON: same keys and order-insensitive objects, numbers equal to 1e-9 (2 == 2.0). */
    private function assertSameJson($expected, $actual, string $path): void
    {
        if (is_array($expected) && is_array($actual)) {
            $list = array_is_list($expected) && array_is_list($actual);
            $this->assertSame($list ? count($expected) : self::sortedKeys($expected), $list ? count($actual) : self::sortedKeys($actual), "$path: keys");
            foreach ($expected as $k => $v) {
                $this->assertSameJson($v, $actual[$k], "$path.$k");
            }
            return;
        }
        if ((is_int($expected) || is_float($expected)) && (is_int($actual) || is_float($actual))) {
            $this->assertEqualsWithDelta((float) $expected, (float) $actual, 1e-9, $path);
            return;
        }
        $this->assertSame($expected, $actual, $path);
    }

    private static function sortedKeys(array $a): array
    {
        $keys = array_map('strval', array_keys($a));
        sort($keys);
        return $keys;
    }
}
