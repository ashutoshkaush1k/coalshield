<?php

use yii\db\Migration;

require_once __DIR__ . '/m260928_000004_create_alert.php';

/** Alert code CONTRACT_WORKER_CAP_EXCEEDED (brief Phase 3), which the historical data does not contain. */
class m260929_000004_alert_code_worker_cap extends Migration
{
    public function safeUp()
    {
        $this->setCodes([...m260928_000004_create_alert::CODES, 'CONTRACT_WORKER_CAP_EXCEEDED']);
    }

    public function safeDown()
    {
        $this->execute("DELETE FROM {{%alert}} WHERE code = 'CONTRACT_WORKER_CAP_EXCEEDED'");
        $this->setCodes(m260928_000004_create_alert::CODES);
    }

    private function setCodes(array $codes): void
    {
        $list = implode(', ', array_map(fn($c) => "'$c'", $codes));
        $this->execute('ALTER TABLE {{%alert}} DROP CONSTRAINT alert_code_check');
        $this->execute("ALTER TABLE {{%alert}} ADD CONSTRAINT alert_code_check CHECK (code IN ($list))");
    }
}
