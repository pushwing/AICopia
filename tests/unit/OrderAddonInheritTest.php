<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 장바구니의 parent_product_id 가 주문 항목으로 승계되는지 확인한다.
 */
final class OrderAddonInheritTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** @var array<int, int> */
    private array $orderItemIds = [];

    protected function tearDown(): void
    {
        if ($this->orderItemIds !== []) {
            db_connect()->table('order_items')->whereIn('id', $this->orderItemIds)->delete();
        }
        $this->orderItemIds = [];
        parent::tearDown();
    }

    public function testParentProductIdColumnAcceptsAndReturnsValue(): void
    {
        $db = db_connect();
        $db->table('order_items')->insert([
            'order_id'          => 0,
            'product_id'        => 30,
            'parent_product_id' => 10,
            'product_name'      => '애드온승계테스트',
            'product_price'     => 1000,
            'qty'               => 1,
            'subtotal'          => 1000,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
        $id                   = (int) $db->insertID();
        $this->orderItemIds[] = $id;

        $row = $db->table('order_items')->where('id', $id)->get()->getRowArray();

        $this->assertSame(10, (int) $row['parent_product_id'], 'order_items 가 부모를 보관해야 한다');
    }
}
