<?php

use yii\db\Migration;

/**
 * Incidents on the obligation register (owner, 2026-09-28): each incident has one on-event task for
 * its reporting obligation (incident.obligation_code: RPT-03 / RPT-04 / RPT-05), due
 * product.obligation_schedule.incident_notice_hours after it occurred and done at reported_at.
 * obligation_task.incident_id links the two; at most one task per incident.
 */
class m261004_000001_obligation_task_incident extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%obligation_task}}', 'incident_id', $this->integer()->null());
        $this->addForeignKey('fk_obligation_task_incident', '{{%obligation_task}}', 'incident_id', '{{%incident}}', 'id', 'RESTRICT', 'CASCADE');
        $this->execute('CREATE UNIQUE INDEX obligation_task_incident_unique ON {{%obligation_task}} (incident_id) WHERE incident_id IS NOT NULL');
    }

    public function safeDown()
    {
        $this->execute('DROP INDEX IF EXISTS obligation_task_incident_unique');
        $this->dropForeignKey('fk_obligation_task_incident', '{{%obligation_task}}');
        $this->dropColumn('{{%obligation_task}}', 'incident_id');
    }
}
