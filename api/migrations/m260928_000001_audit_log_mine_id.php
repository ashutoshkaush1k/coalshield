<?php

use yii\db\Migration;

/**
 * audit_log.mine_id: which mine an entry belongs to, so the audit trail can be scoped like every
 * other record (a mine head reads its own mine's history). No foreign key: the history must
 * outlive the records it describes (a deleted mine keeps its trail). The column is part of the hash, so
 * audit_row_hash() gains a parameter and existing rows are re-chained once, in id order.
 */
class m260928_000001_audit_log_mine_id extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%audit_log}}', 'mine_id', $this->integer()->null());
        $this->createIndex('idx_audit_log_mine', '{{%audit_log}}', ['mine_id', 'id']);
        $this->installFunctions(true);
        $this->rechain('audit_row_hash(prev, id, entity, entity_id, action, old_values, new_values, user_id, ip, created_at, mine_id)');
    }

    public function safeDown()
    {
        $this->installFunctions(false);
        $this->dropColumn('{{%audit_log}}', 'mine_id');
        $this->rechain('audit_row_hash(prev, id, entity, entity_id, action, old_values, new_values, user_id, ip, created_at)');
    }

    private function installFunctions(bool $withMine): void
    {
        $this->execute('DROP FUNCTION IF EXISTS audit_row_hash(text, bigint, text, bigint, text, jsonb, jsonb, integer, text, timestamptz, integer)');
        if ($withMine) {
            $this->execute(<<<'SQL'
                CREATE FUNCTION audit_row_hash(p_prev text, p_id bigint, p_entity text, p_entity_id bigint,
                                               p_action text, p_old jsonb, p_new jsonb, p_user_id integer,
                                               p_ip text, p_created_at timestamptz, p_mine_id integer)
                RETURNS text LANGUAGE sql STABLE AS $$
                  SELECT encode(digest(p_prev || jsonb_build_object(
                      'id', p_id, 'entity', p_entity, 'entity_id', p_entity_id, 'action', p_action,
                      'old_values', p_old, 'new_values', p_new, 'user_id', p_user_id, 'ip', p_ip,
                      'created_at', to_char(p_created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
                      'mine_id', p_mine_id
                    )::text, 'sha256'), 'hex')
                $$
                SQL);
        }
        $args = $withMine ? ', NEW.mine_id' : '';
        $this->execute(<<<SQL
            CREATE OR REPLACE FUNCTION audit_log_chain() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE
              last_hash text;
            BEGIN
              PERFORM pg_advisory_xact_lock(hashtext('audit_log_chain'));
              NEW.id := nextval('audit_log_id_seq');
              NEW.created_at := coalesce(NEW.created_at, clock_timestamp());
              SELECT row_hash INTO last_hash FROM audit_log ORDER BY id DESC LIMIT 1;
              NEW.prev_hash := coalesce(last_hash, repeat('0', 64));
              NEW.row_hash := audit_row_hash(NEW.prev_hash, NEW.id, NEW.entity, NEW.entity_id, NEW.action,
                                             NEW.old_values, NEW.new_values, NEW.user_id, NEW.ip, NEW.created_at$args);
              RETURN NEW;
            END
            \$\$
            SQL);
    }

    /** Recompute prev_hash / row_hash for existing rows in id order (the guard trigger is paused). */
    private function rechain(string $hashCall): void
    {
        $this->execute('ALTER TABLE audit_log DISABLE TRIGGER audit_log_immutable');
        $this->execute(<<<SQL
            DO \$\$
            DECLARE
              r record;
              prev text := repeat('0', 64);
            BEGIN
              FOR r IN SELECT * FROM audit_log ORDER BY id LOOP
                UPDATE audit_log SET prev_hash = prev,
                       row_hash = (SELECT $hashCall FROM audit_log WHERE id = r.id)
                 WHERE id = r.id
                RETURNING row_hash INTO prev;
              END LOOP;
            END
            \$\$
            SQL);
        $this->execute('ALTER TABLE audit_log ENABLE TRIGGER audit_log_immutable');
    }
}
