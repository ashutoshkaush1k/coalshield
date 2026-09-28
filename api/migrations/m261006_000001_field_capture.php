<?php

use yii\db\Migration;

/**
 * Phase 7B: the offline field app.
 *
 *   observation   gains what a phone capture carries: the client's id (unique - a replayed sync
 *                 never creates a second row), the checklist item and obligation it answers, a
 *                 note, the device's time as reported and the server's receipt time, the GPS point
 *                 with its accuracy (or why there is none), the distance from the mine and the
 *                 geo / clock flags (flags, never rejections), and who recorded it
 *   inspection    gains the client's id of the visit that created it, and the type `self` (a mine
 *                 head's own inspection from the field app)
 *   field_sync    one row per synced visit, capture or photo: the client id, the account, and the
 *                 result returned - a retry of the same id returns it again instead of acting twice
 */
class m261006_000001_field_capture extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%observation}}', 'client_uuid', 'uuid NULL');
        $this->addColumn('{{%observation}}', 'checklist_item', $this->string(32)->null());
        $this->addColumn('{{%observation}}', 'checklist_version', $this->string(16)->null());
        $this->addColumn('{{%observation}}', 'obligation_code', $this->string(16)->null());
        $this->addColumn('{{%observation}}', 'note', $this->text()->null());
        $this->addColumn('{{%observation}}', 'recorded_at', 'timestamptz NULL');
        $this->addColumn('{{%observation}}', 'received_at', 'timestamptz NULL');
        $this->addColumn('{{%observation}}', 'location', 'geometry(Point,4326) NULL');
        $this->addColumn('{{%observation}}', 'location_accuracy_m', $this->decimal(9, 1)->null());
        $this->addColumn('{{%observation}}', 'location_status', $this->string(24)->null());
        $this->addColumn('{{%observation}}', 'distance_m', $this->integer()->null());
        $this->addColumn('{{%observation}}', 'geo_flag', $this->boolean()->notNull()->defaultValue(false));
        $this->addColumn('{{%observation}}', 'clock_skew_s', $this->integer()->null());
        $this->addColumn('{{%observation}}', 'clock_flag', $this->boolean()->notNull()->defaultValue(false));
        $this->addColumn('{{%observation}}', 'recorded_by', $this->integer()->null());
        $this->execute("ALTER TABLE {{%observation}}
            ADD CONSTRAINT observation_client_uuid_key UNIQUE (client_uuid),
            ADD CONSTRAINT observation_location_status_check
                CHECK (location_status IS NULL OR location_status IN ('gps', 'unknown_underground', 'denied', 'unavailable')),
            ADD CONSTRAINT observation_accuracy_check CHECK (location_accuracy_m IS NULL OR location_accuracy_m >= 0)");
        $this->addForeignKey('fk_observation_recorded_by', '{{%observation}}', 'recorded_by', '{{%user}}', 'id', 'RESTRICT', 'CASCADE');

        $this->addColumn('{{%inspection}}', 'client_uuid', 'uuid NULL');
        $this->execute("ALTER TABLE {{%inspection}}
            ADD CONSTRAINT inspection_client_uuid_key UNIQUE (client_uuid),
            DROP CONSTRAINT inspection_type_check,
            ADD CONSTRAINT inspection_type_check CHECK (inspection_type IN ('regular', 'spot', 'complaint', 'self'))");

        $this->createTable('{{%field_sync}}', [
            'client_uuid' => 'uuid PRIMARY KEY',
            'user_id' => $this->integer()->notNull(),
            'kind' => $this->string(16)->notNull(),
            'result' => 'jsonb NOT NULL',
            'created_at' => 'timestamptz NOT NULL DEFAULT now()',
        ]);
        $this->execute("ALTER TABLE {{%field_sync}} ADD CONSTRAINT field_sync_kind_check CHECK (kind IN ('visit', 'capture', 'photo'))");
        $this->addForeignKey('fk_field_sync_user', '{{%field_sync}}', 'user_id', '{{%user}}', 'id', 'CASCADE', 'CASCADE');
        $this->createIndex('idx_field_sync_user', '{{%field_sync}}', ['user_id', 'created_at']);
    }

    public function safeDown()
    {
        $this->dropTable('{{%field_sync}}');
        // A mine head's field inspection becomes a spot inspection (kept, with its findings).
        $this->execute("UPDATE {{%inspection}} SET inspection_type = 'spot' WHERE inspection_type = 'self'");
        $this->execute("ALTER TABLE {{%inspection}}
            DROP CONSTRAINT inspection_type_check,
            ADD CONSTRAINT inspection_type_check CHECK (inspection_type IN ('regular', 'spot', 'complaint')),
            DROP CONSTRAINT inspection_client_uuid_key");
        $this->dropColumn('{{%inspection}}', 'client_uuid');
        $this->dropForeignKey('fk_observation_recorded_by', '{{%observation}}');
        $this->execute('ALTER TABLE {{%observation}} DROP CONSTRAINT observation_client_uuid_key,
            DROP CONSTRAINT observation_location_status_check, DROP CONSTRAINT observation_accuracy_check');
        foreach (['recorded_by', 'clock_flag', 'clock_skew_s', 'geo_flag', 'distance_m', 'location_status', 'location_accuracy_m',
            'location', 'received_at', 'recorded_at', 'note', 'obligation_code', 'checklist_version', 'checklist_item', 'client_uuid'] as $column) {
            $this->dropColumn('{{%observation}}', $column);
        }
    }
}
