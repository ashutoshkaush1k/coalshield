<?php

declare(strict_types=1);

namespace app\tests\Support\Helper;

use app\models\User;

/** Demo accounts (data/out/<preset>/user.csv; the same in every preset) and a token helper. */
final class Auth
{
    public const GOVERNMENT = 'gov@dgms.gov.in';
    public const CORPORATE_SECL = 'corporate.secl@coalmine.in';
    public const MINE_HEAD_MOONIDIH = 'head.jh-dhn-01@coalmine.in';
    /** OD-TLC-05, Bhubaneswari (MCL) - the HIGH-risk demo mine (score 45). */
    public const MINE_HEAD_BHUBANESWARI = 'head.od-tlc-05@coalmine.in';
    public const INSPECTOR = 'inspector.01@dgms.example';
    public const PASSWORD = 'demo123';

    public static function user(string $email): User
    {
        $user = User::findOne(['email' => $email]);
        if ($user === null) {
            throw new \RuntimeException("Demo user $email missing: seed the test database (run_tests.bat)");
        }
        return $user;
    }

    public static function token(string $email): string
    {
        return self::user($email)->issueToken();
    }
}
