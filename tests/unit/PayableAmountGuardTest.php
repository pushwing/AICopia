<?php

declare(strict_types=1);

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 결제 가능 금액 검증 — OrderModel::validatePayableAmount()
 *
 * 쿠폰·포인트로 전액이 차감되면 payable_amount 가 0 이 되는데, 이 주문은
 * PG 결제창에 0원을 요청하게 되어 결제가 성립하지 않는다(무통장입금도
 * "0원 입금" 안내가 되어 마찬가지). 주문 생성 단계에서 막아야 한다.
 *
 * @internal
 */
final class PayableAmountGuardTest extends CIUnitTestCase
{
    private OrderModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new OrderModel();
    }

    // ─── 0원 주문 차단 ────────────────────────────────────────────────────────

    /** 쿠폰·포인트로 전액 차감된 주문은 거부한다. */
    public function testZeroPayableIsRejected(): void
    {
        $error = $this->model->validatePayableAmount(0, 10000);

        $this->assertNotNull($error, '0원 주문이 통과했습니다.');
        $this->assertStringContainsString('결제할 금액', $error);
    }

    /**
     * 최소 결제 금액 설정이 0(또는 미설정)이어도 0원 주문은 막아야 한다.
     * 최소금액 검증에 기대는 방식은 이 구멍을 놓친다.
     */
    public function testZeroPayableIsRejectedEvenWhenMinimumIsZero(): void
    {
        $error = $this->model->validatePayableAmount(0, 0);

        $this->assertNotNull($error, '최소 결제 금액이 0이면 0원 주문이 통과합니다.');
        $this->assertStringContainsString('결제할 금액', $error);
    }

    /** 음수는 발생해선 안 되지만, 들어와도 0원과 같이 막는다. */
    public function testNegativePayableIsRejected(): void
    {
        $this->assertNotNull($this->model->validatePayableAmount(-1, 0));
    }

    // ─── 최소 결제 금액 ───────────────────────────────────────────────────────

    /** 1원 이상이지만 최소 결제 금액에 못 미치면 기존 안내 문구로 거부한다. */
    public function testBelowMinimumIsRejectedWithMinimumMessage(): void
    {
        $error = $this->model->validatePayableAmount(5000, 10000);

        $this->assertNotNull($error);
        $this->assertStringContainsString('최소 결제 금액', $error);
        $this->assertStringContainsString('10,000', $error);
    }

    /** 최소 결제 금액과 같으면 통과한다(경계값). */
    public function testExactlyMinimumPasses(): void
    {
        $this->assertNull($this->model->validatePayableAmount(10000, 10000));
    }

    /** 최소 결제 금액 설정이 없을 때는 1원 이상이면 통과한다. */
    public function testAnyPositiveAmountPassesWhenNoMinimum(): void
    {
        $this->assertNull($this->model->validatePayableAmount(1, 0));
    }

    /** 정상 주문(쿠폰·포인트 차감 후 42,000원)은 통과한다. */
    public function testDiscountedButPositiveOrderPasses(): void
    {
        $this->assertNull($this->model->validatePayableAmount(42000, 10000));
    }
}
