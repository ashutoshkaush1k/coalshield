<?php

use yii\db\Migration;

/**
 * Accounts; data/schema/user.yaml (password -> password_hash). Lower-case roles. The CHECKs keep a
 * role's scope columns consistent, so ScopedActiveQuery can trust them:
 *   government, inspector -> no subsidiary, no mine
 *   corporate             -> a subsidiary, no mine
 *   mine_head             -> a mine
 */
class m260927_000005_create_user extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%user}}', [
            'id' => $this->primaryKey(),
            'email' => $this->string(254)->notNull(),
            'password_hash' => $this->string(255)->notNull(),
            'full_name' => $this->string(160)->notNull(),
            'role' => $this->string(16)->notNull(),
            'subsidiary_id' => $this->integer()->null(),
            'area_id' => $this->integer()->null(),
            'mine_id' => $this->integer()->null(),
            'preferred_language' => $this->string(2)->notNull()->defaultValue('en'),
            'status' => $this->string(16)->notNull()->defaultValue('active'),
            'created_at' => 'timestamptz NOT NULL DEFAULT now()',
            'updated_at' => 'timestamptz NOT NULL DEFAULT now()',
        ]);
        $this->execute(<<<'SQL'
            ALTER TABLE "user"
              ADD CONSTRAINT user_role_check CHECK (role IN ('government', 'corporate', 'mine_head', 'inspector')),
              ADD CONSTRAINT user_status_check CHECK (status IN ('active', 'inactive')),
              ADD CONSTRAINT user_language_check CHECK (preferred_language IN ('en', 'hi', 'bn', 'or', 'te', 'mr')),
              ADD CONSTRAINT user_email_lower_check CHECK (email = lower(email)),
              ADD CONSTRAINT user_scope_check CHECK (
                   (role IN ('government', 'inspector') AND subsidiary_id IS NULL AND mine_id IS NULL)
                OR (role = 'corporate' AND subsidiary_id IS NOT NULL AND mine_id IS NULL)
                OR (role = 'mine_head' AND mine_id IS NOT NULL))
            SQL);
        $this->createIndex('uq_user_email', '{{%user}}', 'email', true);
        $this->addForeignKey('fk_user_subsidiary', '{{%user}}', 'subsidiary_id', '{{%subsidiary}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_user_area', '{{%user}}', 'area_id', '{{%area}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk_user_mine', '{{%user}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx_user_subsidiary', '{{%user}}', 'subsidiary_id');
        $this->createIndex('idx_user_area', '{{%user}}', 'area_id');
        $this->createIndex('idx_user_mine', '{{%user}}', 'mine_id');
        $this->createIndex('idx_user_role', '{{%user}}', 'role');
    }

    public function safeDown()
    {
        $this->dropTable('{{%user}}');
    }
}
