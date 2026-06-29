<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedSmtpSettings extends Migration
{
    private array $keys = [
        'smtp_host',
        'smtp_port',
        'smtp_user',
        'smtp_pass',
        'smtp_crypto',
        'smtp_from',
    ];

    public function up(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [
            ['group' => 'contact', 'key' => 'smtp_host',   'value' => '',    'label' => 'SMTP 호스트',            'type' => 'text',     'updated_at' => $now],
            ['group' => 'contact', 'key' => 'smtp_port',   'value' => '587', 'label' => 'SMTP 포트',              'type' => 'text',     'updated_at' => $now],
            ['group' => 'contact', 'key' => 'smtp_user',   'value' => '',    'label' => 'SMTP 사용자 (이메일)',    'type' => 'text',     'updated_at' => $now],
            ['group' => 'contact', 'key' => 'smtp_pass',   'value' => '',    'label' => 'SMTP 비밀번호',          'type' => 'password', 'updated_at' => $now],
            ['group' => 'contact', 'key' => 'smtp_crypto', 'value' => 'tls', 'label' => 'SMTP 암호화',            'type' => 'text',     'updated_at' => $now],
            ['group' => 'contact', 'key' => 'smtp_from',   'value' => '',    'label' => '발신 이메일 주소',        'type' => 'text',     'updated_at' => $now],
        ];

        foreach ($rows as $row) {
            $exists = $this->db->table('settings')->where('key', $row['key'])->countAllResults();
            if (! $exists) {
                $this->db->table('settings')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('settings')->whereIn('key', $this->keys)->delete();
    }
}
