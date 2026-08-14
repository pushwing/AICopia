<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedGtmSetting extends Migration
{
    public function up(): void
    {
        $exists = $this->db->table('settings')->where('key', 'gtm_id')->countAllResults();
        if ($exists === 0) {
            $this->db->table('settings')->insert([
                'group'      => 'seo',
                'key'        => 'gtm_id',
                'value'      => '',
                'label'      => 'GTM 컨테이너 ID',
                'type'       => 'text',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('settings')->where('key', 'gtm_id')->delete();
    }
}
