<?php

declare(strict_types=1);

namespace app\components;

/**
 * When an incident must be reported, by its obligation (owner, Phase 6 approval: the law's times).
 * The same rule as data/generators/gen_obligations.py incident_deadline():
 *
 *   RPT-05  r.7(3)  "within twelve hours"                                         12 h   law
 *   RPT-04  r.7(2)  "within twelve hours after the completion of forty-eight hours" 60 h  law
 *                   (48 h of disablement, counted from the incident, then 12 h)
 *   RPT-03  r.7(1)  "forthwith" - immediate, with a grace period                   1 h   product setting
 *
 * The hours come from data/schema/rules.yaml (legal values cite their obligation; the grace is
 * product.obligation_schedule.forthwith_grace_hours). `rule` is the law's own wording, shown in the UI.
 */
final class IncidentDeadline
{
    /** @return array{hours: float, basis: string, rule: string} */
    public static function for(string $obligationCode): array
    {
        return match ($obligationCode) {
            'RPT-05' => ['hours' => (float) Rules::value('legal', 'dangerous_occurrence_notice_hours'), 'basis' => 'law', 'rule' => 'within twelve hours'],
            'RPT-04' => ['hours' => (float) Rules::value('legal', 'injury_disablement_hours') + (float) Rules::value('legal', 'injury_report_hours_after_48h_disablement'),
                'basis' => 'law', 'rule' => 'within twelve hours after the completion of forty-eight hours'],
            'RPT-03' => ['hours' => (float) Rules::value('product', 'obligation_schedule')['forthwith_grace_hours'], 'basis' => 'product', 'rule' => 'forthwith'],
            default => throw new \InvalidArgumentException("no reporting deadline for $obligationCode"),
        };
    }

    /** SQL: the incident's due time, for filters (`late`) - one CASE over the three codes. */
    public static function dueSql(string $alias = 'incident'): string
    {
        $cases = '';
        foreach (['RPT-03', 'RPT-04', 'RPT-05'] as $code) {
            $cases .= sprintf(" WHEN '%s' THEN interval '%d minutes'", $code, (int) round(self::for($code)['hours'] * 60));
        }
        return "($alias.occurred_at + CASE $alias.obligation_code$cases END)";
    }
}
