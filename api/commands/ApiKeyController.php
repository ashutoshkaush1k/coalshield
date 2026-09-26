<?php

declare(strict_types=1);

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Machine credentials for POST /v1/sensor-readings/ingest.
 *   yii api-key/issue simulator [--out=path]   new key (replaces one with the same name); printed
 *                                              once, or written to --out and not printed
 *   yii api-key/revoke simulator
 * Only the SHA-256 of a key is stored.
 */
class ApiKeyController extends Controller
{
    public ?string $out = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'issue' ? ['out'] : []);
    }

    public function actionIssue(string $name): int
    {
        if (!preg_match('/^[a-z0-9_-]{2,64}$/', $name)) {
            $this->stderr("Name: 2-64 of a-z 0-9 _ -\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $key = 'csk_' . bin2hex(random_bytes(24));
        $db = Yii::$app->db;
        $db->createCommand()->delete('{{%api_key}}', ['name' => $name])->execute();
        $db->createCommand()->insert('{{%api_key}}', ['name' => $name, 'key_hash' => hash('sha256', $key), 'scope' => 'sensor_ingest'])->execute();
        if ($this->out !== null) {
            if (file_put_contents($this->out, $key . PHP_EOL) === false) {
                $this->stderr("Cannot write {$this->out}\n", Console::FG_RED);
                return ExitCode::IOERR;
            }
            $this->stdout("API key '$name' issued and written to {$this->out}.\n");
        } else {
            $this->stdout("API key '$name' (shown once, keep it secret):\n$key\n");
        }
        return ExitCode::OK;
    }

    public function actionRevoke(string $name): int
    {
        $count = Yii::$app->db->createCommand()->update('{{%api_key}}', ['revoked_at' => gmdate('Y-m-d H:i:sP')], ['name' => $name, 'revoked_at' => null])->execute();
        $this->stdout($count ? "Revoked '$name'.\n" : "No active key '$name'.\n");
        return ExitCode::OK;
    }
}
