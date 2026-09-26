<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\models\Mine;
use app\models\User;
use app\tests\Support\Helper\Auth;
use Codeception\Test\Unit;

class ScopedActiveQueryTest extends Unit
{
    public function testGovernmentAndInspectorSeeEverything(): void
    {
        $all = (int) Mine::find()->count();
        $this->assertSame($all, (int) Mine::find()->forUser(Auth::user(Auth::GOVERNMENT))->count());
        $this->assertSame($all, (int) Mine::find()->forUser(Auth::user(Auth::INSPECTOR))->count());
    }

    public function testCorporateSeesItsSubsidiary(): void
    {
        $user = Auth::user(Auth::CORPORATE_SECL);
        $ids = Mine::find()->forUser($user)->select('mine.id')->column();
        $expected = Mine::find()->where(['subsidiary_id' => $user->subsidiary_id])->select('id')->column();
        $this->assertNotEmpty($ids);
        $this->assertEqualsCanonicalizing($expected, $ids);
    }

    public function testMineHeadSeesOwnMine(): void
    {
        $user = Auth::user(Auth::MINE_HEAD_MOONIDIH);
        $this->assertSame([$user->mine_id], array_map('intval', Mine::find()->forUser($user)->select('mine.id')->column()));
    }

    public function testMisconfiguredUsersSeeNothing(): void
    {
        $noSubsidiary = new User(['role' => User::ROLE_CORPORATE, 'subsidiary_id' => null]);
        $noMine = new User(['role' => User::ROLE_MINE_HEAD, 'mine_id' => null]);
        $unknownRole = new User(['role' => 'auditor']);
        foreach ([$noSubsidiary, $noMine, $unknownRole] as $user) {
            $this->assertSame(0, (int) Mine::find()->forUser($user)->count());
        }
    }

    public function testNoIdentitySeesNothing(): void
    {
        \Yii::$app->user->logout();
        $this->assertSame(0, (int) Mine::find()->forCurrentUser()->count());
    }

    public function testFindScopedThrows404OutOfScope(): void
    {
        $user = Auth::user(Auth::MINE_HEAD_MOONIDIH);
        \Yii::$app->user->setIdentity($user);
        $this->assertSame($user->mine_id, Mine::findScoped($user->mine_id)->id);

        $other = (int) Mine::find()->where(['<>', 'id', $user->mine_id])->select('id')->scalar();
        try {
            Mine::findScoped($other);
            $this->fail('expected 404');
        } catch (\app\components\ApiException $e) {
            $this->assertSame(404, $e->statusCode);
            $this->assertSame('NOT_FOUND', $e->errorCode);
        }
    }
}
