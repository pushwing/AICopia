<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterOrderEnums extends Migration
{
    public function up()
    {
        // orders.status에 'awaiting_payment' 추가
        $this->db->query("
            ALTER TABLE orders
            MODIFY COLUMN status
                ENUM('pending','awaiting_payment','paid','preparing','shipped','delivered','cancelled','expired','refund_requested','refunded')
                NOT NULL DEFAULT 'pending'
        ");

        // payments.pg_provider에 'bank_transfer' 추가
        $this->db->query("
            ALTER TABLE payments
            MODIFY COLUMN pg_provider
                ENUM('bank_transfer','toss','inicis','nicepay','kakaopay','naverpay','payco')
                NOT NULL
        ");

        // payments.status에 'pending' 추가
        $this->db->query("
            ALTER TABLE payments
            MODIFY COLUMN status
                ENUM('pending','ready','paid','failed','cancelled','refunded')
                NOT NULL DEFAULT 'ready'
        ");

        // payments.method에 '무통장입금' 추가
        $this->db->query("
            ALTER TABLE payments
            MODIFY COLUMN method
                ENUM('card','virtual_account','transfer','phone','kakaopay','naverpay','payco','무통장입금')
                NULL
        ");
    }

    public function down()
    {
        $this->db->query("
            ALTER TABLE orders
            MODIFY COLUMN status
                ENUM('pending','paid','preparing','shipped','delivered','cancelled','expired','refund_requested','refunded')
                NOT NULL DEFAULT 'pending'
        ");

        $this->db->query("
            ALTER TABLE payments
            MODIFY COLUMN pg_provider
                ENUM('toss','inicis','nicepay','kakaopay','naverpay','payco')
                NOT NULL
        ");

        $this->db->query("
            ALTER TABLE payments
            MODIFY COLUMN status
                ENUM('ready','paid','failed','cancelled','refunded')
                NOT NULL DEFAULT 'ready'
        ");

        $this->db->query("
            ALTER TABLE payments
            MODIFY COLUMN method
                ENUM('card','virtual_account','transfer','phone','kakaopay','naverpay','payco')
                NULL
        ");
    }
}
