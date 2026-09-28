<?php

declare(strict_types=1);

namespace app\commands;

use app\components\AuditChain;
use app\components\CacheReset;
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
        // After incident: an incident's reporting task references it.
        'obligation' => 5, 'obligation_applicability' => 5, 'obligation_task' => 5, 'obligation_submission' => 5,
    ];

    /** CSV column => table column, where they differ (HANDOFF conflict C11). */
    private const RENAMED = ['user' => ['password' => 'password_hash'], 'grievance' => ['tracking_code' => 'tracking_code_hash']];

    /**
     * Columns only the app writes (nullable or defaulted), which the data track does not generate:
     * the field app's capture details (Phase 7B). Every other table column must be in the CSV.
     */
    private const APP_ONLY = [
        'inspection' => ['client_uuid'],
        'observation' => ['client_uuid', 'checklist_item', 'checklist_version', 'obligation_code', 'note', 'recorded_at', 'received_at',
            'location', 'location_accuracy_m', 'location_status', 'distance_m', 'geo_flag', 'clock_skew_s', 'clock_flag', 'recorded_by'],
    ];

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
            // Runtime state of the public grievance endpoints: rate-limit windows and ticket
            // counters (the counter never goes below the highest ticket loaded). Phase 7: what the
            // jobs derived from the data (findings, daily snapshots, predictions); job_run is kept.
            foreach (['rate_limit', 'grievance_ticket_counter', 'anomaly_flag', 'mine_risk_snapshot', 'mine_risk_prediction', 'field_sync'] as $runtime) {
                if ($db->getTableSchema($runtime, true) !== null) {
                    $truncate[] = $runtime;
                }
            }
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
            // Open violations per mine right after seeding: the baseline the simulator pre-flight
            // (GET /v1/admin/baseline-check) compares live scores with.
            $baseline = isset($loaded['violation'])
                ? array_map('intval', $db->createCommand('SELECT mine_id, count(*) FROM violation WHERE NOT resolved GROUP BY mine_id ORDER BY mine_id')->queryAll(\PDO::FETCH_KEY_PAIR))
                : [];
            AuditChain::append('seed', null, 'seed', null, [
                'preset' => $preset,
                'roster' => $manifest['roster'] ?? null,
                'generator_seed' => $manifest['seed'] ?? null,
                'tables' => $loaded,
                'baseline_open_violations' => (object) $baseline,
            ], $db, null, 'seed');
            $history = $this->backfillHistory($db, array_keys($loaded));
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->stderr('Seed failed, nothing was changed: ' . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        CacheReset::flush();   // roles were re-assigned and new tables may have appeared

        $this->stdout(sprintf("Seeded preset '%s' (%d tables, %d role assignments, %d history entries in the audit chain) in %.1fs.\n",
            $preset, count($loaded), $assigned, $history, microtime(true) - $started), Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * The seeded records' own history, written into the audit chain with source = seed_history,
     * their original timestamps and actors, so a mine's audit trail reads like the months the data
     * covers rather than starting empty. One INSERT ... SELECT ordered by time: every row still
     * passes the audit_log_chain trigger (row triggers see the rows inserted before them in the
     * same statement), so the chain is built by the same hash function as every other entry.
     *
     * Events (only where the data holds a timestamp; nothing is invented):
     *   violation      recorded (detected_at; inspector for inspection findings), resolved
     *                  (resolved_at; the mine head who closed its corrective action)
     *   corrective_action  recorded (created_at, created_by), resolved (resolved_at, created_by)
     *   incident       reported (reported_at)
     *   inspection     visited (visited_at, inspector), closed (closed_at, inspector)
     *   alert          raised (created_at). Acknowledgements carry no time in the data.
     *   contract       started (start_date)
     *   contractor_compliance_doc  uploaded (the file's created_at and uploaded_by)
     * Directives: the seeded alerts contain none (government directives only arise in the app).
     *
     * @param string[] $loaded tables loaded by this seed
     */
    private function backfillHistory(Connection $db, array $loaded): int
    {
        $has = fn(string ...$tables) => !array_diff($tables, $loaded);
        $parts = [];
        if ($has('violation', 'corrective_action')) {
            $parts[] = "SELECT 'violation' AS entity, v.id AS entity_id, 'recorded' AS action, NULL::jsonb AS old_values,
                    jsonb_build_object('violation_type', v.violation_type, 'category', v.category, 'detected_by', v.source,
                                       'confidence', v.confidence, 'inspection_id', v.inspection_id) AS new_values,
                    i.inspector_id AS user_id, v.detected_at AS at, v.mine_id, 1 AS ord
               FROM violation v LEFT JOIN inspection i ON i.id = v.inspection_id";
            $parts[] = "SELECT 'violation', v.id, 'resolved', jsonb_build_object('resolved', false), jsonb_build_object('resolved', true),
                    (SELECT ca.created_by FROM corrective_action ca WHERE ca.violation_id = v.id AND ca.status = 'resolved'
                      ORDER BY ca.resolved_at LIMIT 1),
                    v.resolved_at, v.mine_id, 4
               FROM violation v WHERE v.resolved";
            $parts[] = "SELECT 'corrective_action', ca.id, 'recorded', NULL::jsonb,
                    jsonb_build_object('violation_id', ca.violation_id, 'description', ca.description,
                                       'due_at', to_char(ca.due_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'), 'status', 'open'),
                    ca.created_by, ca.created_at, ca.mine_id, 2
               FROM corrective_action ca";
            $parts[] = "SELECT 'corrective_action', ca.id, 'resolved', jsonb_build_object('status', 'open'),
                    jsonb_build_object('status', 'resolved', 'proof_image_path', ca.proof_image_path),
                    ca.created_by, ca.resolved_at, ca.mine_id, 3
               FROM corrective_action ca WHERE ca.status = 'resolved'";
        }
        if ($has('incident')) {
            $parts[] = "SELECT 'incident', n.id, 'reported', NULL::jsonb,
                    jsonb_build_object('type', n.type, 'severity', n.severity, 'persons_affected', n.persons_affected,
                                       'description_code', n.description_code, 'obligation_code', n.obligation_code,
                                       'reported_within_48h', n.reported_within_48h, 'related_violation_id', n.related_violation_id,
                                       'occurred_at', to_char(n.occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')),
                    NULL::integer, n.reported_at, n.mine_id, 5
               FROM incident n";
        }
        if ($has('inspection')) {
            $parts[] = "SELECT 'inspection', s.id, 'visited', jsonb_build_object('status', 'scheduled'),
                    jsonb_build_object('status', 'visited', 'inspection_type', s.inspection_type, 'scheduled_for', s.scheduled_for),
                    s.inspector_id, s.visited_at, s.mine_id, 0
               FROM inspection s WHERE s.visited_at IS NOT NULL";
            $parts[] = "SELECT 'inspection', s.id, 'closed', jsonb_build_object('status', 'visited'),
                    jsonb_build_object('status', 'closed', 'findings_count', s.findings_count, 'is_locked', s.is_locked),
                    s.inspector_id, s.closed_at, s.mine_id, 6
               FROM inspection s WHERE s.closed_at IS NOT NULL";
        }
        if ($has('contract', 'contractor')) {
            $parts[] = "SELECT 'contract', c.id, 'started', NULL::jsonb,
                    jsonb_build_object('contractor_id', c.contractor_id, 'work_type', c.work_type, 'work_order_no', c.work_order_no,
                                       'max_workers', c.max_workers, 'end_date', c.end_date),
                    NULL::integer, c.start_date::timestamptz, c.mine_id, 0
               FROM contract c";
        }
        if ($has('contractor_compliance_doc', 'file', 'contract')) {
            $parts[] = "SELECT 'contractor_compliance_doc', d.id, 'uploaded', NULL::jsonb,
                    jsonb_build_object('contract_id', d.contract_id, 'doc_type', d.doc_type, 'period', d.period,
                                       'file_id', d.file_id, 'verified', d.verified),
                    f.uploaded_by, f.created_at, c.mine_id, 2
               FROM contractor_compliance_doc d JOIN file f ON f.id = d.file_id JOIN contract c ON c.id = d.contract_id";
        }
        if ($has('daily_production')) {
            $parts[] = "SELECT 'daily_production', p.id, 'submitted', jsonb_build_object('status', 'draft'),
                    jsonb_build_object('status', 'submitted', 'date', p.date, 'shift', p.shift, 'coal_target_t', p.coal_target_t,
                                       'coal_actual_t', p.coal_actual_t),
                    p.submitted_by, p.submitted_at, p.mine_id, 1
               FROM daily_production p WHERE p.submitted_at IS NOT NULL";
        }
        if ($has('production_edit_log', 'daily_production')) {
            $parts[] = "SELECT 'daily_production', e.production_id, 'edited', jsonb_build_object(e.field, e.old_value),
                    jsonb_build_object(e.field, e.new_value, 'reason', e.reason, 'edit_log_id', e.id),
                    e.edited_by, e.edited_at, p.mine_id, 2
               FROM production_edit_log e JOIN daily_production p ON p.id = e.production_id";
        }
        if ($has('production_detail_request')) {
            $parts[] = "SELECT 'production_detail_request', r.id, 'requested', NULL::jsonb,
                    jsonb_build_object('date_from', r.date_from, 'date_to', r.date_to, 'reason', r.reason,
                                       'due_at', to_char(r.due_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'), 'status', 'pending'),
                    r.requested_by, r.created_at, r.mine_id, 3
               FROM production_detail_request r";
            $parts[] = "SELECT 'production_detail_request', r.id, 'responded', jsonb_build_object('status', 'pending'),
                    jsonb_build_object('status', 'submitted', 'response_note', r.response_note, 'response_file_id', r.response_file_id),
                    r.responded_by, r.responded_at, r.mine_id, 4
               FROM production_detail_request r WHERE r.responded_at IS NOT NULL";
        }
        if ($has('grievance', 'grievance_action')) {
            // The timeline only - never the complainant's name or contact.
            $parts[] = "SELECT 'grievance', ga.grievance_id, ga.action, CASE WHEN ga.from_status IS NULL THEN NULL ELSE jsonb_build_object('status', ga.from_status) END,
                    jsonb_build_object('status', ga.to_status, 'category', g.category, 'ticket_no', g.ticket_no),
                    ga.actor_id, ga.created_at, g.mine_id, 3
               FROM grievance_action ga JOIN grievance g ON g.id = ga.grievance_id";
        }
        if ($has('obligation_submission', 'obligation_task', 'obligation')) {
            $parts[] = "SELECT 'obligation_submission', s.id, 'submitted', NULL::jsonb,
                    jsonb_build_object('task_id', s.task_id, 'obligation', o.code, 'period', t.period, 'file_id', s.file_id),
                    s.submitted_by, s.submitted_at, t.mine_id, 5
               FROM obligation_submission s JOIN obligation_task t ON t.id = s.task_id JOIN obligation o ON o.id = t.obligation_id";
            $parts[] = "SELECT 'obligation_submission', s.id, CASE WHEN s.status = 'accepted' THEN 'accepted' ELSE 'rejected' END,
                    jsonb_build_object('status', 'pending'),
                    jsonb_build_object('status', s.status, 'review_note', s.review_note, 'obligation', o.code, 'period', t.period),
                    s.reviewed_by, s.reviewed_at, t.mine_id, 6
               FROM obligation_submission s JOIN obligation_task t ON t.id = s.task_id JOIN obligation o ON o.id = t.obligation_id
              WHERE s.reviewed_at IS NOT NULL";
        }
        if ($has('alert')) {
            $parts[] = "SELECT 'alert', a.id, 'raised', NULL::jsonb,
                    jsonb_build_object('code', a.code, 'params', a.params, 'severity', a.severity, 'entity_type', a.entity_type,
                                       'entity_id', a.entity_id),
                    NULL::integer, a.created_at, a.mine_id, 7
               FROM alert a";
        }
        if ($parts === []) {
            return 0;
        }
        return $db->createCommand(
            "INSERT INTO audit_log (entity, entity_id, action, old_values, new_values, user_id, ip, created_at, mine_id, source)
             SELECT e.entity, e.entity_id, e.action, e.old_values, e.new_values, e.user_id, NULL, e.at, e.mine_id, 'seed_history'
               FROM (" . implode("\n UNION ALL \n", $parts) . ") e
              WHERE e.at IS NOT NULL
              ORDER BY e.at, e.ord, e.entity_id"
        )->execute();
    }

    /** yii seed/status - exit 0 and print the preset when the database has been seeded, else 1. */
    public function actionStatus(): int
    {
        try {
            $values = Yii::$app->db->createCommand("SELECT new_values FROM audit_log WHERE entity = 'seed' AND action = 'seed' ORDER BY id DESC LIMIT 1")->queryScalar();
        } catch (\Throwable) {
            $this->stdout("not migrated\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        if (!$values) {
            $this->stdout("not seeded\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout('seeded: ' . (json_decode($values, true)['preset'] ?? '?') . "\n");
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
            $missing = array_diff($dbColumns, $columns, self::APP_ONLY[$table] ?? []);
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
            // Grievance tracking codes (demo codes in the CSV) are stored only as an HMAC.
            $trackingIndex = array_search('tracking_code_hash', $columns, true);
            $count = 0;
            $buffer = [];
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;   // blank line
                }
                if (count($row) !== count($columns)) {
                    throw new \RuntimeException(sprintf('%s: row %d has %d fields, expected %d', $table, $count + 2, count($row), count($columns)));
                }
                if ($trackingIndex !== false) {
                    $row[$trackingIndex] = \app\services\GrievanceService::trackingHash($row[$trackingIndex]);
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
