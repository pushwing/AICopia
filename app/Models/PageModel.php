<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class PageModel extends Model
{
    #[\Override]
    protected $table      = 'pages';
    #[\Override]
    protected $primaryKey = 'id';
    #[\Override]
    protected $useTimestamps = true;
    #[\Override]
    protected $allowedFields = [
        'slug', 'title', 'content', 'layout',
        'meta_title', 'meta_desc', 'og_image', 'sort_order', 'status',
    ];

    /** @return array<string, mixed>|null */
    public function getBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->where('status', 'published')->first();
    }

    /** @return array<int, array<string, mixed>> */
    public function getPublished(): array
    {
        return $this->where('status', 'published')->orderBy('sort_order')->findAll();
    }
}
