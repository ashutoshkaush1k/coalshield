<?php

use yii\db\Migration;

/**
 * compliance_score: append-only score history (the trend line); a row is written whenever a
 * mine's score moves (vision run, sensor ingest, violation resolved). Weights are stored with each
 * point so a retuned weight never rewrites the past.
 *
 * api_key: machine credentials for POST /v1/sensor-readings/ingest. Only the SHA-256 of a key is
 * stored; `yii api-key/issue` prints the key once.
 */
class m260928_000010_create_compliance_score_and_api_key extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%compliance_score}}', [
            'id' => $this->bigPrimaryKey(),
            'mine_id' => $this->integer()->notNull(),
            'score' => $this->decimal(5, 1)->notNull(),
            'risk_level' => $this->string(8)->notNull(),
            'violation_count' => $this->integer()->notNull(),
            'breach_count' => $this->integer()->notNull(),
            'weight_ppe' => $this->decimal(6, 2)->notNull(),
            'weight_env' => $this->decimal(6, 2)->notNull(),
            'computed_at' => 'timestamptz NOT NULL DEFAULT now()',
        ]);
        $this->execute("ALTER TABLE {{%compliance_score}}
            ADD CONSTRAINT compliance_score_range_check CHECK (score >= 0 AND score <= 100),
            ADD CONSTRAINT compliance_score_risk_check CHECK (risk_level IN ('low', 'medium', 'high'))");
        $this->addForeignKey('fk_compliance_score_mine', '{{%compliance_score}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx_compliance_score_mine_time', '{{%compliance_score}}', ['mine_id', 'computed_at']);

        $this->createTable('{{%api_key}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(64)->notNull()->unique(),
            'key_hash' => $this->char(64)->notNull()->unique(),
            'scope' => $this->string(32)->notNull(),
            'created_at' => 'timestamptz NOT NULL DEFAULT now()',
            'last_used_at' => 'timestamptz NULL',
            'revoked_at' => 'timestamptz NULL',
        ]);
        $this->execute("ALTER TABLE {{%api_key}} ADD CONSTRAINT api_key_scope_check CHECK (scope IN ('sensor_ingest'))");
    }

    public function safeDown()
    {
        $this->dropTable('{{%api_key}}');
        $this->dropTable('{{%compliance_score}}');
    }
}
