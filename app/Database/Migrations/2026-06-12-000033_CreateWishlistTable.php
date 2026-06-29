<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWishlistTable extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE wishlists (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id    INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_user_product (user_id, product_id),
                KEY idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS wishlists');
    }
}
