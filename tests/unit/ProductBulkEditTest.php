<?php

namespace Tests\Unit;

use App\Models\ProductModel;
use App\Models\StockLogModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 상품 일괄 편집(bulk) 핵심 로직 검증
 *
 * ProductController::bulk() 는 세 가지 액션을 처리한다:
 *  - status  : 여러 상품 상태를 한 번에 변경
 *  - stock   : 여러 상품 재고를 한 번에 설정 + StockLog 기록
 *  - delete  : 여러 상품 소프트 삭제
 *
 * 컨트롤러 레이어(HTTP)는 제외하고 DB 레이어 동작을 직접 검증한다.
 */
final class ProductBulkEditTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string       $prefix;
    private ProductModel $model;

    private array $cleanup = [
        'stock_logs' => [],
        'products'   => [],
        'categories' => [],
        'users'      => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model  = new ProductModel();
        $this->prefix = 'BLK' . substr(uniqid(), -6) . '_';
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['stock_logs'] !== []) {
            $db->table('stock_logs')->whereIn('product_id', $this->cleanup['products'])->delete();
        }
        if ($this->cleanup['products'] !== []) {
            // 소프트 삭제된 것도 포함해 완전 삭제
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }
        if ($this->cleanup['categories'] !== []) {
            $db->table('categories')->whereIn('id', $this->cleanup['categories'])->delete();
        }
        if ($this->cleanup['users'] !== []) {
            $db->table('users')->whereIn('id', $this->cleanup['users'])->delete();
        }
        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertProduct(array $overrides = []): int
    {
        $uid  = uniqid();
        $data = array_merge([
            'name'       => $this->prefix . $uid,
            'slug'       => $this->prefix . $uid,
            'price'      => 10000,
            'stock'      => 100,
            'status'     => 'on_sale',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $overrides);
        $db  = db_connect();
        $db->table('products')->insert($data);
        $id  = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    /** bulk status 로직 — 컨트롤러와 동일 */
    private function applyBulkStatus(array $ids, string $status): void
    {
        db_connect()->table('products')
            ->whereIn('id', $ids)
            ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /** bulk stock 로직 — 컨트롤러와 동일 */
    private function applyBulkStock(array $ids, int $stock, int $adminId = 0): void
    {
        $logModel = new StockLogModel();
        foreach ($ids as $id) {
            $product = $this->model->find($id);
            if (! $product) continue;
            $oldStock = (int) $product['stock'];
            $this->model->update($id, ['stock' => $stock]);
            $logModel->record($id, 'adjust', abs($stock - $oldStock), $oldStock, $stock, '관리자 일괄 재고 조정', $adminId);
        }
    }

    /** bulk delete 로직 — 컨트롤러와 동일 */
    private function applyBulkDelete(array $ids): void
    {
        foreach ($ids as $id) {
            $this->model->delete($id);
        }
    }

    // ── 상태 변경 ──────────────────────────────────────────────────────────────

    public function testBulkStatusChangeToSoldOut(): void
    {
        $id1 = $this->insertProduct(['status' => 'on_sale']);
        $id2 = $this->insertProduct(['status' => 'on_sale']);

        $this->applyBulkStatus([$id1, $id2], 'sold_out');

        $p1 = $this->model->find($id1);
        $p2 = $this->model->find($id2);
        $this->assertSame('sold_out', $p1['status']);
        $this->assertSame('sold_out', $p2['status']);
    }

    public function testBulkStatusChangeToHidden(): void
    {
        $id1 = $this->insertProduct(['status' => 'on_sale']);
        $id2 = $this->insertProduct(['status' => 'sold_out']);

        $this->applyBulkStatus([$id1, $id2], 'hidden');

        $this->assertSame('hidden', $this->model->find($id1)['status']);
        $this->assertSame('hidden', $this->model->find($id2)['status']);
    }

    public function testBulkStatusOnlyAffectsTargetIds(): void
    {
        $target  = $this->insertProduct(['status' => 'on_sale']);
        $bystander = $this->insertProduct(['status' => 'on_sale']);

        $this->applyBulkStatus([$target], 'hidden');

        $this->assertSame('hidden',   $this->model->find($target)['status']);
        $this->assertSame('on_sale',  $this->model->find($bystander)['status']);
    }

    public function testBulkStatusUpdatesUpdatedAt(): void
    {
        $id  = $this->insertProduct(['updated_at' => '2020-01-01 00:00:00']);
        $before = date('Y-m-d H:i:s');

        $this->applyBulkStatus([$id], 'sold_out');

        $updated = $this->model->find($id)['updated_at'];
        $this->assertGreaterThanOrEqual($before, $updated, 'updated_at 이 갱신돼야 한다');
    }

    // ── 재고 설정 ──────────────────────────────────────────────────────────────

    public function testBulkStockSetsCorrectValue(): void
    {
        $id1 = $this->insertProduct(['stock' => 100]);
        $id2 = $this->insertProduct(['stock' => 50]);

        $this->applyBulkStock([$id1, $id2], 30);

        $this->assertSame(30, (int) $this->model->find($id1)['stock']);
        $this->assertSame(30, (int) $this->model->find($id2)['stock']);
    }

    public function testBulkStockAllowsZero(): void
    {
        $id = $this->insertProduct(['stock' => 100]);

        $this->applyBulkStock([$id], 0);

        $this->assertSame(0, (int) $this->model->find($id)['stock']);
    }

    public function testBulkStockRecordsLogForEachProduct(): void
    {
        $id1 = $this->insertProduct(['stock' => 100]);
        $id2 = $this->insertProduct(['stock' => 50]);

        $this->applyBulkStock([$id1, $id2], 10);

        $db   = db_connect();
        $log1 = $db->table('stock_logs')
            ->where('product_id', $id1)->where('note', '관리자 일괄 재고 조정')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $log2 = $db->table('stock_logs')
            ->where('product_id', $id2)->where('note', '관리자 일괄 재고 조정')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        $this->assertNotNull($log1, 'id1 재고 로그가 기록돼야 한다');
        $this->assertNotNull($log2, 'id2 재고 로그가 기록돼야 한다');
        $this->assertSame(10,  (int) $log1['stock_after']);
        $this->assertSame(100, (int) $log1['stock_before']);
        $this->assertSame(10,  (int) $log2['stock_after']);
        $this->assertSame(50,  (int) $log2['stock_before']);
    }

    public function testBulkStockLogRecordsCorrectDiff(): void
    {
        $id = $this->insertProduct(['stock' => 70]);

        $this->applyBulkStock([$id], 20);

        $log = db_connect()->table('stock_logs')
            ->where('product_id', $id)->where('note', '관리자 일괄 재고 조정')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        $this->assertSame(50,  (int) $log['quantity'],     'diff = |70 - 20| = 50');
        $this->assertSame(70,  (int) $log['stock_before']);
        $this->assertSame(20,  (int) $log['stock_after']);
    }

    // ── 소프트 삭제 ───────────────────────────────────────────────────────────

    public function testBulkDeleteSoftDeletesProducts(): void
    {
        $id1 = $this->insertProduct();
        $id2 = $this->insertProduct();

        $this->applyBulkDelete([$id1, $id2]);

        $row1 = db_connect()->table('products')->where('id', $id1)->get()->getRowArray();
        $row2 = db_connect()->table('products')->where('id', $id2)->get()->getRowArray();

        $this->assertNotNull($row1['deleted_at'], 'deleted_at 이 설정돼야 한다');
        $this->assertNotNull($row2['deleted_at'], 'deleted_at 이 설정돼야 한다');
    }

    public function testBulkDeleteOnlyAffectsTargetIds(): void
    {
        $target    = $this->insertProduct();
        $bystander = $this->insertProduct();

        $this->applyBulkDelete([$target]);

        $this->assertNull(db_connect()->table('products')->where('id', $bystander)->get()->getRowArray()['deleted_at'],
            '대상이 아닌 상품은 삭제되면 안 된다');
    }

    public function testDeletedProductNotReturnedByModel(): void
    {
        $id = $this->insertProduct();

        $this->applyBulkDelete([$id]);

        $this->assertNull($this->model->find($id), 'Model::find() 는 소프트 삭제된 상품을 반환하면 안 된다');
    }

    // ── STATUSES 상수 검증 ────────────────────────────────────────────────────

    public function testValidStatusValues(): void
    {
        $this->assertArrayHasKey('on_sale',  ProductModel::STATUSES);
        $this->assertArrayHasKey('sold_out', ProductModel::STATUSES);
        $this->assertArrayHasKey('hidden',   ProductModel::STATUSES);
    }
}
