<?php

use yii\db\Migration;

/**
 * Tamper-evident, append-only audit log (brief rule 4).
 *
 * - audit_row_hash(): SHA-256 over prev_hash || canonical JSON of the row (jsonb text form is
 *   deterministic; created_at is formatted to microseconds in UTC). Used by the insert trigger and by
 *   `yii audit/verify`, so writer and verifier cannot drift.
 * - audit_log_chain (BEFORE INSERT): takes a transaction-level advisory lock, then assigns the id,
 *   prev_hash and row_hash. Because the lock is held until commit, ids follow commit order and the
 *   chain stays linear under concurrent writers.
 * - audit_log_immutable (BEFORE UPDATE OR DELETE): rejects changes. TRUNCATE (used only by
 *   `yii seed`, which then writes a fresh genesis entry) is not affected.
 */
class m260927_000006_create_audit_log extends Migration
{
    public function safeUp()
    {
        $this->execute('CREATE SEQUENCE audit_log_id_seq');
        $this->createTable('{{%audit_log}}', [
            'id' => 'bigint PRIMARY KEY',
            'entity' => $this->string(64)->notNull(),
            'entity_id' => $this->bigInteger()->null(),
            'action' => $this->string(32)->notNull(),
            'old_values' => 'jsonb NULL',
            'new_values' => 'jsonb NULL',
            'user_id' => $this->integer()->null(),
            'ip' => $this->string(45)->null(),
            'created_at' => 'timestamptz NOT NULL',
            'prev_hash' => $this->char(64)->notNull(),
            'row_hash' => $this->char(64)->notNull(),
        ]);
        $this->execute('ALTER SEQUENCE audit_log_id_seq OWNED BY audit_log.id');
        $this->execute("ALTER TABLE audit_log ADD CONSTRAINT audit_log_action_check CHECK (action ~ '^[a-z_]+$')");
        $this->addForeignKey('fk_audit_log_user', '{{%audit_log}}', 'user_id', '{{%user}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx_audit_log_entity', '{{%audit_log}}', ['entity', 'entity_id']);
        $this->createIndex('idx_audit_log_user', '{{%audit_log}}', 'user_id');
        $this->createIndex('idx_audit_log_created', '{{%audit_log}}', 'created_at');

        $this->execute(<<<'SQL'
            CREATE FUNCTION audit_row_hash(p_prev text, p_id bigint, p_entity text, p_entity_id bigint,
                                           p_action text, p_old jsonb, p_new jsonb, p_user_id integer,
                                           p_ip text, p_created_at timestamptz)
            RETURNS text LANGUAGE sql STABLE AS $$
              SELECT encode(digest(p_prev || jsonb_build_object(
                  'id', p_id, 'entity', p_entity, 'entity_id', p_entity_id, 'action', p_action,
                  'old_values', p_old, 'new_values', p_new, 'user_id', p_user_id, 'ip', p_ip,
                  'created_at', to_char(p_created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"')
                )::text, 'sha256'), 'hex')
            $$
            SQL);
        $this->execute(<<<'SQL'
            CREATE FUNCTION audit_log_chain() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              last_hash text;
            BEGIN
              PERFORM pg_advisory_xact_lock(hashtext('audit_log_chain'));
              NEW.id := nextval('audit_log_id_seq');
              NEW.created_at := coalesce(NEW.created_at, clock_timestamp());
              SELECT row_hash INTO last_hash FROM audit_log ORDER BY id DESC LIMIT 1;
              NEW.prev_hash := coalesce(last_hash, repeat('0', 64));
              NEW.row_hash := audit_row_hash(NEW.prev_hash, NEW.id, NEW.entity, NEW.entity_id, NEW.action,
                                             NEW.old_values, NEW.new_values, NEW.user_id, NEW.ip, NEW.created_at);
              RETURN NEW;
            END
            $$
            SQL);
        $this->execute(<<<'SQL'
            CREATE FUNCTION audit_log_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'audit_log is append-only' USING ERRCODE = '42501';
            END
            $$
            SQL);
        $this->execute('CREATE TRIGGER audit_log_chain BEFORE INSERT ON audit_log FOR EACH ROW EXECUTE FUNCTION audit_log_chain()');
        $this->execute('CREATE TRIGGER audit_log_immutable BEFORE UPDATE OR DELETE ON audit_log FOR EACH ROW EXECUTE FUNCTION audit_log_immutable()');
    }

    public function safeDown()
    {
        $this->dropTable('{{%audit_log}}');   // drops its triggers and the owned sequence
        $this->execute('DROP FUNCTION audit_log_immutable()');
        $this->execute('DROP FUNCTION audit_log_chain()');
        $this->execute('DROP FUNCTION audit_row_hash(text, bigint, text, bigint, text, jsonb, jsonb, integer, text, timestamptz)');
    }
}
