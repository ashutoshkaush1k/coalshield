<?php

declare(strict_types=1);

namespace app\components;

/** Implemented by every model with a workflow; see StatusTransition. */
interface HasStatusTransitions
{
    public static function statusAttribute(): string;

    /** @return array<string, string[]> from status => allowed target statuses */
    public static function transitions(): array;

    /** Write the entity's history row (e.g. grievance_action). Called inside the transition's transaction. */
    public function recordTransition(string $from, string $to, ?int $userId, array $context): void;
}
