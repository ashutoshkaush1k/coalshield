<?php

declare(strict_types=1);

namespace app\commands;

use app\components\AuditChain;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Connection;
use yii\db\Exception as DbException;
use yii\helpers\Console;

/**
 * yii seed [preset]
 *
 * Loads data/out/<preset>/*.csv (written by data/generators, never generated here) into the
 * database, in the foreign-key order of data/HANDOFF.md, inside ONE transaction:
 *
 *   1. refuse if the preset's _validation.json has a failing check;
 *   2. TRUNCATE every seeded table plus audit_log and RBAC assignments (RESTART IDENTITY);
 *   3. per table: COPY the CSV into an all-text TEMP staging table, then INSERT ... SELECT with a
 *      cast to each column's real type (geometry via ST_GeomFromText(..., 4326));
 *      the CSV columns must match the table's columns exactly, or the seed fails;
 *   4. reset id sequences, compare row counts with _manifest.json;
 *   5. assign RBAC roles from user.role and write one "seed" entry that starts the audit chain.
 *
 * Tables whose migration belongs to a later phase are skipped with a note.
 */
class SeedController extends Controller
{
    /** HANDOFF load order => phase that creates the table. */
    private const LOAD_ORDER = [
        'subsidiary' => 1, 'area' => 1, 'mine' => 1, 'user' => 1, 'file' => 1,
        'contractor' => 3, 'contract' => 3, 'contract_worker' => 3, 'contractor_compliance_doc' => 3,
        'daily_production' => 4, 'production_edit_log' => 4, 'production_detail_request' => 4,
        'grievance' => 5, 'grievance_action' => 5,
        'inspection' => 2, 'observation' => 2, 'violation' => 2, 'alert' => 2,
        'corrective_action' => 2, 'incident' => 2, 'sensor_reading' => 2, 'env_reading' => 2,
    ];

    /** CSV column => table column, where they differ (HANDOFF conflict C11). */
    private const RENAMED = ['user' => ['password' => 'password_hash']];

    private const CHUNK = 5000;

    /** Allow seeding when YII_ENV is prod. */
    public bool $force = false;

    /** @var array<string, string> plain demo password => bcrypt hash (hash each distinct value once) */
    private array $hashes = [];

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['force']);
    }

    public function actionIndex(string $preset = 'demo'): int
    {
        if (YII_ENV_PROD && !$this->force) {
            $this->stderr("Refusing to seed with YII_ENV=prod (use --force).\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        if (!preg_match('/^[a-z0-9_]+$/', $preset)) {
            $this->stderr("Invalid preset name.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $dir = $this->presetDir($preset);
        $manifest = $this->readJson($dir . '/_manifest.json');
        $this->assertValidated($this->readJson($dir . '/_validation.json'));

        $db = Yii::$app->db;
        $started = microtime(true);
        $loaded = [];
        $transaction = $db->beginTransaction();
        try {
            $present = array_values(array_filter(array_keys(self::LOAD_ORDER), fn($t) => $db->getTableSchema($t, true) !== null));
            $truncate = array_merge($present, ['audit_log', $this->authManager()->assignmentTable]);
            $db->createCommand('TRUNCATE ' . implode(', ', array_map([$db, 'quoteTableName'], $truncate)) . ' RESTART IDENTITY CASCADE')->execute();
            $db->createCommand('SET CONSTRAINTS ALL DEFERRED')->execute();

            foreach (self::LOAD_ORDER as $table => $phase) {
                if (!in_array($table, $present, true)) {
                    $this->stdout(sprintf("  %-28s skipped (created in Phase %d)\n", $table, $phase), Console::FG_GREY);
                    continue;
                }
                $rows = $this->loadTable($db, $table, $dir . '/' . $table . '.csv');
                $expected = $manifest['tables'][$table]['rows'] ?? null;
                if ($expected !== null && $expected !== $rows) {
                    throw new \RuntimeException("$table: loaded $rows rows, _manifest.json says $expected");
                }
                $loaded[$table] = $rows;
                $this->stdout(sprintf("  %-28s %8d rows\n", $table, $rows));
            }

            $assigned = RbacController::syncAssignments($db);
            AuditChain::append('seed', null, 'seed', null, [
                'preset' => $preset,
                'roster' => $manifest['roster'] ?? null,
                'generator_seed' => $manifest['seed'] ?? null,
                'tables' => $loaded,
            ], $db);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->stderr('Seed failed, nothing was changed: ' . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->stdout(sprintf("Seeded preset '%s' (%d tables, %d role assignments) in %.1fs.\n",
            $preset, count($loaded), $assigned, microtime(true) - $started), Console::FG_GREEN);
        return ExitCode::OK;
    }

    private function loadTable(Connection $db, string $table, string $csvPath): int
    {
        $handle = @fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Missing $csvPath");
        }
        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if (!is_array($header)) {
                throw new \RuntimeException("$table: empty CSV");
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $renamed = self::RENAMED[$table] ?? [];
            $columns = array_map(fn($c) => $renamed[$c] ?? $c, $header);

            $schema = $db->getTableSchema($table, true);
            $dbColumns = $schema->columnNames;
            $missing = array_diff($dbColumns, $columns);
            $extra = array_diff($columns, $dbColumns);
            if ($missing || $extra || count($columns) !== count(array_unique($columns))) {
                throw new \RuntimeException(sprintf('%s: CSV columns do not match the table (missing in CSV: [%s]; not in table: [%s])',
                    $table, implode(', ', $missing), implode(', ', $extra)));
            }

            $stage = 'seed_stage_' . $table;
            $db->createCommand(sprintf('CREATE TEMP TABLE %s (%s) ON COMMIT DROP', $stage,
                implode(', ', array_map(fn($c) => $db->quoteColumnName($c) . ' text', $columns))))->execute();

            $pdo = $db->getMasterPdo();
            $fieldList = implode(',', array_map([$db, 'quoteColumnName'], $columns));
            $passwordIndex = array_search('password_hash', $columns, true);
            $count = 0;
            $buffer = [];
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;   // blank line
                }
                if (count($row) !== count($columns)) {
                    throw new \RuntimeException(sprintf('%s: row %d has %d fields, expected %d', $table, $count + 2, count($row), count($columns)));
                }
                if ($passwordIndex !== false) {
                    $row[$passwordIndex] = $this->hashPassword($row[$passwordIndex]);
                }
                $buffer[] = implode("\t", array_map([$this, 'copyField'], $row));
                $count++;
                if (count($buffer) >= self::CHUNK) {
                    $this->copy($pdo, $stage, $buffer, $fieldList, $table);
                    $buffer = [];
                }
            }
            if ($buffer) {
                $this->copy($pdo, $stage, $buffer, $fieldList, $table);
            }
        } finally {
            fclose($handle);
        }

        $types = $db->createCommand(<<<'SQL'
            SELECT a.attname, format_type(a.atttypid, a.atttypmod) AS type
              FROM pg_attribute a
             WHERE a.attrelid = :table::regclass AND a.attnum > 0 AND NOT a.attisdropped
            SQL, [':table' => $db->quoteTableName($table)])->queryAll();
        $typeOf = array_column($types, 'type', 'attname');
        $select = array_map(function (string $column) use ($db, $typeOf): string {
            $quoted = $db->quoteColumnName($column);
            return str_starts_with($typeOf[$column], 'geometry')
                ? "ST_GeomFromText($quoted, 4326)"
                : "$quoted::{$typeOf[$column]}";
        }, $columns);

        try {
            $inserted = $db->createCommand(sprintf('INSERT INTO %s (%s) SELECT %s FROM %s',
                $db->quoteTableName($table), implode(', ', array_map([$db, 'quoteColumnName'], $columns)),
                implode(', ', $select), $stage))->execute();
        } catch (DbException $e) {
            throw new \RuntimeException("$table: " . ($e->errorInfo[2] ?? $e->getMessage()), 0, $e);
        }

        if ($schema->sequenceName !== null && in_array('id', $columns, true)) {
            $db->createCommand(sprintf("SELECT setval(pg_get_serial_sequence(%s, 'id'), coalesce(max(id), 0) + 1, false) FROM %s",
                $db->quoteValue($db->quoteTableName($table)), $db->quoteTableName($table)))->execute();
        }
        return $inserted;
    }

    /** @param string[] $lines */
    private function copy(\PDO $pdo, string $stage, array $lines, string $fieldList, string $table): void
    {
        if (!$pdo->pgsqlCopyFromArray($stage, $lines, "\t", '\\\\N', $fieldList)) {
            throw new \RuntimeException("$table: COPY failed: " . implode(' ', $pdo->errorInfo()));
        }
    }

    /** One CSV cell in COPY text format: empty means NULL (data/schema/README.md). */
    private function copyField(?string $value): string
    {
        if ($value === null || $value === '') {
            return '\\N';
        }
        return strtr($value, ['\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r']);
    }

    private function hashPassword(string $plain): string
    {
        return $this->hashes[$plain] ??= Yii::$app->security->generatePasswordHash($plain);
    }

    private function presetDir(string $preset): string
    {
        $base = Yii::$app->params['dataOutDir'];
        if (!preg_match('~^([a-zA-Z]:)?[/\\\\]~', $base) && !str_starts_with($base, '@')) {
            $base = Yii::getAlias('@app') . '/' . $base;
        }
        $dir = Yii::getAlias($base) . '/' . $preset;
        if (!is_dir($dir)) {
            throw new \yii\console\Exception("No data for preset '$preset' in $dir. Generate it first: data\\run_data.bat $preset");
        }
        return $dir;
    }

    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            throw new \yii\console\Exception("Missing $path (regenerate the preset with data\\run_data.bat)");
        }
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertValidated(array $validation): void
    {
        $failed = array_filter($validation['checks'] ?? [], fn($c) => empty($c['pass']));
        if (!isset($validation['checks']) || $failed) {
            throw new \yii\console\Exception("The preset failed validation; refusing to seed:\n  - "
                . implode("\n  - ", array_column($failed, 'check')));
        }
    }

    private function authManager(): \yii\rbac\DbManager
    {
        /** @var \yii\rbac\DbManager $auth */
        $auth = Yii::$app->authManager;
        return $auth;
    }
}
