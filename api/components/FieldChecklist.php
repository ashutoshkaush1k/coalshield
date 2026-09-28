<?php

declare(strict_types=1);

namespace app\components;

use Yii;

/**
 * The field inspection checklist (Phase 7B): data/reference/field_checklist.csv. One item per
 * thing an inspector looks at, keyed to one of the 11 violation categories and, where one clearly
 * applies, to obligations of the cited catalogue (obligations.csv). The prompt text there is the
 * English source; the app shows the item in the user's language (i18n key field.check.<code>).
 * The version is the file's hash, stored with each observation, so an edited list never changes
 * what an old capture answered.
 */
final class FieldChecklist
{
    private static ?array $items = null;
    private static ?string $version = null;

    /** @return array<string, array{code: string, category: string, obligations: string[]}> by item code */
    public static function items(): array
    {
        if (self::$items === null) {
            $path = self::path();
            $raw = (string) file_get_contents($path);
            self::$version = substr(sha1($raw), 0, 12);
            $rows = array_map('str_getcsv', preg_split('/\r?\n/', trim($raw)));
            $head = array_shift($rows);
            self::$items = [];
            foreach ($rows as $row) {
                $r = array_combine($head, $row);
                self::$items[$r['item_code']] = [
                    'code' => $r['item_code'],
                    'category' => $r['category'],
                    'obligations' => $r['obligation_codes'] === '' ? [] : explode(';', $r['obligation_codes']),
                ];
            }
        }
        return self::$items;
    }

    public static function version(): string
    {
        self::items();
        return (string) self::$version;
    }

    private static function path(): string
    {
        $dir = (string) Yii::$app->params['dataReferenceDir'];
        if (!preg_match('~^([a-zA-Z]:)?[/\\\\]~', $dir)) {
            $dir = Yii::getAlias('@app') . '/' . $dir;
        }
        return $dir . '/field_checklist.csv';
    }
}
