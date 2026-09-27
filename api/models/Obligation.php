<?php

declare(strict_types=1);

namespace app\models;

use app\components\ActiveRecord;

/**
 * A statutory obligation from the cited catalogue (data/reference/obligations.csv, Phase 5B).
 * Global reference data, not per mine. `citation()` is what every screen shows next to it.
 *
 * @property int $id
 * @property string $code
 * @property string $domain
 * @property string $title
 * @property string|null $instrument
 * @property string|null $clause
 * @property string $applies_to
 * @property string $frequency
 * @property string|null $due_rule
 * @property string $responsible_role
 * @property string $evidence_type
 * @property int|null $citation_page
 * @property string|null $citation_file
 * @property string|null $citation_quote
 * @property bool $verified
 * @property string|null $note
 * @property string $schedule
 * @property bool $generates_tasks
 * @property string $due_basis
 */
class Obligation extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%obligation}}';
    }

    /** Act, section / rule, the verbatim quote and where it was read (file and page). */
    public function citation(): array
    {
        return [
            'instrument' => $this->instrument,
            'clause' => $this->clause,
            'quote' => $this->citation_quote,
            'source_file' => $this->citation_file === null ? null : basename($this->citation_file),
            'page' => $this->citation_page === null ? null : (int) $this->citation_page,
            'verified' => (bool) $this->verified,
        ];
    }

    public function fields(): array
    {
        return [
            'id', 'code', 'domain', 'title', 'applies_to', 'frequency', 'due_rule', 'responsible_role', 'evidence_type',
            'verified' => fn() => (bool) $this->verified,
            'note', 'schedule',
            'generates_tasks' => fn() => (bool) $this->generates_tasks,
            'due_basis',
            'citation' => fn() => $this->citation(),
            // Sensor types whose limit comes from this obligation (rules.yaml): a continuous limit
            // is watched by the sensor rules, not tracked as a dated task.
            'monitored_by' => fn() => self::monitoredBy()[$this->code] ?? [],
        ];
    }

    /** @return array<string, string[]> obligation code => sensor types */
    private static function monitoredBy(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (\app\components\Rules::sensors() as $type => $rule) {
                if ($rule['obligation'] !== null && $rule['limit'] !== null) {
                    $map[$rule['obligation']][] = $type;
                }
            }
        }
        return $map;
    }
}
