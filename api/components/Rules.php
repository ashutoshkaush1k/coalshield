<?php

declare(strict_types=1);

namespace app\components;

use Symfony\Component\Yaml\Yaml;
use Yii;

/**
 * Read-only access to data/schema/rules.yaml and violation_categories.yaml - the single source of
 * legal sensor limits (each tied to a verified obligation) and of the 11 violation categories.
 * Nothing here is typed in by hand: a limit the YAML marks TODO-VERIFY or null is "no limit", and
 * readings of that sensor never breach.
 */
final class Rules
{
    /** Chart and breach-bucket grouping of the sensor types. */
    public const SENSOR_CATEGORY = [
        'ch4' => 'gas', 'ch4_return_air' => 'gas', 'co' => 'gas',
        'dust' => 'dust', 'temperature' => 'temperature', 'humidity' => 'humidity',
    ];

    private static ?array $rules = null;
    private static ?array $categories = null;

    /**
     * @return array<string, array{unit: string, limit: ?float, compare: string, obligation: ?string}>
     */
    public static function sensors(): array
    {
        $out = [];
        foreach (self::rules()['sensors'] as $type => $rule) {
            $limit = $rule['limit'] ?? null;
            $out[$type] = [
                'unit' => (string) $rule['unit'],
                'limit' => is_int($limit) || is_float($limit) ? (float) $limit : null,
                'compare' => (string) ($rule['compare'] ?? 'instantaneous'),
                'obligation' => $rule['obligation'] ?? null,
            ];
        }
        return $out;
    }

    public static function sensor(string $type): ?array
    {
        return self::sensors()[$type] ?? null;
    }

    /** @return string[] the 11 category keys */
    public static function categories(): array
    {
        if (self::$categories === null) {
            $data = Yaml::parseFile(self::path('violation_categories.yaml'));
            self::$categories = array_column($data['categories'], 'key');
        }
        return self::$categories;
    }

    /** @return array<string, string[]> category key => its violation types (violation_categories.yaml example_types) */
    public static function violationTypes(): array
    {
        $data = Yaml::parseFile(self::path('violation_categories.yaml'));
        return array_column(array_map(fn($c) => [$c['key'], $c['example_types'] ?? []], $data['categories']), 1, 0);
    }

    /** A value under `legal:` or `product:` (e.g. legal.dangerous_occurrence_notice_hours). */
    public static function value(string $section, string $key): mixed
    {
        return self::rules()[$section][$key]['value'] ?? self::rules()[$section][$key] ?? null;
    }

    private static function rules(): array
    {
        return self::$rules ??= Yaml::parseFile(self::path('rules.yaml'));
    }

    private static function path(string $file): string
    {
        $dir = Yii::$app->params['dataSchemaDir'];
        if (!preg_match('~^([a-zA-Z]:)?[/\\\\]~', $dir) && !str_starts_with($dir, '@')) {
            $dir = Yii::getAlias('@app') . '/' . $dir;
        }
        $path = Yii::getAlias($dir) . '/' . $file;
        if (!is_file($path)) {
            throw new \RuntimeException("Missing $path (set DATA_SCHEMA_DIR)");
        }
        return $path;
    }
}
