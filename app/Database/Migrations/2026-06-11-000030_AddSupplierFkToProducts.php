<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSupplierFkToProducts extends Migration
{
    public function up(): void
    {
        // 인덱스 추가 (FK 성능용 + ON DELETE SET NULL 지원)
        $this->forge->addKey('supplier_id', false, false, 'products');

        $this->db->query(
            'ALTER TABLE products
             ADD CONSTRAINT fk_products_supplier
             FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
             ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE products DROP FOREIGN KEY fk_products_supplier');
        $this->db->query('ALTER TABLE products DROP INDEX supplier_id');
    }
}
