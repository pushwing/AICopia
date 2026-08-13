<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Exceptions\WithdrawalBlockedException;
use App\Models\WithdrawnUserModel;

/**
 * 회원탈퇴 유스케이스
 *
 * users 행은 삭제하지 않고 마스킹한다. 이 DB 에는 외래키 제약이 없어서
 * 행을 지워도 에러는 안 나지만 orders·product_reviews·posts 등 8개 테이블의
 * user_id 가 조용히 고아가 되기 때문이다.
 */
class WithdrawalService
{
    /** 결제·배송이 진행 중이라 탈퇴를 막아야 하는 주문 상태 */
    public const BLOCKING_ORDER_STATUSES = [
        'pending', 'awaiting_payment', 'paid', 'preparing', 'shipped',
    ];

    /** 반품·교환·환불 처리 중이라 탈퇴를 막아야 하는 주문 상태 */
    public const BLOCKING_CLAIM_STATUSES = [
        'refund_requested', 'return_requested', 'return_approved',
        'exchange_requested', 'exchange_approved',
    ];

    /** 탈퇴 사유 코드 → 화면 라벨 */
    public const REASON_CODES = [
        'unused'  => '이용하지 않아서',
        'price'   => '가격·혜택이 아쉬워서',
        'service' => '서비스·상품이 만족스럽지 않아서',
        'privacy' => '개인정보가 걱정되어서',
        'rejoin'  => '다른 계정으로 재가입하려고',
        'admin'   => '관리자 처리',
        'etc'     => '기타',
    ];

    private readonly WithdrawnUserModel $withdrawnUserModel;

    public function __construct()
    {
        $this->withdrawnUserModel = new WithdrawnUserModel();
    }

    /**
     * 탈퇴 가능 여부 판정
     *
     * @param array<string, mixed> $user users 행
     *
     * @return array{allowed: bool, reasons: list<string>}
     */
    public function canWithdraw(array $user): array
    {
        $reasons = [];

        if (($user['role'] ?? 'member') === 'admin') {
            $reasons[] = '관리자 계정은 탈퇴할 수 없습니다.';
        }

        $counts = $this->countBlockingOrders((int) $user['id']);

        if ($counts['order'] > 0) {
            $reasons[] = "진행 중인 주문이 {$counts['order']}건 있습니다. 배송 완료 후 탈퇴해 주세요.";
        }
        if ($counts['claim'] > 0) {
            $reasons[] = "처리 중인 반품·교환·환불이 {$counts['claim']}건 있습니다.";
        }

        return ['allowed' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * 탈퇴 처리 — 개인정보 스냅샷 → users 마스킹 → 부수 데이터 정리
     *
     * 세션 파기는 하지 않는다. 관리자 강제 탈퇴에서도 쓰이므로 호출자의
     * 세션을 건드리면 안 된다 — 세션 처리는 컨트롤러 책임이다.
     *
     * @throws WithdrawalBlockedException 차단 조건에 걸린 경우
     */
    public function withdraw(int $userId, string $reasonCode, ?string $reasonText = null, string $by = 'member'): void
    {
        $db = db_connect();
        $db->transStart();

        // 폼을 그린 시점과 제출 시점 사이에 주문이 생겼을 수 있다 — 트랜잭션 안에서 재검사.
        // 일반 SELECT 대신 SELECT ... FOR UPDATE 로 행을 잠가 동시 탈퇴 요청(더블클릭,
        // 네트워크 재시도 중복 POST)을 직렬화한다. 먼저 잠근 트랜잭션이 커밋할 때까지
        // 뒤따르는 트랜잭션은 이 SELECT 에서 블록되고, 커밋 후에는 withdrawn_at 이 채워진
        // 최신 값을 보게 되어 아래 멱등성 검사가 정상 동작한다.
        $user = $db->query('SELECT * FROM users WHERE id = ? FOR UPDATE', [$userId])->getRowArray();
        if (! is_array($user)) {
            $db->transComplete();

            return;
        }

        // 이미 탈퇴한 회원의 재요청은 조용히 통과 (멱등)
        if (! empty($user['withdrawn_at'])) {
            $db->transComplete();

            return;
        }

        $check = $this->canWithdraw($user);
        if (! $check['allowed']) {
            $db->transRollback();

            throw new WithdrawalBlockedException($check['reasons']);
        }

        $forfeit = $this->forfeitSummary($userId);
        $meta    = [
            'point_balance' => $forfeit['point'],
            'coupon_count'  => $forfeit['coupon'],
            'order_count'   => $db->table('orders')->where('user_id', $userId)->countAllResults(),
        ];

        $this->withdrawnUserModel->snapshot($user, $meta, $reasonCode, $reasonText, $by);

        $this->maskUser($userId);
        $this->cleanupPersonalData($userId, $forfeit['point']);

        $db->transComplete();
    }

    /**
     * 탈퇴 시 소멸되는 자산 (화면 경고용)
     *
     * @return array{point: int, coupon: int}
     */
    public function forfeitSummary(int $userId): array
    {
        $db   = db_connect();
        $user = $db->table('users')->select('point_balance')->where('id', $userId)->get()->getRowArray();

        return [
            'point'  => (int) ($user['point_balance'] ?? 0),
            'coupon' => $db->table('user_coupons')
                ->where('user_id', $userId)
                ->where('status', 'issued')
                ->countAllResults(),
        ];
    }

    /**
     * 보관기간이 지난 탈퇴회원의 개인정보 파기
     *
     * @return int 파기한 행 수
     */
    public function purgeExpired(int $retentionDays): int
    {
        return $this->withdrawnUserModel->purgeOlderThan($retentionDays);
    }

    /**
     * users 행 마스킹
     *
     * email 은 UNIQUE 이므로 id 기반 고유값으로 바꾼다. 그러면 원래 이메일이
     * 해방되어 재가입도 가능해진다. social_* 와 email_verify_token 은
     * 각각 unique_social·uq_email_verify_token UNIQUE 에 걸려 있어 NULL 로 비운다
     * (MySQL 은 UNIQUE 인덱스에서 NULL 중복을 허용한다).
     */
    private function maskUser(int $userId): void
    {
        db_connect()->table('users')->where('id', $userId)->update([
            'email'                 => "withdrawn_{$userId}@deleted.local",
            'username'              => "withdrawn_{$userId}",
            'nickname'              => '탈퇴회원',
            'password'              => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'phone'                 => null,
            'gender'                => null,
            'birthday'              => null,
            'avatar'                => null,
            'social_provider'       => null,
            'social_id'             => null,
            'social_token'          => null,
            'email_verify_token'    => null,
            'email_verify_token_at' => null,
            'point_balance'         => 0,
            'is_active'             => 0,
            'withdrawn_at'          => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);
    }

    /** 개인정보가 남는 부수 테이블 정리 + 포인트·쿠폰 소멸 */
    private function cleanupPersonalData(int $userId, int $pointBalance): void
    {
        $db = db_connect();

        // 배송지·장바구니·찜·재입고알림에는 개인정보나 취향 정보가 남는다
        foreach (['cart_items', 'wishlists', 'shipping_addresses', 'restock_alerts'] as $table) {
            $db->table($table)->where('user_id', $userId)->delete();
        }

        // 쿠폰은 행을 지우지 않는다 — uniq(user_id, coupon_id) 가 재발급 이력을 지탱한다
        $db->table('user_coupons')
            ->where('user_id', $userId)
            ->where('status', 'issued')
            ->update(['status' => 'expired']);

        // 포인트 소멸 기록. point_logs.type ENUM 에 'withdraw' 가 없어 'admin' 을 쓴다
        if ($pointBalance > 0) {
            $db->table('point_logs')->insert([
                'user_id'    => $userId,
                'type'       => 'admin',
                'amount'     => -$pointBalance,
                'note'       => '회원탈퇴로 인한 포인트 소멸',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * 차단 대상 주문을 한 번의 조회로 상태별 집계 (N+1 방지)
     *
     * @return array{order: int, claim: int}
     */
    private function countBlockingOrders(int $userId): array
    {
        $all = array_merge(self::BLOCKING_ORDER_STATUSES, self::BLOCKING_CLAIM_STATUSES);

        $rows = db_connect()->table('orders')
            ->select('status, COUNT(*) AS cnt')
            ->where('user_id', $userId)
            ->whereIn('status', $all)
            ->groupBy('status')
            ->get()->getResultArray();

        $order = 0;
        $claim = 0;
        foreach ($rows as $row) {
            $cnt = (int) $row['cnt'];
            if (in_array($row['status'], self::BLOCKING_ORDER_STATUSES, true)) {
                $order += $cnt;
            } else {
                $claim += $cnt;
            }
        }

        return ['order' => $order, 'claim' => $claim];
    }
}
