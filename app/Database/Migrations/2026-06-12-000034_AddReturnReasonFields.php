<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddReturnReasonFields extends Migration
{
    public function up()
    {
        $this->forge->addColumn('orders', [
            'return_reason_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'default'    => null,
                'after'      => 'return_reason',
            ],
            'return_reason_note' => [
                'type'    => 'TEXT',
                'null'    => true,
                'default' => null,
                'after'   => 'return_reason_code',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('orders', 'return_reason_code');
        $this->forge->dropColumn('orders', 'return_reason_note');
    }
}
