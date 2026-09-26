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

/** The test database was seeded with `yii_test seed small` by run_tests.bat. */
class SeedTest extends Unit
{
    private const PHASE1_TABLES = ['subsidiary', 'area', 'mine', 'user', 'file'];

    public function testRowCountsMatchTheManifest(): void
    {
        $manifest = $this->manifest('small');
        foreach (self::PHASE1_TABLES as $table) {
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
        $source = $this->presetDir('small');
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
        copy($this->presetDir('small') . '/_manifest.json', "$dir/invalid/_manifest.json");
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

    private function presetDir(string $preset): string
    {
        return Yii::getAlias('@app') . '/' . Yii::$app->params['dataOutDir'] . '/' . $preset;
    }

    private function manifest(string $preset): array
    {
        return json_decode(file_get_contents($this->presetDir($preset) . '/_manifest.json'), true);
    }
}
