<?php

namespace App\Models;

use App\Traits\HasSlug;
use CodeIgniter\Model;

class CategoryModel extends Model
{
    use HasSlug;
    protected $table      = 'categories';
    protected $primaryKey = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['parent_id', 'name', 'slug', 'sort_order', 'is_active'];

    // 자동 캐시 삭제 없음 — 관리자 수동 "쇼핑몰 적용" 버튼으로만 갱신

    /**
     * 대분류(parent_id=null) → 소분류 트리 반환 (캐시 1시간)
     * 반환 구조: [['id'=>1,'name'=>'의류','children'=>[...]], ...]
     */
    public function getTree(): array
    {
        return (array) cache()->remember('category_tree', 0, function () {
            $all = $this->db->table($this->table)->where('is_active', 1)->orderBy('sort_order')->get()->getResultArray();

            $parents = [];
            $children = [];
            foreach ($all as $row) {
                if ($row['parent_id'] === null) {
                    $parents[$row['id']] = $row + ['children' => []];
                } else {
                    $children[$row['parent_id']][] = $row;
                }
            }
            foreach ($children as $pid => $rows) {
                if (isset($parents[$pid])) {
                    $parents[$pid]['children'] = $rows;
                }
            }
            return array_values($parents);
        });
    }

    /**
     * 캐시 없이 DB 직접 조회 (관리자 페이지용)
     */
    public function getTreeDirect(): array
    {
        $all      = $this->db->table($this->table)->orderBy('sort_order')->get()->getResultArray();
        $parents  = [];
        $children = [];
        foreach ($all as $row) {
            if ($row['parent_id'] === null) {
                $parents[$row['id']] = $row + ['children' => []];
            } else {
                $children[$row['parent_id']][] = $row;
            }
        }
        foreach ($children as $pid => $rows) {
            if (isset($parents[$pid])) {
                $parents[$pid]['children'] = $rows;
            }
        }
        return array_values($parents);
    }

    /**
     * 소분류 id → 대분류 row 반환 (캐시에서 탐색)
     */
    public function getParent(int $childId): ?array
    {
        $child = $this->find($childId);
        if (! $child || ! $child['parent_id']) return null;
        return $this->find($child['parent_id']);
    }

    public function clearCache(): void
    {
        cache()->delete('category_tree');
    }

}
