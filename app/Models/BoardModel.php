<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class BoardModel extends Model
{
    protected $table      = 'boards';
    protected $primaryKey = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'slug', 'name', 'description',
        'read_permission', 'write_permission',
        'allow_file', 'allow_image',
        'posts_per_page', 'sort_order', 'is_active',
    ];

    /** @return array<string, mixed>|null */
    public function getBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->where('is_active', 1)->first();
    }

    /** @return array<int, array<string, mixed>> */
    public function getActiveBoards(): array
    {
        return $this->where('is_active', 1)->orderBy('sort_order')->findAll();
    }
}
