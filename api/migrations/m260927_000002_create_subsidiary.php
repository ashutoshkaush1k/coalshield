<?php

use yii\db\Migration;

/** Operating companies; data/schema/subsidiary.yaml. */
class m260927_000002_create_subsidiary extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%subsidiary}}', [
            'id' => $this->primaryKey(),
            'code' => $this->string(32)->notNull()->unique(),
            'name' => $this->string(160)->notNull(),
            'type' => $this->string(16)->notNull(),
            'parent_id' => $this->integer()->null(),
        ]);
        $this->execute("ALTER TABLE {{%subsidiary}} ADD CONSTRAINT subsidiary_type_check
            CHECK (type IN ('holding', 'subsidiary', 'psu', 'state_jv', 'private'))");
        $this->addForeignKey('fk_subsidiary_parent', '{{%subsidiary}}', 'parent_id', '{{%subsidiary}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx_subsidiary_parent', '{{%subsidiary}}', 'parent_id');
    }

    public function safeDown()
    {
        $this->dropTable('{{%subsidiary}}');
    }
}
