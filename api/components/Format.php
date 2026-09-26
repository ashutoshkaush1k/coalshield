<?php

declare(strict_types=1);

namespace app\components;

use DateTimeImmutable;
use DateTimeZone;

/** Output conventions (brief rule 8): ISO-8601 UTC with Z, numbers as numbers. */
final class Format
{
    public static function utc(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** SQL timestamp for a PHP time (UTC, microseconds kept). */
    public static function sql(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function float(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    public static function int(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    public static function bool(mixed $value): ?bool
    {
        return $value === null ? null : (bool) $value;
    }

    /** Decode a jsonb value that may already be an array (Yii typecasts json columns). */
    public static function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
