<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\commands\SeedController;
use app\models\Mine;
use app\models\User;
use app\tests\Support\Helper\Auth;
use Codeception\Test\Unit;
use Yii;
use yii\console\ExitCode;
use yii\helpers\FileHelper;

/** The test database was seeded by run_tests.bat (`yii_test seed demo` unless TEST_PRESET says otherwise). */
class SeedTest extends Unit
{
    private const LOADED_TABLES = ['subsidiary', 'area', 'mine', 'user', 'file', 'inspection', 'observation',
        'violation', 'alert', 'corrective_action', 'incident', 'sensor_reading', 'env_reading'];

    public function testRowCountsMatchTheManifest(): void
    {
        $manifest = $this->manifest(self::seededPreset());
        foreach (self::LOADED_TABLES as $table) {
            $count = (int) Yii::$app->db->createCommand("SELECT count(*) FROM \"$table\"")->queryScalar();
            $this->assertSame($manifest['tables'][$table]['rows'], $count, $table);
        }
    }

    public function testDemoPasswordsAreHashedNotStored(): void
    {
        $user = Auth::user(Auth::CORPORATE_SECL);
        $this->assertStringStartsWith('$2y$', $user->password_hash);
        $this->assertTrue($user->validatePassword(Auth::PASSWORD));
        $this->assertSame(0, (int) User::find()->where(['password_hash' => Auth::PASSWORD])->count());
    }

    public function testGeometryAndEnumsLoaded(): void
    {
        $mine = Mine::find()->where(['code' => 'JH-DHN-01'])->one();
        $this->assertSame('Moonidih Coal Mine', $mine->name);
        $this->assertSame('Point', json_decode($mine->location_geojson, true)['type']);
        $srid = Yii::$app->db->createCommand('SELECT DISTINCT ST_SRID(location) FROM mine WHERE location IS NOT NULL')->queryColumn();
        $this->assertSame([4326], array_map('intval', $srid));
    }

    public function testRolesAreAssignedFromTheUserColumn(): void
    {
        foreach (User::find()->all() as $user) {
            $this->assertSame([$user->role], array_keys(Yii::$app->authManager->getRolesByUser($user->id)), $user->email);
        }
    }

    public function testSequencesContinueAfterSeededIds(): void
    {
        $max = (int) Mine::find()->max('id');
        $mine = new Mine([
            'code' => 'SEQ-TEST', 'name' => 'Sequence Test', 'type' => 'underground', 'subsidiary_id' => 1,
            'status' => 'active', 'district' => 'X', 'state' => 'Y', 'region' => 'East', 'location_quality' => 'wikidata',
        ]);
        $this->assertTrue($mine->save());
        $this->assertGreaterThan($max, $mine->id);
    }

    public function testColumnMismatchFailsAndChangesNothing(): void
    {
        $dir = Yii::getAlias('@runtime') . '/seed-test-' . bin2hex(random_bytes(4));
        FileHelper::createDirectory($dir . '/broken');
        $source = $this->presetDir(self::seededPreset());
        foreach (['_manifest.json', '_validation.json', 'subsidiary.csv', 'area.csv'] as $name) {
            copy("$source/$name", "$dir/broken/$name");
        }
        // mine.csv with one column renamed
        $csv = file_get_contents("$source/mine.csv");
        file_put_contents("$dir/broken/mine.csv", preg_replace('/^(.*?)\bregion\b/', '$1zone', $csv, 1));

        $before = (int) Mine::find()->count();
        $params = Yii::$app->params;
        Yii::$app->params['dataOutDir'] = $dir;
        try {
            $controller = new SeedController('seed', Yii::$app);
            ob_start();
            $code = $controller->actionIndex('broken');
            ob_end_clean();
        } finally {
            Yii::$app->params = $params;
            FileHelper::removeDirectory($dir);
        }
        $this->assertSame(ExitCode::DATAERR, $code);
        $this->assertSame($before, (int) Mine::find()->count(), 'the failed seed rolled back');
    }

    public function testRefusesAPresetThatFailedValidation(): void
    {
        $dir = Yii::getAlias('@runtime') . '/seed-test-' . bin2hex(random_bytes(4));
        FileHelper::createDirectory($dir . '/invalid');
        copy($this->presetDir(self::seededPreset()) . '/_manifest.json', "$dir/invalid/_manifest.json");
        file_put_contents("$dir/invalid/_validation.json", json_encode(['checks' => [['check' => 'V1 schema', 'pass' => false]]]));

        $params = Yii::$app->params;
        Yii::$app->params['dataOutDir'] = $dir;
        try {
            $this->expectException(\yii\console\Exception::class);
            (new SeedController('seed', Yii::$app))->actionIndex('invalid');
        } finally {
            Yii::$app->params = $params;
            FileHelper::removeDirectory($dir);
        }
    }

    public static function seededPreset(): string
    {
        $values = Yii::$app->db->createCommand("SELECT new_values FROM audit_log WHERE entity = 'seed' ORDER BY id LIMIT 1")->queryScalar();
        return json_decode($values, true)['preset'];
    }

    public function testBaselineIsRecordedInTheSeedEntry(): void
    {
        $values = json_decode(Yii::$app->db->createCommand("SELECT new_values FROM audit_log WHERE entity = 'seed' ORDER BY id LIMIT 1")->queryScalar(), true);
        $open = (int) Yii::$app->db->createCommand('SELECT count(*) FROM violation WHERE NOT resolved')->queryScalar();
        $this->assertSame($open, array_sum($values['baseline_open_violations']));
    }

    public function testSeededHistoryIsInTheChainWithOriginalTimestampsAndActors(): void
    {
        $db = Yii::$app->db;
        $q = fn(string $sql) => (int) $db->createCommand($sql)->queryScalar();
        $expected = $q('SELECT count(*) FROM violation') + $q('SELECT count(*) FROM violation WHERE resolved')
            + $q('SELECT count(*) FROM corrective_action') + $q("SELECT count(*) FROM corrective_action WHERE status = 'resolved'")
            + $q('SELECT count(*) FROM incident') + $q('SELECT count(*) FROM inspection WHERE visited_at IS NOT NULL')
            + $q('SELECT count(*) FROM inspection WHERE closed_at IS NOT NULL') + $q('SELECT count(*) FROM alert')
            + $q('SELECT count(*) FROM contract') + $q('SELECT count(*) FROM contractor_compliance_doc');
        $this->assertSame($expected, $q("SELECT count(*) FROM audit_log WHERE source = 'seed_history'"));

        // Original timestamp and actor: an incident's report, an inspection visit by its inspector.
        $incident = $db->createCommand('SELECT id, reported_at FROM incident ORDER BY id LIMIT 1')->queryOne();
        $entry = $db->createCommand("SELECT created_at, source FROM audit_log WHERE entity = 'incident' AND entity_id = :id AND action = 'reported'",
            [':id' => $incident['id']])->queryOne();
        $this->assertSame(strtotime($incident['reported_at']), strtotime($entry['created_at']));
        $this->assertSame('seed_history', $entry['source']);
        $visit = $db->createCommand("SELECT s.inspector_id, a.user_id FROM inspection s JOIN audit_log a
            ON a.entity = 'inspection' AND a.entity_id = s.id AND a.action = 'visited' ORDER BY s.id LIMIT 1")->queryOne();
        $this->assertSame((int) $visit['inspector_id'], (int) $visit['user_id']);

        // History is in time order along the chain, and the chain verifies.
        $this->assertSame(0, $q("SELECT count(*) FROM (SELECT created_at < lag(created_at) OVER (ORDER BY id) AS back
            FROM audit_log WHERE source = 'seed_history') t WHERE back"));
        $this->assertSame([], \app\components\AuditChain::verify());
    }

    public function testSensorReadingsLandInMonthlyPartitions(): void
    {
        $inDefault = (int) Yii::$app->db->createCommand('SELECT count(*) FROM sensor_reading_default')->queryScalar();
        $this->assertSame(0, $inDefault, 'every seeded reading has a monthly partition');
        $partitions = Yii::$app->db->createCommand("SELECT count(*) FROM pg_inherits WHERE inhparent = 'sensor_reading'::regclass")->queryScalar();
        $this->assertGreaterThan(12, (int) $partitions);
    }

    private function presetDir(string $preset): string
    {
        return Yii::getAlias('@app') . '/' . Yii::$app->params['dataOutDir'] . '/' . $preset;
    }

    private function manifest(string $preset): array
    {
        return json_decode(file_get_contents($this->presetDir($preset) . '/_manifest.json'), true);
    }
}
