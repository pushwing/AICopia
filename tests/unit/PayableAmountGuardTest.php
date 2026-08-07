<?php

declare(strict_types=1);

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 결제 가능 금액 검증 — OrderModel::validatePayableAmount()
 *
 * payable_amount 가 0 인 주문(100% 할인 쿠폰·포인트 전액 사용)은 PG 를 거치지
 * 않고 즉시 확정하는 무료 주문 경로로 간다(→ confirmFree, FreeOrderTest 참조).
 * 따라서 0원은 여기서 통과시키고, 1원 이상일 때만 최소 결제 금액을 따진다.
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

    // ─── 0원 주문 = 무료 주문 경로 ────────────────────────────────────────────

    /** 쿠폰·포인트로 전액 차감된 주문은 무료 주문으로 통과시킨다. */
    public function testZeroPayableIsAllowedAsFreeOrder(): void
    {
        $this->assertNull($this->model->validatePayableAmount(0, 10000));
    }

    /** 무료 주문은 최소 결제 금액 검사 대상이 아니다 — 결제 자체가 없다. */
    public function testZeroPayableIgnoresMinimumRegardlessOfSetting(): void
    {
        $this->assertNull($this->model->validatePayableAmount(0, 0));
        $this->assertNull($this->model->validatePayableAmount(0, 50000));
    }

    /** 음수는 계산 오류를 뜻하므로 무료 주문으로 넘기지 않고 거부한다. */
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
