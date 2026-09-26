<?php

use yii\db\Migration;

/**
 * Mines; data/schema/mine.yaml. type adds 'mixed' to the brief's opencast/underground for GEM
 * "Underground & Surface" mines (data/HANDOFF.md). location is nullable (PLAN Q8) although the real
 * roster has a point for every mine.
 */
class m260927_000004_create_mine extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%mine}}', [
            'id' => $this->primaryKey(),
            'code' => $this->string(32)->notNull()->unique(),
            'name' => $this->string(160)->notNull(),
            'type' => $this->string(16)->notNull(),
            'subsidiary_id' => $this->integer()->notNull(),
            'area_id' => $this->integer()->null(),
            'location' => 'geometry(Point,4326) NULL',
            'boundary' => 'geometry(Polygon,4326) NULL',
            'status' => $this->string(16)->notNull()->defaultValue('active'),
            'district' => $this->string(160)->notNull(),
            'state' => $this->string(160)->notNull(),
            'region' => $this->string(32)->notNull(),
            'location_quality' => $this->string(32)->notNull(),
            'capacity_mtpa' => $this->decimal(8, 2)->null(),
            'gem_id' => $this->string(16)->null(),
        ]);
        $this->execute("ALTER TABLE {{%mine}}
            ADD CONSTRAINT mine_type_check CHECK (type IN ('opencast', 'underground', 'mixed')),
            ADD CONSTRAINT mine_status_check CHECK (status IN ('active', 'inactive')),
            ADD CONSTRAINT mine_location_quality_check
                CHECK (location_quality IN ('exact_gem', 'approx_gem', 'wikidata', 'district_centroid')),
            ADD CONSTRAINT mine_capacity_check CHECK (capacity_mtpa IS NULL OR capacity_mtpa >= 0)");
        $this->addForeignKey('fk_mine_subsidiary', '{{%mine}}', 'subsidiary_id', '{{%subsidiary}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_mine_area', '{{%mine}}', 'area_id', '{{%area}}', 'id', 'SET NULL', 'CASCADE');
        $this->createIndex('idx_mine_subsidiary', '{{%mine}}', 'subsidiary_id');
        $this->createIndex('idx_mine_area', '{{%mine}}', 'area_id');
        $this->createIndex('idx_mine_status', '{{%mine}}', 'status');
        $this->execute('CREATE INDEX idx_mine_location ON {{%mine}} USING GIST (location)');
        $this->execute('CREATE INDEX idx_mine_name_trgm ON {{%mine}} USING GIN (name gin_trgm_ops)');
    }

    public function safeDown()
    {
        $this->dropTable('{{%mine}}');
    }
}
