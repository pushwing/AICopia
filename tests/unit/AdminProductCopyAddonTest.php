<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\ProductController;
use App\Models\ProductAddonModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 상품 복제(copy())가 애드온(추가구성상품) 연결도 함께 복사하는지 검증한다 (이슈 #160).
 *
 * 카테고리·이미지·옵션/SKU는 이미 copy() 가 복사하지만 product_addons 연결은
 * 빠져 있었다 — 복제된 상품은 원본이 갖고 있던 추가구성상품 노출이 사라진 채로 남는다.
 */
final class AdminProductCopyAddonTest extends CIUnitTestCase
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
        $this->prefix = 'APCA' . substr(uniqid(), -6);
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['product_addons'] !== []) {
            $db->table('product_addons')->whereIn('id', $this->cleanup['product_addons'])->delete();
        }
        if ($this->cleanup['products'] !== []) {
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }
        $this->cleanup = ['product_addons' => [], 'products' => []];
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertProduct(string $suffix): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'       => $this->prefix . $suffix,
            'slug'       => strtolower($this->prefix . $suffix),
            'price'      => 10000,
            'stock'      => 5,
            'status'     => 'on_sale',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id                          = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return $id;
    }

    /** 새로 복제된 상품이 만들어졌다면 tearDown 에서 함께 지워지도록 등록한다 */
    private function trackCopiedProduct(int $newId): void
    {
        $this->cleanup['products'][]      = $newId;
        $rows = db_connect()->table('product_addons')->select('id')->where('product_id', $newId)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->cleanup['product_addons'][] = (int) $row['id'];
        }
    }

    private function callCopy(int $productId): int
    {
        $controller = new ProductController();
        $controller->initController(service('request'), service('response'), service('logger'));
        $response = $controller->copy($productId);

        // "/admin/products/{newId}/edit" 리다이렉트에서 새 상품 id 를 뽑아낸다.
        $location = $response->header('Location')->getValue();
        preg_match('#/admin/products/(\d+)/edit#', $location, $m);

        return (int) $m[1];
    }

    // ── 테스트 ────────────────────────────────────────────────────────────────

    public function testCopyDuplicatesAddonLinksInOrder(): void
    {
        $main   = $this->insertProduct('MAIN');
        $addon1 = $this->insertProduct('ADDON1');
        $addon2 = $this->insertProduct('ADDON2');
        new ProductAddonModel()->saveForProduct($main, [$addon1, $addon2]);
        $this->trackCopiedProduct($main);

        $newId = $this->callCopy($main);
        $this->trackCopiedProduct($newId);

        $this->assertSame(
            [$addon1, $addon2],
            new ProductAddonModel()->getAddonProductIds($newId),
            '복제된 상품은 원본과 동일한 순서로 애드온 연결을 가져야 한다',
        );
    }

    public function testCopyWithoutAddonsDoesNotFail(): void
    {
        $solo  = $this->insertProduct('SOLO');
        $newId = $this->callCopy($solo);
        $this->trackCopiedProduct($newId);

        $this->assertSame([], new ProductAddonModel()->getAddonProductIds($newId), '애드온이 없던 상품은 복제 후에도 빈 배열이어야 한다');
    }
}
