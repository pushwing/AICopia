<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddExchangeReasonAndTrackingToOrders extends Migration
{
    public function up()
    {
        $this->forge->addColumn('orders', [
            'exchange_reason_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'default'    => null,
                'after'      => 'exchange_reason',
            ],
            'exchange_return_tracking_company' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => null,
                'after'      => 'exchange_request_note',
            ],
            'exchange_return_tracking_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => null,
                'after'      => 'exchange_return_tracking_company',
            ],
            'exchange_tracking_company' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => null,
                'after'      => 'exchange_return_tracking_number',
            ],
            'exchange_tracking_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => null,
                'after'      => 'exchange_tracking_company',
            ],
            'exchange_seller_shipping_fee' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'default'  => null,
                'after'    => 'exchange_tracking_number',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('orders', 'exchange_reason_code');
        $this->forge->dropColumn('orders', 'exchange_return_tracking_company');
        $this->forge->dropColumn('orders', 'exchange_return_tracking_number');
        $this->forge->dropColumn('orders', 'exchange_tracking_company');
        $this->forge->dropColumn('orders', 'exchange_tracking_number');
        $this->forge->dropColumn('orders', 'exchange_seller_shipping_fee');
    }
}
