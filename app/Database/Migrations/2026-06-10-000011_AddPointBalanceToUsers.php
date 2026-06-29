<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPointBalanceToUsers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'point_balance' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
                'after'    => 'is_active',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'point_balance');
    }
}
