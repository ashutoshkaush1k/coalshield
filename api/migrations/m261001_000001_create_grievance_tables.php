<?php

use yii\db\Migration;

/**
 * Grievance handling (brief Phase 5); columns exactly as data/schema/grievance.yaml and
 * grievance_action.yaml, plus what the public endpoints need:
 *   - grievance_ticket_counter + next_grievance_ticket(year): GRV-YYYY-NNNNNN from a per-year
 *     counter that never goes below the highest ticket already in the table (seeded or not);
 *   - rate_limit: fixed-window hit counters for anonymous callers (by IP and endpoint);
 *   - observation.grievance_id gets its foreign key (a safety grievance creates an observation);
 *   - file.uploaded_by may be NULL for a file sent with a public grievance - and only then.
 * "Sensitive" (harassment, or against the mine head) is not stored: it is the expression
 * (category = 'harassment' OR against_mine_head), indexed, and applied by AccessRule.
 */
class m261001_000001_create_grievance_tables extends Migration
{
    public const CATEGORIES = ['wages', 'safety', 'working_conditions', 'harassment', 'environment', 'land_compensation', 'other'];
    public const STATUSES = ['received', 'acknowledged', 'under_investigation', 'resolved', 'closed', 'reopened'];

    public function safeUp()
    {
        $in = fn(array $values) => "'" . implode("', '", $values) . "'";

        $this->createTable('{{%grievance}}', [
            'id' => $this->primaryKey(),
            'ticket_no' => $this->string(16)->notNull(),
            'mine_id' => $this->integer()->notNull(),
            'submitter_type' => $this->string(16)->notNull(),
            'name' => $this->string(120)->null(),
            'contact' => $this->string(64)->null(),
            'is_anonymous' => $this->boolean()->notNull()->defaultValue(false),
            'category' => $this->string(24)->notNull(),
            'severity' => $this->string(8)->notNull(),
            'language' => $this->string(2)->notNull(),
            'description' => $this->text()->notNull(),
            'file_id' => $this->integer()->null(),
            'location' => 'geometry(Point, 4326) NULL',
            'status' => $this->string(24)->notNull()->defaultValue('received'),
            'assigned_to' => $this->integer()->null(),
            'sla_due_at' => 'timestamptz NOT NULL',
            'escalation_level' => $this->smallInteger()->notNull()->defaultValue(0),
            'against_mine_head' => $this->boolean()->notNull()->defaultValue(false),
            'resolution_note' => $this->text()->null(),
            'satisfaction_rating' => $this->smallInteger()->null(),
            'created_at' => 'timestamptz NOT NULL DEFAULT now()',
        ]);
        $this->execute("ALTER TABLE {{%grievance}}
            ADD CONSTRAINT grievance_ticket_check CHECK (ticket_no ~ '^GRV-[0-9]{4}-[0-9]{6}$'),
            ADD CONSTRAINT grievance_submitter_check CHECK (submitter_type IN ('employee', 'contract_worker', 'community', 'anonymous')),
            ADD CONSTRAINT grievance_category_check CHECK (category IN ({$in(self::CATEGORIES)})),
            ADD CONSTRAINT grievance_severity_check CHECK (severity IN ('low', 'medium', 'high')),
            ADD CONSTRAINT grievance_language_check CHECK (language IN ('en', 'hi', 'bn', 'or', 'te', 'mr')),
            ADD CONSTRAINT grievance_status_check CHECK (status IN ({$in(self::STATUSES)})),
            ADD CONSTRAINT grievance_anonymous_check CHECK (NOT is_anonymous OR (name IS NULL AND contact IS NULL)),
            ADD CONSTRAINT grievance_escalation_check CHECK (escalation_level BETWEEN 0 AND 3),
            ADD CONSTRAINT grievance_rating_check CHECK (satisfaction_rating IS NULL OR satisfaction_rating BETWEEN 1 AND 5)");
        $this->createIndex('uq_grievance_ticket', '{{%grievance}}', 'ticket_no', true);
        $this->addForeignKey('fk_grievance_mine', '{{%grievance}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_grievance_file', '{{%grievance}}', 'file_id', '{{%file}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_grievance_assigned_to', '{{%grievance}}', 'assigned_to', '{{%user}}', 'id', 'SET NULL', 'CASCADE');
        $this->createIndex('idx_grievance_mine_status', '{{%grievance}}', ['mine_id', 'status']);
        $this->createIndex('idx_grievance_status_sla', '{{%grievance}}', ['status', 'sla_due_at']);
        $this->createIndex('idx_grievance_category', '{{%grievance}}', ['category', 'created_at']);
        $this->createIndex('idx_grievance_created', '{{%grievance}}', 'created_at');
        $this->execute("CREATE INDEX idx_grievance_sensitive ON {{%grievance}} (mine_id) WHERE category = 'harassment' OR against_mine_head");

        $this->createTable('{{%grievance_action}}', [
            'id' => $this->primaryKey(),
            'grievance_id' => $this->integer()->notNull(),
            'action' => $this->string(16)->notNull(),
            'from_status' => $this->string(24)->null(),
            'to_status' => $this->string(24)->notNull(),
            'note' => $this->text()->null(),
            'actor_id' => $this->integer()->null(),
            'created_at' => 'timestamptz NOT NULL DEFAULT now()',
        ]);
        $this->execute("ALTER TABLE {{%grievance_action}}
            ADD CONSTRAINT grievance_action_check CHECK (action IN ('submit', 'acknowledge', 'investigate', 'resolve', 'close', 'reopen', 'escalate', 'assign')),
            ADD CONSTRAINT grievance_action_to_check CHECK (to_status IN ({$in(self::STATUSES)}))");
        $this->addForeignKey('fk_grievance_action_grievance', '{{%grievance_action}}', 'grievance_id', '{{%grievance}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_grievance_action_actor', '{{%grievance_action}}', 'actor_id', '{{%user}}', 'id', 'SET NULL', 'CASCADE');
        $this->createIndex('idx_grievance_action_grievance', '{{%grievance_action}}', ['grievance_id', 'created_at']);
        $this->createIndex('idx_grievance_action_escalate', '{{%grievance_action}}', ['action', 'created_at']);

        $this->createTable('{{%grievance_ticket_counter}}', [
            'year' => $this->integer()->notNull(),
            'last' => $this->integer()->notNull(),
            'PRIMARY KEY (year)',
        ]);
        $this->execute(<<<'SQL'
            CREATE FUNCTION next_grievance_ticket(p_year integer) RETURNS text
            LANGUAGE plpgsql AS $$
            DECLARE
              v_prefix text := 'GRV-' || p_year || '-';
              v_max integer;
              v_next integer;
            BEGIN
              SELECT coalesce(max(substr(ticket_no, 10)::integer), 0) INTO v_max
                FROM grievance WHERE ticket_no LIKE v_prefix || '%';
              INSERT INTO grievance_ticket_counter (year, last) VALUES (p_year, v_max + 1)
                ON CONFLICT (year) DO UPDATE SET last = greatest(grievance_ticket_counter.last, v_max) + 1
                RETURNING last INTO v_next;
              IF v_next > 999999 THEN
                RAISE EXCEPTION 'grievance tickets for % exhausted', p_year;
              END IF;
              RETURN v_prefix || lpad(v_next::text, 6, '0');
            END $$;
            SQL);

        $this->createTable('{{%rate_limit}}', [
            'bucket' => $this->string(96)->notNull(),
            'window_start' => 'timestamptz NOT NULL',
            'hits' => $this->integer()->notNull()->defaultValue(0),
            'PRIMARY KEY (bucket, window_start)',
        ]);
        $this->createIndex('idx_rate_limit_window', '{{%rate_limit}}', 'window_start');

        // Observations already carry grievance_id (Phase 2); give it its foreign key now.
        $dangling = $this->db->createCommand('UPDATE {{%observation}} SET grievance_id = NULL
            WHERE grievance_id IS NOT NULL AND grievance_id NOT IN (SELECT id FROM {{%grievance}})')->execute();
        if ($dangling) {
            echo "    > cleared $dangling observation.grievance_id value(s) without a grievance (re-seed to restore them)\n";
        }
        $this->addForeignKey('fk_observation_grievance', '{{%observation}}', 'grievance_id', '{{%grievance}}', 'id', 'SET NULL', 'CASCADE');

        // A file sent with a public grievance has no uploader account.
        $this->execute('ALTER TABLE {{%file}} ALTER COLUMN uploaded_by DROP NOT NULL');
        $this->execute("ALTER TABLE {{%file}} ADD CONSTRAINT file_uploader_check CHECK (uploaded_by IS NOT NULL OR entity = 'grievance')");
    }

    public function safeDown()
    {
        $this->execute('ALTER TABLE {{%file}} DROP CONSTRAINT file_uploader_check');
        $this->execute("DELETE FROM {{%file}} WHERE uploaded_by IS NULL");
        $this->execute('ALTER TABLE {{%file}} ALTER COLUMN uploaded_by SET NOT NULL');
        $this->dropForeignKey('fk_observation_grievance', '{{%observation}}');
        $this->dropTable('{{%rate_limit}}');
        $this->execute('DROP FUNCTION next_grievance_ticket(integer)');
        $this->dropTable('{{%grievance_ticket_counter}}');
        $this->dropTable('{{%grievance_action}}');
        $this->dropTable('{{%grievance}}');
    }
}
