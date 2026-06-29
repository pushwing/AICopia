<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddActiveThemeSetting extends Migration
{
    public function up()
    {
        $now = date('Y-m-d H:i:s');

        $existing = $this->db->table('settings')->where('key', 'active_theme')->get()->getRow();
        if (! $existing) {
            $this->db->table('settings')->insert([
                'group'      => 'theme',
                'key'        => 'active_theme',
                'value'      => 'default',
                'label'      => '활성 테마',
                'type'       => 'text',
                'updated_at' => $now,
            ]);
        }
    }

    public function down()
    {
        $this->db->table('settings')->where('key', 'active_theme')->delete();
    }
}
