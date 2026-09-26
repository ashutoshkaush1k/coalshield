<?php

use yii\db\Migration;

/** Daily ambient air quality per mine; data/schema/env_reading.yaml. */
class m260928_000008_create_env_reading extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%env_reading}}', [
            'id' => $this->primaryKey(),
            'mine_id' => $this->integer()->notNull(),
            'date' => $this->date()->notNull(),
            'parameter' => $this->string(8)->notNull(),
            'value' => $this->decimal(10, 2)->notNull(),
            'unit' => $this->string(16)->notNull(),
            'data_kind' => $this->string(16)->notNull(),
            'station_id' => $this->integer()->null(),
            'station_distance_km' => $this->decimal(8, 1)->null(),
        ]);
        $this->execute("ALTER TABLE {{%env_reading}}
            ADD CONSTRAINT env_reading_parameter_check CHECK (parameter IN ('pm10', 'pm25', 'so2', 'no2')),
            ADD CONSTRAINT env_reading_kind_check CHECK (data_kind IN ('calibrated', 'synthetic'))");
        $this->addForeignKey('fk_env_reading_mine', '{{%env_reading}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('uq_env_reading_mine_date_parameter', '{{%env_reading}}', ['mine_id', 'date', 'parameter'], true);
    }

    public function safeDown()
    {
        $this->dropTable('{{%env_reading}}');
    }
}
