<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\models\Contractor;
use app\services\ContractorService;
use Codeception\Test\Unit;

/** The contractor score's rules, independent of the seeded data. */
class ContractorServiceTest extends Unit
{
    public function testDuePeriodsFollowTheDueDay(): void
    {
        // Due day 10 (rules.yaml product.contractor_doc_due_day): August is due on 10 September.
        $this->assertSame(['2026-05', '2026-06', '2026-07', '2026-08'], ContractorService::duePeriods('2026-09-27'));
        $this->assertSame(['2026-04', '2026-05', '2026-06', '2026-07'], ContractorService::duePeriods('2026-09-05'));
    }

    public function testScoreAndBand(): void
    {
        $contractor = new Contractor(['id' => 1, 'status' => 'active', 'licence_valid_to' => '2030-01-01']);
        $clean = ['active_workers' => 10, 'vt_expired' => 0, 'medical_overdue' => 0, 'violations' => 0, 'missing_docs' => [],
            'unverified_docs' => 0, 'over_cap' => [], 'contracts' => 1, 'active_contracts' => 1];
        $this->assertSame(['score' => 100, 'band' => 'compliant'], array_intersect_key(ContractorService::score($contractor, $clean, '2026-09-27'), ['score' => 0, 'band' => 0]));

        $bad = ['violations' => 10, 'missing_docs' => array_fill(0, 20, ['contract_id' => 1, 'period' => '2026-08', 'doc_type' => 'wage_register'])] + $clean;
        $result = ContractorService::score($contractor, $bad, '2026-09-27');
        $this->assertSame(['violations' => 40, 'documents' => 30, 'licence' => 0, 'workers' => 0, 'cap' => 0], $result['penalties']);
        $this->assertSame(30, $result['score']);
        $this->assertSame('flagged', $result['band']);
        $this->assertSame(1.0, $result['violations_per_worker']);
    }

    public function testLicenceAndStatus(): void
    {
        $figures = ['active_workers' => 0, 'vt_expired' => 0, 'medical_overdue' => 0, 'violations' => 0, 'missing_docs' => [],
            'unverified_docs' => 0, 'over_cap' => [], 'contracts' => 0, 'active_contracts' => 0];
        $expiring = ContractorService::score(new Contractor(['id' => 1, 'status' => 'active', 'licence_valid_to' => '2026-10-10']), $figures, '2026-09-27');
        $this->assertSame(['state' => 'expiring', 'valid_to' => '2026-10-10', 'days_left' => 13, 'obligation' => 'LAB-02'], $expiring['licence']);
        $this->assertSame(90, $expiring['score']);
        $expired = ContractorService::score(new Contractor(['id' => 1, 'status' => 'active', 'licence_valid_to' => '2026-09-01']), $figures, '2026-09-27');
        $this->assertSame(70, $expired['score']);
        $this->assertNull($expired['violations_per_worker'], 'no active workers: no ratio');
        $blacklisted = ContractorService::score(new Contractor(['id' => 1, 'status' => 'blacklisted', 'licence_valid_to' => '2030-01-01']), $figures, '2026-09-27');
        $this->assertSame('flagged', $blacklisted['band'], 'a blacklisted contractor is always flagged');
    }
}
