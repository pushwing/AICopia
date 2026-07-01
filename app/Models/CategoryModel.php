<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasSlug;
use CodeIgniter\Model;

class CategoryModel extends Model
{
    use HasSlug;
    protected $table      = 'categories';
    protected $primaryKey = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['parent_id', 'name', 'description', 'faq', 'slug', 'sort_order', 'is_active'];

    /**
     * 카테고리 FAQ(JSON 문자열)를 [{question, answer}] 배열로 디코드.
     * 질문·답변이 모두 있는 항목만 반환한다.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    public static function decodeFaq(?string $faqJson): array
    {
        if ($faqJson === null || trim($faqJson) === '') {
            return [];
        }

        $decoded = json_decode($faqJson, true);
        if (! is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $q = trim((string) ($row['question'] ?? ''));
            $a = trim((string) ($row['answer'] ?? ''));
            if ($q !== '' && $a !== '') {
                $items[] = ['question' => $q, 'answer' => $a];
            }
        }

        return $items;
    }

    /**
     * "질문 || 답변" 형식의 여러 줄 텍스트를 FAQ JSON 문자열로 변환.
     * 유효한 항목이 없으면 null 반환.
     */
    public static function encodeFaqFromLines(string $raw): ?string
    {
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (! str_contains($line, '||')) {
                continue;
            }
            [$q, $a] = array_map('trim', explode('||', $line, 2));
            if ($q !== '' && $a !== '') {
                $items[] = ['question' => $q, 'answer' => $a];
            }
        }

        return $items === [] ? null : json_encode($items, JSON_UNESCAPED_UNICODE);
    }

    /**
     * FAQ(JSON)를 관리자 편집용 "질문 || 답변" 여러 줄 텍스트로 변환.
     */
    public static function faqToLines(?string $faqJson): string
    {
        $lines = [];
        foreach (self::decodeFaq($faqJson) as $item) {
            $lines[] = $item['question'] . ' || ' . $item['answer'];
        }

        return implode("\n", $lines);
    }

    // 자동 캐시 삭제 없음 — 관리자 수동 "쇼핑몰 적용" 버튼으로만 갱신

    /**
     * 대분류(parent_id=null) → 소분류 트리 반환 (캐시 1시간)
     * 반환 구조: [['id'=>1,'name'=>'의류','children'=>[...]], ...]
     *
     * @return array<int, array<string, mixed>>
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
     *
     * @return array<int, array<string, mixed>>
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
     *
     * @return array<string, mixed>|null
     */
    public function getParent(int $childId): ?array
    {
        $child = $this->find($childId);
        if (! $child || ! $child['parent_id']) {
            return null;
        }
        return $this->find($child['parent_id']);
    }

    public function clearCache(): void
    {
        cache()->delete('category_tree');
    }

}
