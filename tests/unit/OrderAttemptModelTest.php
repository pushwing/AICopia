<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * OrderAttemptModel — 주문 시도 생명주기
 * 이슈 #214
 */
final class OrderAttemptModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    public function testOrderAttemptsTableExists(): void
    {
        $db = db_connect();

        $this->assertTrue($db->tableExists('order_attempts'));
        $this->assertTrue($db->fieldExists('items_snapshot', 'order_attempts'));
        $this->assertTrue($db->fieldExists('order_attempt_id', 'user_coupons'));
        $this->assertTrue($db->fieldExists('order_attempt_id', 'point_logs'));
    }
}
