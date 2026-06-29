<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSupplierBusinessFields extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('suppliers', [
            'business_no' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'default'    => null,
                'after'      => 'name',
            ],
            'business_license_path' => [
                'type'    => 'VARCHAR',
                'constraint' => 255,
                'null'    => true,
                'default' => null,
                'after'   => 'business_no',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('suppliers', ['business_no', 'business_license_path']);
    }
}
