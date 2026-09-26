<?php

use yii\db\Migration;

/** Stored file metadata; data/schema/file.yaml. */
class m260927_000007_create_file extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%file}}', [
            'id' => $this->primaryKey(),
            'path' => $this->string(512)->notNull(),
            'mime' => $this->string(64)->notNull(),
            'size' => $this->bigInteger()->notNull(),
            'sha256' => $this->char(64)->notNull(),
            'uploaded_by' => $this->integer()->notNull(),
            'entity' => $this->string(64)->notNull(),
            'entity_id' => $this->bigInteger()->notNull(),
            'created_at' => 'timestamptz NOT NULL DEFAULT now()',
        ]);
        $this->execute("ALTER TABLE {{%file}}
            ADD CONSTRAINT file_size_check CHECK (size >= 0),
            ADD CONSTRAINT file_sha256_check CHECK (sha256 ~ '^[0-9a-f]{64}$')");
        $this->addForeignKey('fk_file_uploaded_by', '{{%file}}', 'uploaded_by', '{{%user}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx_file_uploaded_by', '{{%file}}', 'uploaded_by');
        $this->createIndex('idx_file_entity', '{{%file}}', ['entity', 'entity_id']);
    }

    public function safeDown()
    {
        $this->dropTable('{{%file}}');
    }
}
