<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 메뉴 순서 이동 (▲/▼) 로직 검증
 *
 * MenuController::move() 와 동일한 로직을 DB 레벨에서 직접 테스트.
 * 실제 DB 데이터와 격리하기 위해 모든 테스트 항목은 전용 부모 메뉴의
 * 자식으로 생성한다 (parent_id 기반 조회가 테스트 항목만 반환).
 */
final class MenuReorderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = ['menus' => []];

    protected function tearDown(): void
    {
        if ($this->cleanup['menus'] !== []) {
            $db = db_connect();
            // 자식 먼저 삭제 (FK 제약 안전)
            $db->table('menus')->whereIn('id', $this->cleanup['menus'])
               ->where('parent_id IS NOT NULL', null, false)->delete();
            $db->table('menus')->whereIn('id', $this->cleanup['menus'])->delete();
        }
        $this->cleanup['menus'] = [];
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertMenu(int $sortOrder, ?int $parentId = null): int
    {
        $db = db_connect();
        $db->table('menus')->insert([
            'parent_id'  => $parentId,
            'title'      => 'Menu_' . uniqid(),
            'url'        => '/test-' . uniqid(),
            'target'     => '_self',
            'sort_order' => $sortOrder,
            'is_active'  => 1,
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['menus'][] = $id;
        return $id;
    }

    /**
     * 전용 부모 아래에 자식 N개를 생성하여 격리된 형제 그룹 반환.
     * 부모 자체는 반환 배열에 포함하지 않는다.
     *
     * @param  int[] $sortOrders 각 자식의 sort_order
     * @return int[]             자식 ID 배열
     */
    private function createIsolatedGroup(array $sortOrders): array
    {
        $parent = $this->insertMenu(999); // 부모 sort_order 는 무관
        $ids    = [];
        foreach ($sortOrders as $so) {
            $ids[] = $this->insertMenu($so, $parent);
        }
        return $ids;
    }

    /** MenuController::move() 와 동일한 배열 인덱스 스왑 + 재정규화 로직 */
    private function simulateMove(int $id, string $direction): bool
    {
        $db      = db_connect();
        $current = $db->table('menus')->where('id', $id)->get()->getRowArray();
        if (! $current) return false;

        $builder = $db->table('menus');
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
            $db->table('menus')->where('id', (int) $s['id'])->update(['sort_order' => $i]);
        }
        return true;
    }

    private function getSortOrder(int $id): int
    {
        return (int) db_connect()->table('menus')->where('id', $id)->get()->getRowArray()['sort_order'];
    }

    // ── 테스트 ────────────────────────────────────────────────────────────────

    public function testMoveDown_swapsSortOrder(): void
    {
        [$a, $b] = $this->createIsolatedGroup([0, 1]);

        $result = $this->simulateMove($a, 'down');

        $this->assertTrue($result);
        $this->assertSame(1, $this->getSortOrder($a));
        $this->assertSame(0, $this->getSortOrder($b));
    }

    public function testMoveUp_swapsSortOrder(): void
    {
        [$a, $b] = $this->createIsolatedGroup([0, 1]);

        $result = $this->simulateMove($b, 'up');

        $this->assertTrue($result);
        $this->assertSame(1, $this->getSortOrder($a));
        $this->assertSame(0, $this->getSortOrder($b));
    }

    public function testMoveUp_firstItem_returnsFalse(): void
    {
        [$a] = $this->createIsolatedGroup([0, 1]);

        $result = $this->simulateMove($a, 'up');

        $this->assertFalse($result);
        $this->assertSame(0, $this->getSortOrder($a));
    }

    public function testMoveDown_lastItem_returnsFalse(): void
    {
        [, $b] = $this->createIsolatedGroup([0, 1]);

        $result = $this->simulateMove($b, 'down');

        $this->assertFalse($result);
        $this->assertSame(1, $this->getSortOrder($b));
    }

    public function testMoveWithDuplicateSortOrder_alwaysMoves(): void
    {
        // sort_order 가 모두 0으로 중복된 상황 — id 오름차순으로 순위 결정
        [$a, $b, $c] = $this->createIsolatedGroup([0, 0, 0]);

        // a(idx=0) → down → b(idx=1) 와 교환
        $result = $this->simulateMove($a, 'down');

        $this->assertTrue($result);
        // 재정규화 후 b가 0, a가 1, c가 2
        $this->assertSame(1, $this->getSortOrder($a));
        $this->assertSame(0, $this->getSortOrder($b));
        $this->assertSame(2, $this->getSortOrder($c));
    }

    public function testMoveRenormalizesToSequential(): void
    {
        // 불규칙한 sort_order 값
        [$a, $b, $c] = $this->createIsolatedGroup([10, 20, 30]);

        $this->simulateMove($c, 'up'); // c ↔ b

        // 이후 모두 0,1,2 로 정규화되어야 함
        $orders = array_map([$this, 'getSortOrder'], [$a, $b, $c]);
        sort($orders);
        $this->assertSame([0, 1, 2], $orders);
    }

    public function testMoveRespects_parentId_grouping(): void
    {
        // 부모 2개 (전용 격리 그룹 아님 — 자식 범위 테스트가 목적)
        $parent1 = $this->insertMenu(0);
        $parent2 = $this->insertMenu(1);

        // parent1 의 하위 메뉴
        $child1 = $this->insertMenu(0, $parent1);
        $child2 = $this->insertMenu(1, $parent1);

        // parent2 의 하위 메뉴
        $child3 = $this->insertMenu(0, $parent2);

        // child2(parent1 그룹, idx=1) 를 up → child1 과 교환
        $result = $this->simulateMove($child2, 'up');

        $this->assertTrue($result);
        $this->assertSame(1, $this->getSortOrder($child1));
        $this->assertSame(0, $this->getSortOrder($child2));
        // parent2 의 child3 는 영향 없음
        $this->assertSame(0, $this->getSortOrder($child3));
    }

    public function testThreeItems_moveMiddleUp_then_down_returnsToOriginal(): void
    {
        [$a, $b, $c] = $this->createIsolatedGroup([0, 1, 2]);

        $this->simulateMove($b, 'up');   // b ↔ a
        $this->simulateMove($b, 'down'); // b 다시 원위치

        $this->assertSame(0, $this->getSortOrder($a));
        $this->assertSame(1, $this->getSortOrder($b));
        $this->assertSame(2, $this->getSortOrder($c));
    }
}
