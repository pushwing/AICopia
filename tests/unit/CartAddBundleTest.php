<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\CartController;
use App\Models\ProductAddonModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class CartAddBundleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['cart_items' => [], 'product_addons' => [], 'products' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'CABT' . substr(uniqid(), -6);
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['cart_items', 'product_addons', 'products', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['cart_items' => [], 'product_addons' => [], 'products' => [], 'users' => []];
        session()->destroy();

        service('request')->setGlobal('post', []);
        service('request')->setGlobal('get', []);
        service('request')->setGlobal('request', []);

        parent::tearDown();
    }

    private function insertUser(): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'   => $this->prefix,
            'email'      => $this->prefix . '@example.test',
            'password'   => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'   => $this->prefix,
            'role'       => 'member',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id                       = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function insertProduct(string $suffix, int $stock = 10, string $status = 'on_sale'): int
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

    private function link(int $mainId, int $addonId): void
    {
        (new ProductAddonModel())->saveForProduct($mainId, [$addonId]);
        $rows = db_connect()->table('product_addons')->select('id')->where('product_id', $mainId)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->cleanup['product_addons'][] = (int) $row['id'];
        }
    }

    /**
     * @param  array<int, array<string, mixed>> $addons
     * @return array<string, mixed>
     */
    private function callAddBundle(int $mainId, int $qty, array $addons): array
    {
        $post = ['product_id' => (string) $mainId, 'qty' => (string) $qty, 'addons' => $addons];

        $request = service('request');
        $request->setGlobal('post', $post);
        $request->setGlobal('request', $post);

        $controller = new CartController();
        $controller->initController(service('request'), service('response'), service('logger'));
        $response = $controller->addBundle();

        $request->setGlobal('post', []);
        $request->setGlobal('request', []);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    private function cartRows(int $userId): array
    {
        $rows = db_connect()->table('cart_items')->where('user_id', $userId)->orderBy('id', 'ASC')->get()->getResultArray();
        foreach ($rows as $row) {
            $this->cleanup['cart_items'][] = (int) $row['id'];
        }

        return $rows;
    }

    public function testAddsMainAndAddonTogether(): void
    {
        $userId = $this->insertUser();
        $main   = $this->insertProduct('MAIN');
        $addon  = $this->insertProduct('ADDON');
        $this->link($main, $addon);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $body = $this->callAddBundle($main, 1, [['product_id' => $addon, 'qty' => 2]]);
        $rows = $this->cartRows($userId);

        $this->assertTrue($body['success'] ?? false, $body['message'] ?? '');
        $this->assertCount(2, $rows, '본품과 애드온이 각각 담겨야 한다');
        $this->assertNull($rows[0]['parent_product_id'], '본품에는 부모가 없다');
        $this->assertSame($main, (int) $rows[1]['parent_product_id'], '애드온은 본품을 가리켜야 한다');
        $this->assertSame(2, (int) $rows[1]['qty']);
    }

    public function testRejectsAddonThatIsNotLinked(): void
    {
        $userId   = $this->insertUser();
        $main     = $this->insertProduct('MAIN');
        $stranger = $this->insertProduct('STRANGER');

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $body = $this->callAddBundle($main, 1, [['product_id' => $stranger, 'qty' => 1]]);
        $rows = $this->cartRows($userId);

        $this->assertCount(1, $rows, '연결되지 않은 상품은 담기면 안 된다');
        $this->assertNotEmpty($body['skipped'] ?? [], '건너뛴 사유를 알려줘야 한다');
    }

    public function testSkipsSoldOutAddon(): void
    {
        $userId = $this->insertUser();
        $main   = $this->insertProduct('MAIN');
        $addon  = $this->insertProduct('ADDON', 0);
        $this->link($main, $addon);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $this->callAddBundle($main, 1, [['product_id' => $addon, 'qty' => 1]]);

        $this->assertCount(1, $this->cartRows($userId), '품절 애드온은 담기면 안 된다');
    }

    public function testClipsAddonQtyToStock(): void
    {
        $userId = $this->insertUser();
        $main   = $this->insertProduct('MAIN');
        $addon  = $this->insertProduct('ADDON', 3);
        $this->link($main, $addon);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $this->callAddBundle($main, 1, [['product_id' => $addon, 'qty' => 99]]);
        $rows = $this->cartRows($userId);

        $this->assertSame(3, (int) $rows[1]['qty'], '재고까지만 담아야 한다');
    }

    public function testRejectsEverythingWhenMainIsUnavailable(): void
    {
        $userId = $this->insertUser();
        $main   = $this->insertProduct('MAIN', 10, 'hidden');
        $addon  = $this->insertProduct('ADDON');
        $this->link($main, $addon);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $body = $this->callAddBundle($main, 1, [['product_id' => $addon, 'qty' => 1]]);

        $this->assertFalse($body['success'] ?? true, '본품을 살 수 없으면 전체가 실패해야 한다');
        $this->assertCount(0, $this->cartRows($userId));
    }

    public function testKeepsFirstClassificationWhenSameProductAddedTwice(): void
    {
        $userId = $this->insertUser();
        $main   = $this->insertProduct('MAIN');
        $addon  = $this->insertProduct('ADDON');
        $this->link($main, $addon);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        // 애드온으로 먼저 담고, 같은 상품을 본품으로 다시 담는다.
        $this->callAddBundle($main, 1, [['product_id' => $addon, 'qty' => 1]]);
        $this->callAddBundle($addon, 1, []);

        $rows     = $this->cartRows($userId);
        $addonRow = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['product_id'] === $addon));

        $this->assertCount(1, $addonRow, '같은 상품·같은 SKU 는 한 행으로 병합된다');
        $this->assertSame($main, (int) $addonRow[0]['parent_product_id'], '먼저 정해진 분류가 유지돼야 한다');
        $this->assertSame(2, (int) $addonRow[0]['qty'], '수량은 합산된다');
    }

    public function testGuestBundleKeepsParentAfterLoginMerge(): void
    {
        $userId = $this->insertUser();
        $main   = $this->insertProduct('MAIN');
        $addon  = $this->insertProduct('ADDON');
        $this->link($main, $addon);

        // 비회원 상태로 담는다 (user_id 없음)
        $this->callAddBundle($main, 1, [['product_id' => $addon, 'qty' => 1]]);

        $sessionCart = session()->get('cart') ?? [];
        $parentMap   = session()->get('cart_addon_of') ?? [];
        $addonKey    = \App\Models\CartModel::sessionKey($addon, null);

        $this->assertArrayHasKey($addonKey, $sessionCart, '비회원 세션에 애드온이 담겨야 한다');
        $this->assertSame($main, (int) ($parentMap[$addonKey] ?? 0), '세션 병렬 맵에 부모가 남아야 한다');

        // 로그인 병합
        session()->set(['user_id' => $userId, 'user_role' => 'member']);
        $stockMap = [];
        foreach (array_keys($sessionCart) as $key) {
            $stockMap[$key] = 10;
        }
        (new \App\Models\CartModel())->mergeSession($userId, $sessionCart, $stockMap);

        $rows     = $this->cartRows($userId);
        $addonRow = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['product_id'] === $addon));

        $this->assertCount(1, $addonRow);
        $this->assertSame($main, (int) $addonRow[0]['parent_product_id'], '병합 후에도 부모가 유지돼야 한다');
    }

    public function testDuplicateAddonEntriesClipToTotalStockNotPerEntry(): void
    {
        $userId = $this->insertUser();
        $main   = $this->insertProduct('MAIN');
        $addon  = $this->insertProduct('ADDON', 5);
        $this->link($main, $addon);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        // 같은 애드온을 한 요청에 두 번(5+5) 담아도 실제 재고 5개까지만 담겨야 한다.
        $body = $this->callAddBundle($main, 1, [
            ['product_id' => $addon, 'qty' => 5],
            ['product_id' => $addon, 'qty' => 5],
        ]);
        $rows     = $this->cartRows($userId);
        $addonRow = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['product_id'] === $addon));

        $this->assertTrue($body['success'] ?? false, $body['message'] ?? '');
        $this->assertCount(1, $addonRow, '같은 애드온은 한 행으로 합쳐져야 한다');
        $this->assertSame(5, (int) $addonRow[0]['qty'], '항목별이 아니라 합산 수량 기준으로 재고까지만 담아야 한다');
    }

    public function testSameProductAsMainAndAddonDoesNotExceedStock(): void
    {
        $userId = $this->insertUser();
        $main   = $this->insertProduct('MAIN', 5);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        // 같은 상품이 본품(qty=5)이면서 동시에 애드온 목록에도(qty=5) 들어온 경우.
        $body = $this->callAddBundle($main, 5, [
            ['product_id' => $main, 'qty' => 5],
        ]);
        $rows     = $this->cartRows($userId);
        $mainRows = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['product_id'] === $main));

        $this->assertTrue($body['success'] ?? false, $body['message'] ?? '');
        $this->assertCount(1, $mainRows, '본품·애드온으로 중복 요청돼도 한 행이어야 한다');
        $this->assertSame(5, (int) $mainRows[0]['qty'], '재고를 넘겨 담으면 안 된다');
        $this->assertNull($mainRows[0]['parent_product_id'], '본품으로 담긴 만큼 부모가 없어야 한다');
    }
}
