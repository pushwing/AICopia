<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 무료 주문(payable_amount = 0) 결제 이력을 남기기 위한 ENUM 확장.
 *
 * 100% 할인 쿠폰·포인트 전액 사용으로 실결제액이 0원이 된 주문은 PG 를 거치지
 * 않으므로, pg_provider/method 에 기록할 값이 필요하다.
 */
class AddFreePaymentProvider extends Migration
{
    public function up()
    {
        $this->db->query("
            ALTER TABLE payments
            MODIFY COLUMN pg_provider
                ENUM('free','bank_transfer','toss','inicis','nicepay','kakaopay','naverpay','payco')
                NOT NULL
        ");

        $this->db->query("
            ALTER TABLE payments
            MODIFY COLUMN method
                ENUM('free','card','virtual_account','transfer','phone','kakaopay','naverpay','payco','무통장입금')
                NULL
        ");
    }

    public function down()
    {
        // 되돌리기 전에 무료 주문 이력이 남아 있으면 ENUM 축소가 데이터를 잃는다.
        $this->db->query("DELETE FROM payments WHERE pg_provider = 'free'");

        $this->db->query("
            ALTER TABLE payments
            MODIFY COLUMN pg_provider
                ENUM('bank_transfer','toss','inicis','nicepay','kakaopay','naverpay','payco')
                NOT NULL
        ");

        $this->db->query("
            ALTER TABLE payments
            MODIFY COLUMN method
                ENUM('card','virtual_account','transfer','phone','kakaopay','naverpay','payco','무통장입금')
                NULL
        ");
    }
}
