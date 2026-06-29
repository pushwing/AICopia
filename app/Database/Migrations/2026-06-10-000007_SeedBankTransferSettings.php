<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedBankTransferSettings extends Migration
{
    public function up()
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            ['group' => 'shop', 'key' => 'bank_name',    'value' => '',  'label' => '무통장 은행명',    'type' => 'text', 'updated_at' => $now],
            ['group' => 'shop', 'key' => 'bank_account', 'value' => '',  'label' => '무통장 계좌번호',   'type' => 'text', 'updated_at' => $now],
            ['group' => 'shop', 'key' => 'bank_holder',  'value' => '',  'label' => '무통장 예금주',    'type' => 'text', 'updated_at' => $now],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('settings')->where('key', $row['key'])->countAllResults();
            if (! $exists) {
                $this->db->table('settings')->insert($row);
            }
        }
    }

    public function down()
    {
        $this->db->table('settings')
            ->whereIn('key', ['bank_name', 'bank_account', 'bank_holder'])
            ->delete();
    }
}
