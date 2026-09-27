<?php

use yii\db\Migration;

/**
 * Contractor management (brief Phase 3); columns exactly as data/schema/contractor.yaml,
 * contract.yaml, contract_worker.yaml and contractor_compliance_doc.yaml.
 * Contractors are global (not per mine); a contract ties one to a mine, and scoping follows the
 * contract's mine_id.
 */
class m260929_000002_create_contractor_tables extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%contractor}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(160)->notNull(),
            'registration_no' => $this->string(64)->notNull(),
            'labour_licence_no' => $this->string(64)->notNull(),
            'licence_valid_to' => $this->date()->notNull(),
            'epf_code' => $this->string(64)->notNull(),
            'esi_code' => $this->string(64)->notNull(),
            'contact' => $this->string(64)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('active'),
        ]);
        $this->execute("ALTER TABLE {{%contractor}}
            ADD CONSTRAINT contractor_status_check CHECK (status IN ('active', 'suspended', 'blacklisted'))");
        $this->createIndex('uq_contractor_registration', '{{%contractor}}', 'registration_no', true);
        $this->createIndex('idx_contractor_status', '{{%contractor}}', 'status');
        $this->createIndex('idx_contractor_licence', '{{%contractor}}', 'licence_valid_to');

        $this->createTable('{{%contract}}', [
            'id' => $this->primaryKey(),
            'contractor_id' => $this->integer()->notNull(),
            'mine_id' => $this->integer()->notNull(),
            'work_type' => $this->string(16)->notNull(),
            'work_order_no' => $this->string(64)->notNull(),
            'value' => $this->decimal(15, 2)->notNull(),
            'start_date' => $this->date()->notNull(),
            'end_date' => $this->date()->notNull(),
            'max_workers' => $this->integer()->notNull(),
        ]);
        $this->execute("ALTER TABLE {{%contract}}
            ADD CONSTRAINT contract_work_type_check CHECK (work_type IN ('ob_removal', 'transport', 'loading', 'civil', 'security', 'other')),
            ADD CONSTRAINT contract_dates_check CHECK (end_date >= start_date),
            ADD CONSTRAINT contract_value_check CHECK (value >= 0),
            ADD CONSTRAINT contract_max_workers_check CHECK (max_workers > 0)");
        $this->addForeignKey('fk_contract_contractor', '{{%contract}}', 'contractor_id', '{{%contractor}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_contract_mine', '{{%contract}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('uq_contract_work_order', '{{%contract}}', 'work_order_no', true);
        $this->createIndex('idx_contract_contractor', '{{%contract}}', 'contractor_id');
        $this->createIndex('idx_contract_mine_dates', '{{%contract}}', ['mine_id', 'start_date', 'end_date']);

        $this->createTable('{{%contract_worker}}', [
            'id' => $this->primaryKey(),
            'contract_id' => $this->integer()->notNull(),
            'name' => $this->string(160)->notNull(),
            'worker_code' => $this->string(32)->notNull()->unique(),
            'vt_cert_valid_to' => $this->date()->notNull(),
            'medical_exam_date' => $this->date()->notNull(),
            'active' => $this->boolean()->notNull()->defaultValue(true),
        ]);
        $this->addForeignKey('fk_contract_worker_contract', '{{%contract_worker}}', 'contract_id', '{{%contract}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx_contract_worker_contract_active', '{{%contract_worker}}', ['contract_id', 'active']);

        $this->createTable('{{%contractor_compliance_doc}}', [
            'id' => $this->primaryKey(),
            'contract_id' => $this->integer()->notNull(),
            'doc_type' => $this->string(16)->notNull(),
            'period' => $this->char(7)->notNull(),
            'file_id' => $this->integer()->notNull(),
            'verified' => $this->boolean()->notNull()->defaultValue(false),
            'verified_by' => $this->integer()->null(),
        ]);
        $this->execute("ALTER TABLE {{%contractor_compliance_doc}}
            ADD CONSTRAINT contractor_doc_type_check CHECK (doc_type IN ('wage_register', 'epf_challan', 'esi_challan', 'insurance', 'other')),
            ADD CONSTRAINT contractor_doc_period_check CHECK (period ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
            ADD CONSTRAINT contractor_doc_verified_check CHECK (verified = (verified_by IS NOT NULL))");
        $this->addForeignKey('fk_contractor_doc_contract', '{{%contractor_compliance_doc}}', 'contract_id', '{{%contract}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_contractor_doc_file', '{{%contractor_compliance_doc}}', 'file_id', '{{%file}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_contractor_doc_verified_by', '{{%contractor_compliance_doc}}', 'verified_by', '{{%user}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('uq_contractor_doc_contract_type_period', '{{%contractor_compliance_doc}}', ['contract_id', 'doc_type', 'period'], true);
        $this->createIndex('idx_contractor_doc_file', '{{%contractor_compliance_doc}}', 'file_id');
        $this->createIndex('idx_contractor_doc_verified_by', '{{%contractor_compliance_doc}}', 'verified_by');
    }

    public function safeDown()
    {
        $this->dropTable('{{%contractor_compliance_doc}}');
        $this->dropTable('{{%contract_worker}}');
        $this->dropTable('{{%contract}}');
        $this->dropTable('{{%contractor}}');
    }
}
