<?php

declare(strict_types=1);

namespace app\models;

use app\components\ActiveRecord;
use DateTimeImmutable;
use Lcobucci\JWT\UnencryptedToken;
use Yii;
use yii\web\IdentityInterface;

/**
 * An account. Roles and statuses are lower case. password_hash never leaves the API and is redacted
 * in the audit log. Columns: data/schema/user.yaml (the CSV's demo `password` becomes password_hash
 * when seeding).
 *
 * @property int $id
 * @property string $email
 * @property string $password_hash
 * @property string $full_name
 * @property string $role
 * @property int|null $subsidiary_id
 * @property int|null $area_id
 * @property int|null $mine_id
 * @property string $preferred_language
 * @property string $status
 * @property string $created_at
 * @property string $updated_at
 */
class User extends ActiveRecord implements IdentityInterface
{
    public const ROLE_GOVERNMENT = 'government';
    public const ROLE_CORPORATE = 'corporate';
    public const ROLE_MINE_HEAD = 'mine_head';
    public const ROLE_INSPECTOR = 'inspector';
    public const ROLES = [self::ROLE_GOVERNMENT, self::ROLE_CORPORATE, self::ROLE_MINE_HEAD, self::ROLE_INSPECTOR];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const SCENARIO_UPDATE_ME = 'update_me';

    public static function tableName(): string
    {
        return '{{%user}}';
    }

    public static function auditRedacted(): array
    {
        return ['password_hash'];
    }

    public function scenarios(): array
    {
        return parent::scenarios() + [self::SCENARIO_UPDATE_ME => ['preferred_language']];
    }

    public function transactions(): array
    {
        return parent::transactions() + [self::SCENARIO_UPDATE_ME => self::OP_ALL];
    }

    public function rules(): array
    {
        return [
            [['email', 'password_hash', 'full_name', 'role', 'preferred_language', 'status'], 'required', 'message' => 'REQUIRED'],
            [['email'], 'email', 'message' => 'INVALID_EMAIL'],
            [['email'], 'unique', 'message' => 'NOT_UNIQUE'],
            [['role'], 'in', 'range' => self::ROLES, 'message' => 'INVALID_VALUE'],
            [['status'], 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_INACTIVE], 'message' => 'INVALID_VALUE'],
            [['preferred_language'], 'in', 'range' => Yii::$app->params['languages'], 'message' => 'INVALID_VALUE'],
            [['subsidiary_id', 'area_id', 'mine_id'], 'integer', 'message' => 'INVALID_VALUE'],
            [['full_name'], 'string', 'max' => 160, 'tooLong' => 'TOO_LONG'],
        ];
    }

    public function beforeSave($insert): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if ($insert && !$this->created_at) {
            $this->created_at = $now;
        }
        if ($insert || $this->getDirtyAttributes() !== []) {
            $this->updated_at = $now;
        }
        return parent::beforeSave($insert);
    }

    public function fields(): array
    {
        return [
            'id',
            'email',
            'full_name',
            'role',
            'subsidiary_id',
            'area_id',
            'mine_id',
            'preferred_language',
            'status',
            'created_at' => fn() => self::isoUtc($this->created_at),
            'updated_at' => fn() => self::isoUtc($this->updated_at),
        ];
    }

    public function getMine()
    {
        return $this->hasOne(Mine::class, ['id' => 'mine_id']);
    }

    public function getSubsidiary()
    {
        return $this->hasOne(Subsidiary::class, ['id' => 'subsidiary_id']);
    }

    public function validatePassword(string $password): bool
    {
        return Yii::$app->security->validatePassword($password, $this->password_hash);
    }

    public function setPassword(string $password): void
    {
        $this->password_hash = Yii::$app->security->generatePasswordHash($password);
    }

    /** A signed token for this user: sub = id, plus role for clients that want it. */
    public function issueToken(): string
    {
        $jwt = Yii::$app->jwt;
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $hours = (int) Yii::$app->params['jwt.ttlHours'];
        return $jwt->getBuilder()
            ->issuedBy(Yii::$app->params['jwt.issuer'])
            ->relatedTo((string) $this->id)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify("+{$hours} hours"))
            ->withClaim('role', $this->role)
            ->getToken($jwt->getConfiguration()->signer(), $jwt->getConfiguration()->signingKey())
            ->toString();
    }

    // IdentityInterface

    public static function findIdentity($id): ?self
    {
        return static::findOne(['id' => (int) $id, 'status' => self::STATUS_ACTIVE]);
    }

    /** $token is the raw JWT, already validated by JwtHttpBearerAuth (signature, time, issuer). */
    public static function findIdentityByAccessToken($token, $type = null): ?self
    {
        try {
            $parsed = Yii::$app->jwt->parse((string) $token);
        } catch (\Throwable) {
            return null;
        }
        if (!$parsed instanceof UnencryptedToken || !Yii::$app->jwt->validate($parsed)) {
            return null;
        }
        $subject = $parsed->claims()->get('sub');
        return is_numeric($subject) ? static::findIdentity((int) $subject) : null;
    }

    public function getId(): int
    {
        return (int) $this->id;
    }

    public function getAuthKey(): ?string
    {
        return null;
    }

    public function validateAuthKey($authKey): bool
    {
        return false;
    }

    public static function isoUtc(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (new DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
