<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\CouponController;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * POST /coupon/check — 쿠폰 코드 AJAX 검증 응답
 *
 * 유효한 쿠폰일 때 응답 라벨을 조립하는 구간이 DB 에서 온 값(문자열)을
 * number_format() 에 그대로 넘겨 strict_types 아래에서 TypeError(500) 로
 * 터지던 회귀를 막는다. 쿠폰이 검증에서 걸러질 때는 이 구간을 타지 않아
 * 정상 메시지가 나오고, 검증을 통과하는 순간에만 500 이 났다.
 */
final class CouponCheckEndpointTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** @var list<int> */
    private array $couponIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->couponIds !== []) {
            $db->table('coupons')->whereIn('id', $this->couponIds)->delete();
        }
        if ($this->userIds !== []) {
            $db->table('users')->whereIn('id', $this->userIds)->delete();
        }

        $this->couponIds = [];
        $this->userIds   = [];

        parent::tearDown();
    }

    /** @param array<string, mixed> $extra */
    private function insertCoupon(array $extra = []): string
    {
        $code = 'CHK' . strtoupper(substr(uniqid(), -8));
        $db   = db_connect();
        $db->table('coupons')->insert(array_merge([
            'code'                => $code,
            'name'                => '체크쿠폰',
            'type'                => 'fixed',
            'target_grade'        => null,
            'discount_value'      => 3000,
            'min_order_amount'    => 0,
            'max_discount_amount' => 0,
            'total_qty'           => null,
            'used_count'          => 0,
            'per_user_limit'      => 1,
            'starts_at'           => null,
            'expires_at'          => null,
            'is_active'           => 1,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ], $extra));
        $this->couponIds[] = (int) $db->insertID();

        return $code;
    }

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'chk_' . $uid,
            'email'         => 'chk-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'ChkUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id              = (int) $db->insertID();
        $this->userIds[] = $id;

        return $id;
    }

    /** @return array<string, mixed> */
    private function callCheck(string $code, int $orderAmount, int $userId): array
    {
        session()->set('user_id', $userId);

        // CI4 4.7 은 superglobals 서비스가 $_POST 를 스냅샷하므로 $_POST 직접 대입은
        // 반영되지 않는다. request 의 전역을 직접 세팅한다.
        $request = \Config\Services::request(null, false);
        $request->setGlobal('post', [
            'coupon_code'  => $code,
            'order_amount' => (string) $orderAmount,
        ]);

        $controller = new CouponController();
        $controller->initController($request, service('response'), service('logger'));

        $body = $controller->check()->getBody();

        return json_decode((string) $body, true);
    }

    /** 정액 쿠폰 — 검증 통과 시 라벨까지 조립되어야 한다 (회귀: 여기서 500) */
    public function testCheckReturnsLabelForValidFixedCoupon(): void
    {
        $userId = $this->insertUser();
        $code   = $this->insertCoupon(['type' => 'fixed', 'discount_value' => 3000]);

        $result = $this->callCheck($code, 10000, $userId);

        $this->assertTrue($result['valid'], var_export($result, true));
        $this->assertSame(3000, $result['discount']);
        $this->assertStringContainsString('3,000', $result['label']);
    }

    /** 정률 쿠폰 + 최대 할인 상한 — 라벨 조립 구간이 상한값도 포맷한다 */
    public function testCheckReturnsLabelForValidPercentCouponWithCap(): void
    {
        $userId = $this->insertUser();
        $code   = $this->insertCoupon([
            'type'                => 'percent',
            'discount_value'      => 50,
            'max_discount_amount' => 3000,
        ]);

        $result = $this->callCheck($code, 10000, $userId);

        $this->assertTrue($result['valid'], var_export($result, true));
        $this->assertSame(3000, $result['discount']);
        $this->assertNotEmpty($result['label']);
    }

    /** 검증에서 걸러질 때는 구체적인 실패 메시지가 그대로 내려간다 */
    public function testCheckReturnsSpecificMessageWhenRejected(): void
    {
        $userId = $this->insertUser();
        $code   = $this->insertCoupon(['starts_at' => date('Y-m-d H:i:s', strtotime('+1 day'))]);

        $result = $this->callCheck($code, 10000, $userId);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['message'], '메시지가 비면 프론트가 폴백 문구를 띄운다');
    }
}
