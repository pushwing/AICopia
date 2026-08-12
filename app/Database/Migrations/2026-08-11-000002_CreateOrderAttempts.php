<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOrderAttempts extends Migration
{
    public function up()
    {
        // 결제 확정 전 주문 시도. 결제가 확정되면 orders 로 전환되고 order_id 가 채워진다.
        $this->forge->addField([
            'id'                     => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'                => ['type' => 'INT', 'unsigned' => true],
            'order_number'           => ['type' => 'VARCHAR', 'constraint' => 30],
            'status'                 => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'converted', 'failed', 'expired'],
                'default'    => 'pending',
            ],
            // 금액 스냅샷 — PG 승인 금액 검증에 쓰인다.
            'total_product_price'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'shipping_fee'           => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'total_amount'           => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'coupon_id'              => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'coupon_discount_amount' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'point_used_amount'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'point_earned_amount'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'payable_amount'         => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            // 배송지 스냅샷
            'receiver_name'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'receiver_phone'         => ['type' => 'VARCHAR', 'constraint' => 20],
            'zipcode'                => ['type' => 'VARCHAR', 'constraint' => 10],
            'address1'               => ['type' => 'VARCHAR', 'constraint' => 200],
            'address2'               => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'delivery_memo'          => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            // order_items 로 그대로 전환 가능한 라인 배열
            'items_snapshot'         => ['type' => 'JSON', 'null' => true],
            // payments.pg_provider 와 달리 ENUM 이 아니다. PG 추가 시 두 테이블 ENUM 을
            // 함께 늘리는 결합을 만들지 않기 위해서다.
            'pg_provider'            => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'order_id'               => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'fail_reason'            => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'converted_at'           => ['type' => 'DATETIME', 'null' => true],
            'failed_at'              => ['type' => 'DATETIME', 'null' => true],
            'expired_at'             => ['type' => 'DATETIME', 'null' => true],
            'created_at'             => ['type' => 'DATETIME', 'null' => true],
            'updated_at'             => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('order_number', 'uniq_order_attempts_order_number');
        $this->forge->addKey('user_id', false, false, 'idx_order_attempts_user_id');
        $this->forge->addKey('order_id', false, false, 'idx_order_attempts_order_id');
        // 만료 스윕(status='pending' AND created_at < cutoff)과 로그 목록 정렬을 함께 커버한다.
        $this->forge->addKey(['status', 'created_at'], false, false, 'idx_order_attempts_status_created');
        $this->forge->createTable('order_attempts');

        // 쿠폰·포인트 선점의 소유자. 전환 전에는 attempt 를, 전환 후에는 order_id 를 함께 가리킨다.
        $this->forge->addColumn('user_coupons', [
            'order_attempt_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'order_id'],
        ]);
        $this->forge->addColumn('point_logs', [
            'order_attempt_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'order_id'],
        ]);
        $this->db->query('ALTER TABLE user_coupons ADD INDEX idx_user_coupons_order_attempt_id (order_attempt_id)');
        $this->db->query('ALTER TABLE point_logs ADD INDEX idx_point_logs_order_attempt_id (order_attempt_id)');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE point_logs DROP INDEX idx_point_logs_order_attempt_id');
        $this->db->query('ALTER TABLE user_coupons DROP INDEX idx_user_coupons_order_attempt_id');
        $this->forge->dropColumn('point_logs', 'order_attempt_id');
        $this->forge->dropColumn('user_coupons', 'order_attempt_id');
        $this->forge->dropTable('order_attempts', true);
    }
}
