<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOrderTables extends Migration
{
    public function up()
    {
        // 회원 배송지 이력 (주소록)
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'        => ['type' => 'INT', 'unsigned' => true],
            'receiver_name'  => ['type' => 'VARCHAR', 'constraint' => 100],
            'receiver_phone' => ['type' => 'VARCHAR', 'constraint' => 20],
            'zipcode'        => ['type' => 'VARCHAR', 'constraint' => 10],
            'address1'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'address2'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'is_default'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('user_id');
        $this->forge->createTable('shipping_addresses');

        // 주문
        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'             => ['type' => 'INT', 'unsigned' => true],
            'order_number'        => ['type' => 'VARCHAR', 'constraint' => 30],
            'status'              => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'paid', 'preparing', 'shipped', 'delivered', 'cancelled', 'expired', 'refund_requested', 'refunded'],
                'default'    => 'pending',
            ],
            // 금액 스냅샷 (PG 콜백 금액 검증에 사용)
            'total_product_price' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'shipping_fee'        => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'total_amount'        => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            // 배송지 스냅샷 (주문 시점 기준 보존)
            'receiver_name'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'receiver_phone'      => ['type' => 'VARCHAR', 'constraint' => 20],
            'zipcode'             => ['type' => 'VARCHAR', 'constraint' => 10],
            'address1'            => ['type' => 'VARCHAR', 'constraint' => 200],
            'address2'            => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'delivery_memo'       => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            // 운송장
            'tracking_company'    => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'tracking_number'     => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            // 상태 타임스탬프
            'paid_at'             => ['type' => 'DATETIME', 'null' => true],
            'cancelled_at'        => ['type' => 'DATETIME', 'null' => true],
            'expired_at'          => ['type' => 'DATETIME', 'null' => true],
            'created_at'          => ['type' => 'DATETIME', 'null' => true],
            'updated_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('user_id');
        $this->forge->addKey('status');
        $this->forge->addUniqueKey('order_number');
        $this->forge->createTable('orders');

        // 주문 상품 (상품 정보 스냅샷)
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'order_id'      => ['type' => 'INT', 'unsigned' => true],
            'product_id'    => ['type' => 'INT', 'unsigned' => true],
            'product_name'  => ['type' => 'VARCHAR', 'constraint' => 200],
            'product_price' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'qty'           => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'subtotal'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('order_id');
        $this->forge->addKey('product_id');
        $this->forge->createTable('order_items');

        // 결제
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'order_id'     => ['type' => 'INT', 'unsigned' => true],
            'pg_provider'  => [
                'type'       => 'ENUM',
                'constraint' => ['toss', 'inicis', 'nicepay', 'kakaopay', 'naverpay', 'payco'],
            ],
            'pg_tid'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'method'       => [
                'type'       => 'ENUM',
                'constraint' => ['card', 'virtual_account', 'transfer', 'phone', 'kakaopay', 'naverpay', 'payco'],
                'null'       => true,
            ],
            'amount'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'       => [
                'type'       => 'ENUM',
                'constraint' => ['ready', 'paid', 'failed', 'cancelled', 'refunded'],
                'default'    => 'ready',
            ],
            'raw_response' => ['type' => 'JSON', 'null' => true],
            'paid_at'      => ['type' => 'DATETIME', 'null' => true],
            'cancelled_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('order_id');
        $this->forge->addUniqueKey('pg_tid');
        $this->forge->createTable('payments');
    }

    public function down()
    {
        $this->forge->dropTable('payments', true);
        $this->forge->dropTable('order_items', true);
        $this->forge->dropTable('orders', true);
        $this->forge->dropTable('shipping_addresses', true);
    }
}
