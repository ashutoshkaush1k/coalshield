<?php

declare(strict_types=1);

namespace app\services;

/** A score plus every input that produced it, so any number on the dashboard can be explained. */
final class ComplianceResult
{
    public function __construct(
        public readonly float $score,
        public readonly string $riskLevel,
        public readonly int $violationCount,
        public readonly int $breachCount,
        public readonly float $violationPenalty,
        public readonly float $environmentalPenalty,
        public readonly float $rawScore,
        public readonly float $weightPpe,
        public readonly float $weightEnv,
        public readonly ?float $breachWindowHours,
    ) {
    }

    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'risk_level' => $this->riskLevel,
            'risk_colour' => ['low' => 'green', 'medium' => 'yellow', 'high' => 'red'][$this->riskLevel],
            'violation_count' => $this->violationCount,
            'breach_count' => $this->breachCount,
            'violation_penalty' => $this->violationPenalty,
            'environmental_penalty' => $this->environmentalPenalty,
            'weight_ppe' => $this->weightPpe,
            'weight_env' => $this->weightEnv,
            'breach_window_hours' => $this->breachWindowHours,
            'is_demo_value' => true,
        ];
    }
}
