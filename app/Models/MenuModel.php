<?php

namespace App\Models;

use CodeIgniter\Model;

class MenuModel extends Model
{
    protected $table      = 'menus';
    protected $primaryKey = 'id';
    protected $allowedFields = ['parent_id', 'title', 'url', 'target', 'sort_order', 'is_active'];

    /**
     * 트리 구조로 메뉴 반환 (캐시 1시간)
     */
    public function getTree(): array
    {
        return (array) cache()->remember('nav_menus', 3600, function () {
            $all    = $this->db->table($this->table)->where('is_active', 1)->orderBy('sort_order')->get()->getResultArray();
            $tree   = [];
            $map    = [];

            foreach ($all as $item) {
                $item['children'] = [];
                $map[$item['id']] = $item;
            }
            foreach ($map as $item) {
                if ($item['parent_id'] && isset($map[$item['parent_id']])) {
                    $map[$item['parent_id']]['children'][] = $item;
                } else {
                    $tree[] = $item;
                }
            }
            return $tree;
        });
    }

    public function clearCache(): void
    {
        cache()->delete('nav_menus');
    }
}
