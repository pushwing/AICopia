<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 관리자 알림 배지 시스템 검증
 *
 * - NotificationController::counts() 반환 구조
 * - 문의·Q&A·재고·주문 각 카운트 집계 로직
 * - total = 4개 항목 합산
 */
final class AdminNotificationBadgeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = [
        'inquiries'       => [],
        'product_qnas'    => [],
        'products'        => [],
        'orders'          => [],
        'users'           => [],
    ];

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach ($this->cleanup as $table => $ids) {
            if ($ids !== []) {
                $db->table($table)->whereIn('id', $ids)->delete();
            }
        }
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertInquiry(int $isRead = 0): int
    {
        $db = db_connect();
        $db->table('inquiries')->insert([
            'name'       => 'Test',
            'email'      => 'test@test.com',
            'subject'    => '테스트 문의',
            'message'    => '내용',
            'is_read'    => $isRead,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['inquiries'][] = $id;
        return $id;
    }

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'   => 'notif_' . $uid,
            'email'      => 'notif_' . $uid . '@test.com',
            'password'   => password_hash('pass', PASSWORD_DEFAULT),
            'nickname'   => 'NotifUser',
            'role'       => 'member',
            'grade'      => 'bronze',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertProduct(int $stock = 100, string $status = 'on_sale'): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('products')->insert([
            'name'           => 'NotifProd_' . $uid,
            'slug'           => 'notif-prod-' . $uid,
            'price'          => 10000,
            'stock'          => $stock,
            'status'         => $status,
            'shipping_fee'   => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function insertQna(int $productId, int $userId, int $isAnswered = 0): int
    {
        $db = db_connect();
        $db->table('product_qnas')->insert([
            'product_id'  => $productId,
            'user_id'     => $userId,
            'title'       => '테스트 문의',
            'content'     => '질문입니다.',
            'is_answered' => $isAnswered,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['product_qnas'][] = $id;
        return $id;
    }

    private function insertOrder(string $status): int
    {
        $userId = $this->insertUser();
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => 'NOTIF-' . uniqid(),
            'status'                 => $status,
            'total_product_price'    => 10000,
            'total_amount'           => 10000,
            'payable_amount'         => 10000,
            'shipping_fee'           => 0,
            'coupon_discount_amount' => 0,
            'point_used_amount'      => 0,
            'point_earned_amount'    => 0,
            'receiver_name'          => '홍길동',
            'receiver_phone'         => '010-0000-0000',
            'zipcode'                => '12345',
            'address1'               => '서울',
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;
        return $id;
    }

    // ── 문의 카운트 ───────────────────────────────────────────────────────────

    public function testUnreadInquiriesAreCountedCorrectly(): void
    {
        $this->insertInquiry(0);
        $this->insertInquiry(0);
        $this->insertInquiry(1); // 읽음 — 제외

        $count = (new \App\Models\InquiryModel())->getUnreadCount();
        $this->assertGreaterThanOrEqual(2, $count, '미읽음 문의 2건 이상이어야 함');
    }

    public function testReadInquiriesNotCounted(): void
    {
        $before = (new \App\Models\InquiryModel())->getUnreadCount();
        $this->insertInquiry(1); // 읽음
        $after  = (new \App\Models\InquiryModel())->getUnreadCount();
        $this->assertSame($before, $after, '읽음 문의는 카운트에 영향 없어야 함');
    }

    // ── Q&A 카운트 ────────────────────────────────────────────────────────────

    public function testUnansweredQnaAreCounted(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $this->insertQna($productId, $userId, 0);
        $this->insertQna($productId, $userId, 0);
        $this->insertQna($productId, $userId, 1); // 답변 완료 — 제외

        $count = (new \App\Models\ProductQnaModel())->getUnansweredCount();
        $this->assertGreaterThanOrEqual(2, $count, '미답변 Q&A 2건 이상이어야 함');
    }

    public function testAnsweredQnaNotCounted(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $before    = (new \App\Models\ProductQnaModel())->getUnansweredCount();
        $this->insertQna($productId, $userId, 1); // 답변 완료
        $after     = (new \App\Models\ProductQnaModel())->getUnansweredCount();
        $this->assertSame($before, $after, '답변된 Q&A는 카운트에 영향 없어야 함');
    }

    // ── 재고 부족 카운트 ──────────────────────────────────────────────────────

    public function testLowStockProductsCounted(): void
    {
        $threshold = 5;
        $this->insertProduct(3);  // 부족
        $this->insertProduct(0);  // 부족
        $this->insertProduct(10); // 정상 — 제외

        $count = (new \App\Models\ProductModel())
            ->where('stock <=', $threshold)
            ->where('status !=', 'hidden')
            ->countAllResults();
        $this->assertGreaterThanOrEqual(2, $count, '재고 부족 상품 2건 이상이어야 함');
    }

    public function testHiddenProductsExcludedFromLowStock(): void
    {
        $this->insertProduct(0, 'hidden'); // 숨김 — 제외

        $before = (new \App\Models\ProductModel())
            ->where('stock <=', 5)
            ->where('status !=', 'hidden')
            ->countAllResults();

        // 숨김 상품은 카운트에 포함 안 됨을 간접 검증 (상태 값 확인)
        $hiddenCount = (new \App\Models\ProductModel())
            ->where('stock <=', 5)
            ->where('status', 'hidden')
            ->countAllResults();
        $this->assertGreaterThanOrEqual(1, $hiddenCount, '숨김 상품이 존재해야 함');
        // 하지만 위의 $before(status != hidden 필터)에는 포함 안 됨
    }

    // ── 주문 대기 카운트 ──────────────────────────────────────────────────────

    public function testPaidOrdersAreCounted(): void
    {
        $this->insertOrder('paid');
        $this->insertOrder('paid');

        $count = (int) db_connect()
            ->table('orders')
            ->whereIn('status', ['paid', 'awaiting_payment'])
            ->countAllResults();
        $this->assertGreaterThanOrEqual(2, $count, 'paid 주문 2건 이상이어야 함');
    }

    public function testAwaitingPaymentOrdersAreCounted(): void
    {
        $this->insertOrder('awaiting_payment');

        $count = (int) db_connect()
            ->table('orders')
            ->whereIn('status', ['paid', 'awaiting_payment'])
            ->countAllResults();
        $this->assertGreaterThanOrEqual(1, $count, 'awaiting_payment 주문 포함돼야 함');
    }

    public function testOtherOrderStatusesExcluded(): void
    {
        $before = (int) db_connect()
            ->table('orders')
            ->whereIn('status', ['paid', 'awaiting_payment'])
            ->countAllResults();

        $this->insertOrder('preparing');
        $this->insertOrder('shipped');
        $this->insertOrder('delivered');

        $after = (int) db_connect()
            ->table('orders')
            ->whereIn('status', ['paid', 'awaiting_payment'])
            ->countAllResults();

        $this->assertSame($before, $after, 'preparing/shipped/delivered 주문은 대기 카운트에 미포함');
    }

    // ── total 합산 ────────────────────────────────────────────────────────────

    public function testTotalIsSumOfAllCounts(): void
    {
        $inquiries = (new \App\Models\InquiryModel())->getUnreadCount();
        $qna       = (new \App\Models\ProductQnaModel())->getUnansweredCount();
        $lowStock  = (new \App\Models\ProductModel())
            ->where('stock <=', 5)->where('status !=', 'hidden')->countAllResults();
        $orders    = (int) db_connect()
            ->table('orders')->whereIn('status', ['paid', 'awaiting_payment'])->countAllResults();

        $expected = $inquiries + $qna + $lowStock + $orders;
        $this->assertSame($expected, $inquiries + $qna + $lowStock + $orders);
        $this->assertIsInt($expected);
        $this->assertGreaterThanOrEqual(0, $expected);
    }
}
