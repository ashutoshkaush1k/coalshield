<?php

declare(strict_types=1);

namespace app\commands;

use app\models\User;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Sign-ins for the online version (docs/DEPLOYMENT.md), run from the laptop against the online
 * database through scripts\online.bat, which loads deploy\online.local.env and sets ONLINE_TARGET=1.
 * Without it every action refuses, so the laptop's demo accounts (demo123) are never touched.
 *
 *   scripts\online.bat online/credentials            the four judge accounts get strong passwords
 *                                                    (kept from the credentials file when it has
 *                                                    them, as is every other account listed there);
 *                                                    every other account an unrecorded one
 *   scripts\online.bat online/credentials --new=1    new judge passwords, even if the file has some
 *   scripts\online.bat online/reset-password <email> a new password for one account, saved to the file
 *   scripts\online.bat online/reset-data             reseed the online data (seed online + jobs/all),
 *                                                    then the passwords again - the judges' unchanged
 *
 * Passwords are written only to deploy\credentials.local.md (git-ignored) and stored only as
 * hashes; nothing here prints one.
 */
class OnlineController extends Controller
{
    /** The accounts shared with judges: role label => email. */
    public const JUDGES = [
        'Government (DGMS)' => 'gov@dgms.gov.in',
        'Corporate (SECL)' => 'corporate.secl@coalmine.in',
        'Mine Head (Bhubaneswari)' => 'head.od-tlc-05@coalmine.in',
        'Inspector' => 'inspector.01@dgms.example',
    ];
    public const PRESET = 'online';
    /** No 0/O, 1/l/I: the passwords are typed by hand. */
    private const ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const JUDGE_LENGTH = 20;
    private const OTHER_LENGTH = 40;

    public bool $new = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'credentials' ? ['new'] : []);
    }

    public function beforeAction($action): bool
    {
        if (!filter_var(getenv('ONLINE_TARGET') ?: false, FILTER_VALIDATE_BOOL)) {
            throw new \yii\console\Exception("Refused: run this through scripts\\online.bat, which points it at the online database.\n"
                . "(Run directly it would change the laptop's demo accounts.)");
        }
        return parent::beforeAction($action);
    }

    public function actionCredentials(): int
    {
        $saved = $this->new ? [] : $this->readFile();
        $judges = [];
        foreach (self::JUDGES as $label => $email) {
            $judges[$email] = $saved[$email] ?? self::randomPassword(self::JUDGE_LENGTH);
        }
        // Accounts given a password with online/reset-password keep it too: the file is the record.
        $accounts = $judges + array_diff_key($saved, $judges);
        $others = $this->apply($accounts, array_values(self::JUDGES));
        $this->writeFile($accounts);
        $kept = count(array_intersect_key($saved, $judges));
        $this->stdout(sprintf("%d judge account(s) set (%d kept from the file, %d new); %d other account(s) given unrecorded random passwords.\n"
            . "Passwords: %s (never commit or post it).\n", count($judges), $kept, count($judges) - $kept, $others, $this->relativeFile()), Console::FG_GREEN);
        return ExitCode::OK;
    }

    public function actionResetPassword(string $email): int
    {
        $email = mb_strtolower(trim($email));
        $user = User::findOne(['email' => $email]);
        if ($user === null) {
            $this->stderr("No account $email in the online database.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        $password = self::randomPassword(self::JUDGE_LENGTH);
        $user->setPassword($password);
        $user->save(false);
        $saved = $this->readFile();
        $saved[$email] = $password;
        $this->writeFile($saved);
        $this->stdout("New password for $email saved to {$this->relativeFile()}.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    public function actionResetData(): int
    {
        $this->stdout("Reseeding the online database (preset '" . self::PRESET . "'), then the scheduled jobs...\n");
        if (Yii::$app->runAction('seed/index', [self::PRESET]) !== ExitCode::OK) {
            return ExitCode::UNSPECIFIED_ERROR;
        }
        Yii::$app->runAction('jobs/all');
        $this->new = false;
        return $this->actionCredentials();
    }

    /**
     * The listed accounts get their passwords; every other account a random one nobody records.
     * @param array<string, string> $judges email => password (the judges and any other listed account)
     * @param string[] $required emails that must exist
     * @return int how many other accounts were changed
     */
    private function apply(array $judges, array $required): int
    {
        $others = 0;
        /** @var User $user */
        foreach (User::find()->orderBy('id')->each(50) as $user) {
            $email = mb_strtolower($user->email);
            $user->setPassword($judges[$email] ?? self::randomPassword(self::OTHER_LENGTH));
            $user->save(false);
            $others += isset($judges[$email]) ? 0 : 1;
        }
        $missing = array_diff($required, User::find()->select('email')->column());
        if ($missing) {
            throw new \RuntimeException('Judge account(s) missing from the online database: ' . implode(', ', $missing));
        }
        return $others;
    }

    public static function randomPassword(int $length): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    private function file(): string
    {
        return (string) (getenv('ONLINE_CREDENTIALS_FILE') ?: dirname(Yii::getAlias('@app')) . '/deploy/credentials.local.md');
    }

    private function relativeFile(): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR, 'deploy/' . basename($this->file()));
    }

    /** @return array<string, string> email => password, from the file's table */
    private function readFile(): array
    {
        $path = $this->file();
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $cells = array_map('trim', explode('|', trim($line)));
            // | Role | Email | Password |  ->  ['', role, email, password, '']
            if (count($cells) >= 5 && str_contains($cells[2], '@') && preg_match('/^`?([^`\s]+)`?$/', $cells[3], $m)) {
                $out[mb_strtolower(trim($cells[2], '`'))] = $m[1];
            }
        }
        return $out;
    }

    /** @param array<string, string> $accounts email => password */
    private function writeFile(array $accounts): void
    {
        $roles = array_flip(self::JUDGES);
        $site = (string) (getenv('ONLINE_SITE_URL') ?: '(the Vercel address)');
        $lines = [
            '# Online sign-ins - PRIVATE',
            '',
            'Never commit this file, never post it in the repository, an issue or a public chat. Share a',
            'password only in a private message to the person who needs it (docs/DEPLOYMENT.md, "Sharing the logins").',
            '',
            "Site: $site  ",
            'Updated: ' . gmdate('Y-m-d H:i') . ' UTC by `scripts\\online.bat` (the laptop\'s accounts are unchanged: demo123).',
            '',
            'The accounts listed here keep these passwords when the online data is reset; every other',
            'account has a random password nobody knows (give it one with online/reset-password).',
            '',
            '| Role | Email | Password |',
            '|---|---|---|',
        ];
        foreach ($accounts as $email => $password) {
            $lines[] = sprintf('| %s | %s | `%s` |', $roles[$email] ?? 'Other', $email, $password);
        }
        $path = $this->file();
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX);
    }
}
