<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductAddonModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class ProductAddonModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['product_addons' => [], 'products' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'PADD' . substr(uniqid(), -6);
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['product_addons', 'products'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['product_addons' => [], 'products' => []];
        parent::tearDown();
    }

    private function insertProduct(string $suffix, string $status = 'on_sale', int $stock = 10): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'       => $this->prefix . $suffix,
            'slug'       => strtolower($this->prefix . $suffix),
            'price'      => 10000,
            'stock'      => $stock,
            'status'     => $status,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id                          = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return $id;
    }

    /** 삽입된 연결 행 id 를 정리 목록에 담는다 */
    private function trackAddonRows(int $productId): void
    {
        $rows = db_connect()->table('product_addons')->select('id')->where('product_id', $productId)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->cleanup['product_addons'][] = (int) $row['id'];
        }
    }

    public function testSaveForProductStoresLinksInOrder(): void
    {
        $main = $this->insertProduct('MAIN');
        $a    = $this->insertProduct('A');
        $b    = $this->insertProduct('B');

        $model = new ProductAddonModel();
        $model->saveForProduct($main, [$b, $a]);
        $this->trackAddonRows($main);

        $this->assertSame([$b, $a], $model->getAddonProductIds($main), '저장한 순서가 sort_order 로 유지돼야 한다');
    }

    public function testSaveForProductReplacesPreviousLinks(): void
    {
        $main = $this->insertProduct('MAIN');
        $a    = $this->insertProduct('A');
        $b    = $this->insertProduct('B');

        $model = new ProductAddonModel();
        $model->saveForProduct($main, [$a]);
        $model->saveForProduct($main, [$b]);
        $this->trackAddonRows($main);

        $this->assertSame([$b], $model->getAddonProductIds($main), '기존 연결을 전량 교체해야 한다');
    }

    public function testSaveForProductRejectsSelfAndDuplicates(): void
    {
        $main = $this->insertProduct('MAIN');
        $a    = $this->insertProduct('A');

        $model = new ProductAddonModel();
        $model->saveForProduct($main, [$main, $a, $a]);
        $this->trackAddonRows($main);

        $this->assertSame([$a], $model->getAddonProductIds($main), '자기 자신과 중복은 제외돼야 한다');
    }

    public function testGetForDisplayHidesUnbuyableAddons(): void
    {
        $main    = $this->insertProduct('MAIN');
        $onSale  = $this->insertProduct('OK');
        $hidden  = $this->insertProduct('HIDDEN', 'hidden');
        $soldOut = $this->insertProduct('SOLDOUT', 'on_sale', 0);

        $model = new ProductAddonModel();
        $model->saveForProduct($main, [$onSale, $hidden, $soldOut]);
        $this->trackAddonRows($main);

        $shown = array_column($model->getForDisplay($main), 'id');

        $this->assertSame([$onSale], array_map(intval(...), $shown), '판매중·재고 있는 애드온만 노출돼야 한다');
    }

    public function testGetForDisplayHidesSoftDeletedAddon(): void
    {
        $main    = $this->insertProduct('MAIN');
        $onSale  = $this->insertProduct('OK');
        $deleted = $this->insertProduct('DELETED');

        $db = db_connect();
        $db->table('products')->where('id', $deleted)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $model = new ProductAddonModel();
        $model->saveForProduct($main, [$onSale, $deleted]);
        $this->trackAddonRows($main);

        $shown = array_column($model->getForDisplay($main), 'id');

        $this->assertSame([$onSale], array_map(intval(...), $shown), '소프트 삭제된 상품은 애드온 목록에서 제외돼야 한다');
    }

    public function testIsLinked(): void
    {
        $main  = $this->insertProduct('MAIN');
        $a     = $this->insertProduct('A');
        $other = $this->insertProduct('OTHER');

        $model = new ProductAddonModel();
        $model->saveForProduct($main, [$a]);
        $this->trackAddonRows($main);

        $this->assertTrue($model->isLinked($main, $a));
        $this->assertFalse($model->isLinked($main, $other), '연결되지 않은 상품은 애드온이 아니다');
    }
}
