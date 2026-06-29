<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table      = 'users';
    protected $primaryKey = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'username', 'email', 'password', 'nickname', 'role', 'grade', 'is_active', 'last_login',
        'social_provider', 'social_id', 'social_token', 'avatar',
        'phone', 'gender', 'birthday',
        'email_verify_token', 'email_verify_token_at',
    ];

    public function findByEmail(string $email): ?array
    {
        return $this->where('email', $email)->where('is_active', 1)->first();
    }

    /** 미인증(is_active=0) 일반 가입 유저 조회 */
    public function findUnverified(string $email): ?array
    {
        return $this->where('email', $email)
            ->where('is_active', 0)
            ->where('social_provider IS NULL')
            ->first();
    }

    /** 토큰 생성 후 DB 저장, 토큰 문자열 반환 */
    public function generateVerifyToken(int $id): string
    {
        $token = bin2hex(random_bytes(32));
        $this->update($id, [
            'email_verify_token'    => $token,
            'email_verify_token_at' => date('Y-m-d H:i:s'),
        ]);
        return $token;
    }

    /** 토큰으로 유저 조회 (24시간 이내만 유효) */
    public function verifyByToken(string $token): ?array
    {
        $expiry = date('Y-m-d H:i:s', strtotime('-24 hours'));
        return $this->where('email_verify_token', $token)
            ->where('email_verify_token_at >=', $expiry)
            ->where('is_active', 0)
            ->first();
    }

    /** 이메일 인증 완료: 활성화 + 토큰 초기화 */
    public function clearVerifyToken(int $id): void
    {
        $this->update($id, [
            'is_active'             => 1,
            'email_verify_token'    => null,
            'email_verify_token_at' => null,
        ]);
    }

    public function updateLastLogin(int $id): void
    {
        $this->update($id, ['last_login' => date('Y-m-d H:i:s')]);
    }
}
