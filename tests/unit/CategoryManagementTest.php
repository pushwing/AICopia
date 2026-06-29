<?php

namespace Tests\Unit;

use App\Models\CategoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 카테고리 관리 기능 검증
 *
 * - CategoryModel::getTreeDirect() : 캐시 없이 DB 직접 조회 (비활성 포함)
 * - CategoryModel::clearCache()    : category_tree 캐시 키 삭제
 * - categoryMove 로직               : ProductController::categoryMove() 와 동일한 로직
 *
 * 이동 테스트는 전용 부모 아래에 자식을 만들어 기존 DB 데이터와 격리한다.
 */
final class CategoryManagementTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = ['categories' => []];
    private CategoryModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new CategoryModel();
    }

    protected function tearDown(): void
    {
        cache()->delete('category_tree');
        if ($this->cleanup['categories'] !== []) {
            $db = db_connect();
            // 자식 먼저 삭제 (FK 제약 안전)
            $db->table('categories')->whereIn('id', $this->cleanup['categories'])
               ->where('parent_id IS NOT NULL', null, false)->delete();
            $db->table('categories')->whereIn('id', $this->cleanup['categories'])->delete();
        }
        $this->cleanup['categories'] = [];
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertCategory(
        string $name,
        int    $sortOrder = 0,
        int    $isActive  = 1,
        ?int   $parentId  = null
    ): int {
        $db = db_connect();
        $db->table('categories')->insert([
            'parent_id'  => $parentId,
            'name'       => $name,
            'slug'       => 'test-' . uniqid(),
            'sort_order' => $sortOrder,
            'is_active'  => $isActive,
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['categories'][] = $id;
        return $id;
    }

    /**
     * 전용 부모 아래에 자식 N개를 생성하여 격리된 형제 그룹 반환.
     *
     * @param  array<array{sort_order:int, is_active?:int}> $defs
     * @return int[]  자식 ID 배열 (부모 제외)
     */
    private function createIsolatedGroup(array $defs): array
    {
        $parent = $this->insertCategory('_IsoParent_' . uniqid(), 999);
        $ids    = [];
        foreach ($defs as $def) {
            $ids[] = $this->insertCategory(
                'IsoChild_' . uniqid(),
                $def['sort_order'],
                $def['is_active'] ?? 1,
                $parent
            );
        }
        return $ids;
    }

    /** 트리에서 부모+자식 모두의 ID를 int 배열로 수집 */
    private function collectAllIds(array $tree): array
    {
        $ids = [];
        foreach ($tree as $node) {
            $ids[] = (int) $node['id'];
            foreach ($node['children'] ?? [] as $child) {
                $ids[] = (int) $child['id'];
            }
        }
        return $ids;
    }

    /** ProductController::categoryMove() 와 동일한 로직 */
    private function simulateCategoryMove(int $id, string $direction): bool
    {
        $db      = db_connect();
        $current = $db->table('categories')->where('id', $id)->get()->getRowArray();
        if (! $current) return false;

        $builder = $db->table('categories');
        if ($current['parent_id'] === null) {
            $builder->where('parent_id IS NULL', null, false);
        } else {
            $builder->where('parent_id', $current['parent_id']);
        }

        $siblings = $builder->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')
                            ->get()->getResultArray();

        $currentIdx = null;
        foreach ($siblings as $i => $s) {
            if ((int) $s['id'] === $id) { $currentIdx = $i; break; }
        }
        if ($currentIdx === null) return false;

        $swapIdx = $direction === 'up' ? $currentIdx - 1 : $currentIdx + 1;
        if ($swapIdx < 0 || $swapIdx >= count($siblings)) return false;

        [$siblings[$currentIdx], $siblings[$swapIdx]] = [$siblings[$swapIdx], $siblings[$currentIdx]];

        foreach ($siblings as $i => $s) {
            $db->table('categories')->where('id', (int) $s['id'])->update(['sort_order' => $i]);
        }
        return true;
    }

    private function getSortOrder(int $id): int
    {
        return (int) db_connect()->table('categories')->where('id', $id)->get()->getRowArray()['sort_order'];
    }

    // ── getTreeDirect() ───────────────────────────────────────────────────────

    public function testGetTreeDirect_returnsAllCategories_includingInactive(): void
    {
        $active   = $this->insertCategory('ActiveCat',   0, 1);
        $inactive = $this->insertCategory('InactiveCat', 1, 0);

        $tree = $this->model->getTreeDirect();
        $ids  = $this->collectAllIds($tree);

        $this->assertContains($active,   $ids, 'active 카테고리가 포함되어야 함');
        $this->assertContains($inactive, $ids, 'inactive 카테고리도 포함되어야 함');
    }

    public function testGetTreeDirect_bypassesCache(): void
    {
        // 1) 캐시를 미리 채워 두고
        cache()->delete('category_tree');
        $this->model->getTree();

        // 2) 새 카테고리 삽입 (캐시는 아직 갱신되지 않은 상태)
        $newId = $this->insertCategory('NewCatDirect', 99, 1);

        // 3) getTreeDirect() 는 DB를 직접 조회하므로 새 항목이 보여야 함
        $ids = $this->collectAllIds($this->model->getTreeDirect());

        $this->assertContains($newId, $ids, 'getTreeDirect()는 캐시 우선이 아닌 DB 직접 조회여야 함');
    }

    public function testGetTreeDirect_buildsParentChildStructure(): void
    {
        $parentId = $this->insertCategory('ParentCat', 0, 1);
        $child1   = $this->insertCategory('ChildCat1', 0, 1, $parentId);
        $child2   = $this->insertCategory('ChildCat2', 1, 1, $parentId);

        $tree    = $this->model->getTreeDirect();
        $parents = array_filter($tree, fn ($r) => (int) $r['id'] === $parentId);
        $parent  = array_values($parents)[0] ?? null;

        $this->assertNotNull($parent, '부모 카테고리가 트리에 있어야 함');
        $childIds = array_map('intval', array_column($parent['children'], 'id'));
        $this->assertContains($child1, $childIds);
        $this->assertContains($child2, $childIds);
    }

    // ── clearCache() ──────────────────────────────────────────────────────────

    public function testClearCache_removesCache(): void
    {
        $this->insertCategory('CacheTestCat', 0, 1);
        $this->model->getTree(); // category_tree 캐시 생성

        $this->assertNotNull(cache()->get('category_tree'), '캐시가 존재해야 함');

        $this->model->clearCache();

        $this->assertNull(cache()->get('category_tree'), 'clearCache() 후 캐시가 없어야 함');
    }

    public function testClearCache_onEmptyCache_doesNotThrow(): void
    {
        cache()->delete('category_tree');
        $this->expectNotToPerformAssertions();
        $this->model->clearCache();
    }

    public function testGetTree_afterClearCache_rebuildsFromDB(): void
    {
        $id1 = $this->insertCategory('Cat1', 0, 1);
        $this->model->getTree(); // 초기 캐시

        $this->model->clearCache();
        $id2  = $this->insertCategory('Cat2', 1, 1);
        $ids  = $this->collectAllIds($this->model->getTree());

        $this->assertContains($id1, $ids);
        $this->assertContains($id2, $ids);
    }

    // ── categoryMove 로직 ──────────────────────────────────────────────────────

    public function testCategoryMoveDown_swapsSortOrder(): void
    {
        [$a, $b] = $this->createIsolatedGroup([['sort_order' => 0], ['sort_order' => 1]]);

        $result = $this->simulateCategoryMove($a, 'down');

        $this->assertTrue($result);
        $this->assertSame(1, $this->getSortOrder($a));
        $this->assertSame(0, $this->getSortOrder($b));
    }

    public function testCategoryMoveUp_swapsSortOrder(): void
    {
        [$a, $b] = $this->createIsolatedGroup([['sort_order' => 0], ['sort_order' => 1]]);

        $result = $this->simulateCategoryMove($b, 'up');

        $this->assertTrue($result);
        $this->assertSame(1, $this->getSortOrder($a));
        $this->assertSame(0, $this->getSortOrder($b));
    }

    public function testCategoryMoveUp_firstItem_returnsFalse(): void
    {
        [$a] = $this->createIsolatedGroup([['sort_order' => 0], ['sort_order' => 1]]);

        $result = $this->simulateCategoryMove($a, 'up');

        $this->assertFalse($result);
        $this->assertSame(0, $this->getSortOrder($a));
    }

    public function testCategoryMoveDown_lastItem_returnsFalse(): void
    {
        [, $b] = $this->createIsolatedGroup([['sort_order' => 0], ['sort_order' => 1]]);

        $result = $this->simulateCategoryMove($b, 'down');

        $this->assertFalse($result);
        $this->assertSame(1, $this->getSortOrder($b));
    }

    public function testCategoryMoveWithDuplicateSortOrder_alwaysMoves(): void
    {
        [$a, $b, $c] = $this->createIsolatedGroup([
            ['sort_order' => 0],
            ['sort_order' => 0],
            ['sort_order' => 0],
        ]);

        $result = $this->simulateCategoryMove($a, 'down');

        $this->assertTrue($result);
        $this->assertSame(1, $this->getSortOrder($a));
        $this->assertSame(0, $this->getSortOrder($b));
        $this->assertSame(2, $this->getSortOrder($c));
    }

    public function testCategoryMoveRenormalizesToSequential(): void
    {
        [$a, $b, $c] = $this->createIsolatedGroup([
            ['sort_order' => 100],
            ['sort_order' => 200],
            ['sort_order' => 300],
        ]);

        $this->simulateCategoryMove($c, 'up');

        $orders = array_map([$this, 'getSortOrder'], [$a, $b, $c]);
        sort($orders);
        $this->assertSame([0, 1, 2], $orders);
    }

    public function testCategoryMoveRespects_parentId_grouping(): void
    {
        $parent1 = $this->insertCategory('Parent1', 0);
        $parent2 = $this->insertCategory('Parent2', 1);

        $child1 = $this->insertCategory('Child1', 0, 1, $parent1);
        $child2 = $this->insertCategory('Child2', 1, 1, $parent1);
        $child3 = $this->insertCategory('Child3', 0, 1, $parent2);

        $result = $this->simulateCategoryMove($child2, 'up');

        $this->assertTrue($result);
        $this->assertSame(1, $this->getSortOrder($child1));
        $this->assertSame(0, $this->getSortOrder($child2));
        $this->assertSame(0, $this->getSortOrder($child3), 'parent2 그룹 영향 없어야 함');
    }

    public function testCategoryMove_moveUpThenDown_returnsToOriginal(): void
    {
        [$a, $b, $c] = $this->createIsolatedGroup([
            ['sort_order' => 0],
            ['sort_order' => 1],
            ['sort_order' => 2],
        ]);

        $this->simulateCategoryMove($b, 'up');
        $this->simulateCategoryMove($b, 'down');

        $this->assertSame(0, $this->getSortOrder($a));
        $this->assertSame(1, $this->getSortOrder($b));
        $this->assertSame(2, $this->getSortOrder($c));
    }
}
