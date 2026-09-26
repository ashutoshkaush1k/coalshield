<?php

use yii\db\Migration;

/** Operating areas of a company; data/schema/area.yaml. */
class m260927_000003_create_area extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%area}}', [
            'id' => $this->primaryKey(),
            'subsidiary_id' => $this->integer()->notNull(),
            'code' => $this->string(64)->notNull()->unique(),
            'name' => $this->string(160)->notNull(),
            'district' => $this->string(160)->null(),
            'state' => $this->string(160)->null(),
        ]);
        $this->addForeignKey('fk_area_subsidiary', '{{%area}}', 'subsidiary_id', '{{%subsidiary}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx_area_subsidiary', '{{%area}}', 'subsidiary_id');
    }

    public function safeDown()
    {
        $this->dropTable('{{%area}}');
    }
}
