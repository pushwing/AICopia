# 추가구성상품(애드온) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 상품 상세에서 부속 상품을 함께 골라 담고, 그 묶음이 장바구니 · 주문 · 출고 엑셀까지 유지되게 한다.

**Architecture:** 애드온은 별도 엔티티가 아니라 기존 `products` 를 가리키는 연결(`product_addons`)이다. 담기면 일반 장바구니 항목이 되고, `cart_items.parent_product_id` → `order_items.parent_product_id` 로 "어느 본품의 부속인가"만 따라간다. 이 컬럼은 금액·재고·삭제 로직에 전혀 관여하지 않는다.

**Tech Stack:** CodeIgniter 4, PHP 8.5, MySQL, Bootstrap 5, PhpSpreadsheet, PHPUnit

**설계 스펙:** `docs/superpowers/specs/2026-08-10-product-addons-design.md`

## Global Constraints

- 모든 PHP 파일 첫 줄에 `declare(strict_types=1);` — PHP-CS-Fixer 가 강제한다.
- PSR-12, 메서드·프로퍼티에 타입 선언 전부(반환 타입 포함). PHPStan 레벨 5 통과 필수. `@phpstan-ignore` 금지.
- 배열 타입은 제네릭 표기 명시(`array<int, array<string, mixed>>` 등).
- 주석·커밋 메시지는 한국어. 커밋 = 이모지 + Conventional Commits 접두어 + 한국어 설명.
- DB 접근은 Query Builder / 바인딩만. 문자열 조합 raw SQL 금지.
- 뷰의 모든 출력은 `esc()`. POST 폼에는 `csrf_field()`.
- 모델에는 `$allowedFields` 명시.
- 테스트는 `tests/unit/`, `CIUnitTestCase` + `DatabaseTestTrait`, `protected $DBGroup = 'tests'; protected $migrate = false; protected $refresh = false;`, `tearDown()` 에서 삽입한 행을 id 로 직접 삭제.
- 각 태스크 마지막 커밋 전 `composer check`(cs + analyse + test) 전량 통과.
- 브랜치: `feature/product-addons`. `dev`·`main` 직접 push 금지.

---

### Task 1: 마이그레이션 + ProductAddonModel

**Files:**
- Create: `app/Database/Migrations/2026-08-10-000001_CreateProductAddons.php`
- Create: `app/Models/ProductAddonModel.php`
- Test: `tests/unit/ProductAddonModelTest.php`

**Interfaces:**
- Consumes: 없음 (첫 태스크)
- Produces:
  - 테이블 `product_addons(id, product_id, addon_product_id, sort_order, created_at)`
  - 컬럼 `cart_items.parent_product_id` INT UNSIGNED NULL, `order_items.parent_product_id` INT UNSIGNED NULL
  - `ProductAddonModel::saveForProduct(int $productId, array $addonProductIds): void`
  - `ProductAddonModel::getAddonProductIds(int $productId): array<int, int>`
  - `ProductAddonModel::getForDisplay(int $productId): array<int, array<string, mixed>>`
  - `ProductAddonModel::isLinked(int $productId, int $addonProductId): bool`

- [ ] **Step 1: 마이그레이션 작성**

`app/Database/Migrations/2026-08-10-000001_CreateProductAddons.php`:

```php
<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProductAddons extends Migration
{
    public function up()
    {
        // 본품 ↔ 추가구성상품 연결
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'product_id'       => ['type' => 'INT', 'unsigned' => true],
            'addon_product_id' => ['type' => 'INT', 'unsigned' => true],
            'sort_order'       => ['type' => 'INT', 'default' => 0],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('product_id');
        $this->forge->addUniqueKey(['product_id', 'addon_product_id'], 'uniq_product_addons_pair');
        $this->forge->createTable('product_addons');

        // 어느 본품에 딸려 담겼는지 — 표시·포장용이며 금액/재고 계산에는 쓰지 않는다.
        $this->forge->addColumn('cart_items', [
            'parent_product_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'sku_id'],
        ]);
        $this->forge->addColumn('order_items', [
            'parent_product_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'sku_id'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('order_items', 'parent_product_id');
        $this->forge->dropColumn('cart_items', 'parent_product_id');
        $this->forge->dropTable('product_addons', true);
    }
}
```

- [ ] **Step 2: 테스트 DB에 마이그레이션 적용**

Run: `php spark migrate --all`
Expected: `Running: App\Database\Migrations\...CreateProductAddons` 출력 후 `Migrations complete.`

- [ ] **Step 3: 실패하는 테스트 작성**

`tests/unit/ProductAddonModelTest.php`:

```php
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
```

- [ ] **Step 4: 테스트를 돌려 실패 확인**

Run: `vendor/bin/phpunit tests/unit/ProductAddonModelTest.php`
Expected: FAIL — `Error: Class "App\Models\ProductAddonModel" not found`

- [ ] **Step 5: 모델 구현**

`app/Models/ProductAddonModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * 본품 ↔ 추가구성상품 연결.
 *
 * 애드온은 별도 엔티티가 아니라 일반 상품이다. 이 모델은 "어떤 상품 상세에서
 * 어떤 상품을 부속으로 보여줄지"만 관리한다.
 */
class ProductAddonModel extends Model
{
    protected $table         = 'product_addons';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $allowedFields = ['product_id', 'addon_product_id', 'sort_order', 'created_at'];

    /**
     * 연결을 전량 교체한다. 자기 자신 연결과 중복은 버린다.
     *
     * @param array<int, int> $addonProductIds 노출할 순서대로
     */
    public function saveForProduct(int $productId, array $addonProductIds): void
    {
        $this->db->table('product_addons')->where('product_id', $productId)->delete();

        $ids  = array_values(array_unique(array_filter(
            array_map(intval(...), $addonProductIds),
            static fn (int $id): bool => $id > 0 && $id !== $productId,
        )));
        if ($ids === []) {
            return;
        }

        $now  = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($ids as $index => $addonId) {
            $rows[] = [
                'product_id'       => $productId,
                'addon_product_id' => $addonId,
                'sort_order'       => $index,
                'created_at'       => $now,
            ];
        }

        $this->db->table('product_addons')->insertBatch($rows);
    }

    /** @return array<int, int> 노출 순서대로의 애드온 상품 id */
    public function getAddonProductIds(int $productId): array
    {
        $rows = $this->db->table('product_addons')
            ->select('addon_product_id')
            ->where('product_id', $productId)
            ->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): int => (int) $row['addon_product_id'], $rows);
    }

    /**
     * 상품 상세에 노출할 애드온 목록. 살 수 없는 상품(판매중 아님·재고 0·삭제)은 뺀다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForDisplay(int $productId): array
    {
        return $this->db->table('product_addons pa')
            ->select('p.id, p.name, p.slug, p.price, p.discount_price, p.stock, m.file_path')
            ->join('products p', 'p.id = pa.addon_product_id')
            ->join('product_images pi', 'pi.product_id = p.id AND pi.is_primary = 1', 'left')
            ->join('media m', 'm.id = pi.media_id', 'left')
            ->where('pa.product_id', $productId)
            ->where('p.status', 'on_sale')
            ->where('p.stock >', 0)
            ->where('p.deleted_at IS NULL', null, false)
            ->orderBy('pa.sort_order', 'ASC')->orderBy('pa.id', 'ASC')
            ->get()->getResultArray();
    }

    /** 이 쌍이 실제로 등록된 연결인지 — 임의 상품 주입을 막는 검증용 */
    public function isLinked(int $productId, int $addonProductId): bool
    {
        return $this->db->table('product_addons')
            ->where('product_id', $productId)
            ->where('addon_product_id', $addonProductId)
            ->countAllResults() > 0;
    }
}
```

- [ ] **Step 6: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/ProductAddonModelTest.php`
Expected: `OK (5 tests, ...)`

- [ ] **Step 7: 전체 게이트 통과 후 커밋**

Run: `composer check`
Expected: cs `Found 0 of N files that can be fixed`, PHPStan `[OK] No errors`, PHPUnit `OK`

```bash
git add app/Database/Migrations/2026-08-10-000001_CreateProductAddons.php app/Models/ProductAddonModel.php tests/unit/ProductAddonModelTest.php
git commit -m "✨ feat: 추가구성상품 연결 테이블·모델 추가 (#147)"
```

---

### Task 2: 관리자 상품 폼에서 애드온 연결

**Files:**
- Modify: `app/Controllers/Admin/ProductController.php` (`handleAddons()` 추가, `store()`/`update()`/`edit()`/`create()` 에서 호출, `addonSearch()` 추가)
- Modify: `app/Config/Routes.php` (검색 라우트 1줄)
- Modify: `app/Views/admin/products/form.php` (옵션 카드 아래 카드 + JS)
- Test: `tests/unit/AdminProductAddonSaveTest.php`

**Interfaces:**
- Consumes: `ProductAddonModel::saveForProduct()`, `getAddonProductIds()`
- Produces:
  - POST 폼 필드 `addons_json` — `[123, 456]` 형태의 상품 id 배열 JSON
  - `GET /admin/products/addon-search?q=&exclude=` → `{"items":[{"id":1,"name":"...","price":1000,"thumbnail":"..."}]}`
  - 뷰 변수 `addonProducts`: `array<int, array<string, mixed>>` (id, name, price, thumbnail)

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/AdminProductAddonSaveTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductAddonModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 상품 폼이 보낸 addons_json 이 연결로 저장되는지 검증한다.
 * 컨트롤러의 private handleAddons() 는 store()/update() 를 통해 간접 검증한다.
 */
final class AdminProductAddonSaveTest extends CIUnitTestCase
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
        $this->prefix = 'APAS' . substr(uniqid(), -6);
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

    private function trackAddonRows(int $productId): void
    {
        $rows = db_connect()->table('product_addons')->select('id')->where('product_id', $productId)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->cleanup['product_addons'][] = (int) $row['id'];
        }
    }

    public function testAddonSearchExcludesSelfAndReturnsOnSaleOnly(): void
    {
        $main = $this->insertProduct('MAIN');
        $hit  = $this->insertProduct('HIT');

        $controller = new \App\Controllers\Admin\ProductController();
        $controller->initController(service('request'), service('response'), service('logger'));

        $_GET['q']       = $this->prefix;
        $_GET['exclude'] = (string) $main;
        $response = $controller->addonSearch();
        unset($_GET['q'], $_GET['exclude']);

        $body = json_decode((string) $response->getBody(), true);
        $ids  = array_map(static fn (array $r): int => (int) $r['id'], $body['items'] ?? []);

        $this->assertContains($hit, $ids, '검색어에 맞는 상품이 나와야 한다');
        $this->assertNotContains($main, $ids, '자기 자신은 후보에서 빠져야 한다');
    }

    public function testHiddenProductIsNotOfferedAsAddon(): void
    {
        $this->insertProduct('MAIN');
        $hidden = $this->insertProduct('HIDDEN');
        db_connect()->table('products')->where('id', $hidden)->update(['status' => 'hidden']);

        $controller = new \App\Controllers\Admin\ProductController();
        $controller->initController(service('request'), service('response'), service('logger'));

        $_GET['q'] = $this->prefix;
        $response  = $controller->addonSearch();
        unset($_GET['q']);

        $body = json_decode((string) $response->getBody(), true);
        $ids  = array_map(static fn (array $r): int => (int) $r['id'], $body['items'] ?? []);

        $this->assertNotContains($hidden, $ids, '판매중이 아닌 상품은 후보에서 빠져야 한다');
    }
}
```

이 파일은 **컨트롤러를 실제로 호출하는 테스트만** 둔다. `ProductAddonModel` 의 저장 규칙(전량 교체·자기 자신 제외·순서)은 Task 1 의 `ProductAddonModelTest` 가 이미 덮으므로 여기서 반복하지 않는다.

- [ ] **Step 2: 테스트를 돌려 실패 확인**

Run: `vendor/bin/phpunit tests/unit/AdminProductAddonSaveTest.php`
Expected: FAIL — `Error: Call to undefined method App\Controllers\Admin\ProductController::addonSearch()`

- [ ] **Step 3: 컨트롤러에 검색·저장 로직 추가**

`app/Controllers/Admin/ProductController.php` 상단 `use` 에 추가:

```php
use App\Models\ProductAddonModel;
```

`handleOptions()` 메서드 바로 아래에 추가:

```php
    /** 상품 폼의 addons_json 을 추가구성상품 연결로 저장한다 */
    private function handleAddons(int $productId): void
    {
        $json = $this->request->getPost('addons_json');
        $ids  = is_string($json) ? json_decode($json, true) : null;

        new ProductAddonModel()->saveForProduct($productId, is_array($ids) ? $ids : []);
    }

    /** GET /admin/products/addon-search — 추가구성상품 후보 검색 (Ajax) */
    public function addonSearch(): \CodeIgniter\HTTP\ResponseInterface
    {
        $keyword = trim((string) ($this->request->getGet('q') ?? ''));
        $exclude = (int) ($this->request->getGet('exclude') ?? 0);

        if ($keyword === '') {
            return $this->response->setJSON(['items' => []]);
        }

        $builder = $this->productModel->db->table('products p')
            ->select('p.id, p.name, p.price, m.file_path AS thumbnail')
            ->join('product_images pi', 'pi.product_id = p.id AND pi.is_primary = 1', 'left')
            ->join('media m', 'm.id = pi.media_id', 'left')
            ->like('p.name', $keyword)
            ->where('p.status', 'on_sale')
            ->where('p.deleted_at IS NULL', null, false);

        if ($exclude > 0) {
            $builder->where('p.id !=', $exclude);
        }

        $items = $builder->orderBy('p.name', 'ASC')->limit(20)->get()->getResultArray();

        return $this->response->setJSON(['items' => $items]);
    }
```

`store()` 와 `update()` 에서 `$this->handleOptions($productId);` 를 호출하는 줄 바로 뒤에 `$this->handleAddons($productId);` 를 추가한다.

`edit()` 의 `render('admin/products/form', [...])` 배열에 다음을 추가한다:

```php
            'addonProducts'  => $this->addonProductsFor($id),
```

`create()` 의 같은 배열에는 다음을 추가한다:

```php
            'addonProducts'  => [],
```

그리고 `addonSearch()` 아래에 조회 헬퍼를 추가한다:

```php
    /**
     * 폼에 다시 그릴 애드온 목록(순서 유지)
     *
     * @return array<int, array<string, mixed>>
     */
    private function addonProductsFor(int $productId): array
    {
        $ids = new ProductAddonModel()->getAddonProductIds($productId);
        if ($ids === []) {
            return [];
        }

        $rows = $this->productModel->db->table('products p')
            ->select('p.id, p.name, p.price, m.file_path AS thumbnail')
            ->join('product_images pi', 'pi.product_id = p.id AND pi.is_primary = 1', 'left')
            ->join('media m', 'm.id = pi.media_id', 'left')
            ->whereIn('p.id', $ids)
            ->get()->getResultArray();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        // 연결 순서대로 되돌린다 — whereIn 결과 순서는 보장되지 않는다.
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }
```

- [ ] **Step 4: 라우트 추가**

`app/Config/Routes.php` 의 `products/naver-search` 줄 아래에 추가:

```php
    $routes->get('products/addon-search', 'Admin\ProductController::addonSearch');
```

- [ ] **Step 5: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/AdminProductAddonSaveTest.php`
Expected: `OK (3 tests, ...)`

- [ ] **Step 6: 상품 폼에 카드 추가**

`app/Views/admin/products/form.php` 의 옵션 카드(`id="optionCard"`) 를 닫는 `</div>` 바로 뒤에 추가:

```php
            <div class="card mb-3" id="addonCard">
                <div class="card-header fw-semibold bg-white">
                    추가구성상품
                    <span class="text-muted small fw-normal ms-2">상품 상세에서 함께 구매하도록 제안할 상품</span>
                </div>
                <div class="card-body">
                    <input type="hidden" name="addons_json" id="addonsJson">
                    <div class="input-group mb-3">
                        <input type="text" class="form-control" id="addonSearchInput" placeholder="상품명으로 검색">
                        <button class="btn btn-outline-secondary" type="button" onclick="searchAddons()">검색</button>
                    </div>
                    <div id="addonSearchResult" class="list-group mb-3 d-none"></div>
                    <div id="addonList"></div>
                </div>
            </div>
```

같은 파일의 `<script>` 블록 끝(옵션 JS 아래)에 추가:

```php
<script>
let addonItems = <?= json_encode($addonProducts ?? [], JSON_UNESCAPED_UNICODE) ?>;
const addonProductId = <?= (int) ($product['id'] ?? 0) ?>;

function renderAddons() {
    const el = document.getElementById('addonList');
    if (addonItems.length === 0) {
        el.innerHTML = '<div class="text-muted small">연결된 추가구성상품이 없습니다.</div>';
    } else {
        el.innerHTML = addonItems.map(function (item, i) {
            const thumb = item.thumbnail
                ? '<img src="/' + item.thumbnail + '" class="rounded me-2" style="width:36px;height:36px;object-fit:cover">'
                : '';
            return '<div class="d-flex align-items-center border rounded p-2 mb-2">'
                + thumb
                + '<div class="flex-grow-1 small">' + item.name
                + '<span class="text-muted ms-2">' + Number(item.price).toLocaleString() + '원</span></div>'
                + '<button type="button" class="btn btn-sm btn-outline-secondary me-1" onclick="moveAddon(' + i + ',-1)">↑</button>'
                + '<button type="button" class="btn btn-sm btn-outline-secondary me-1" onclick="moveAddon(' + i + ',1)">↓</button>'
                + '<button type="button" class="btn btn-sm btn-outline-danger" onclick="removeAddon(' + i + ')">삭제</button>'
                + '</div>';
        }).join('');
    }
    document.getElementById('addonsJson').value = JSON.stringify(addonItems.map(function (i) { return i.id; }));
}

function searchAddons() {
    const q = document.getElementById('addonSearchInput').value.trim();
    if (!q) { return; }
    fetch('/admin/products/addon-search?q=' + encodeURIComponent(q) + '&exclude=' + addonProductId)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            const box = document.getElementById('addonSearchResult');
            box.classList.remove('d-none');
            const chosen = addonItems.map(function (i) { return Number(i.id); });
            const rows = (data.items || []).filter(function (i) { return chosen.indexOf(Number(i.id)) === -1; });
            box.innerHTML = rows.length === 0
                ? '<div class="list-group-item text-muted small">결과가 없습니다.</div>'
                : rows.map(function (i) {
                    return '<button type="button" class="list-group-item list-group-item-action small"'
                        + ' onclick=\'addAddon(' + JSON.stringify(i) + ')\'>' + i.name + '</button>';
                }).join('');
        });
}

function addAddon(item) {
    addonItems.push(item);
    document.getElementById('addonSearchResult').classList.add('d-none');
    document.getElementById('addonSearchInput').value = '';
    renderAddons();
}

function removeAddon(i) { addonItems.splice(i, 1); renderAddons(); }

function moveAddon(i, dir) {
    const j = i + dir;
    if (j < 0 || j >= addonItems.length) { return; }
    const tmp = addonItems[i]; addonItems[i] = addonItems[j]; addonItems[j] = tmp;
    renderAddons();
}

renderAddons();
</script>
```

- [ ] **Step 7: 화면 확인**

Run: `php spark serve --port 8303` 후 브라우저에서 `/admin/products/<기존상품id>/edit` 열기
Expected: "추가구성상품" 카드가 옵션 카드 아래에 보이고, 상품명 검색 → 추가 → 순서변경 → 삭제가 동작하며, 저장 후 다시 열었을 때 목록이 유지된다.

- [ ] **Step 8: 전체 게이트 통과 후 커밋**

Run: `composer check`
Expected: 세 단계 모두 통과

```bash
git add app/Controllers/Admin/ProductController.php app/Config/Routes.php app/Views/admin/products/form.php tests/unit/AdminProductAddonSaveTest.php
git commit -m "✨ feat: 관리자 상품 폼에서 추가구성상품 연결 (#147)"
```

---

### Task 3: 본품+애드온 한 번에 담기 (`/cart/add-bundle`)

**Files:**
- Modify: `app/Models/CartModel.php` (`upsert()` 시그니처 확장, `mergeSession()` 부모 맵 반영, `getByUser()` 셀렉트에 `parent_product_id` 추가)
- Modify: `app/Controllers/Front/CartController.php` (`addBundle()` 추가)
- Modify: `app/Config/Routes.php` (라우트 1줄, CSRF 예외 아님 — 기존 `/cart/add` 와 동일하게 토큰 필요)
- Test: `tests/unit/CartAddBundleTest.php`

**Interfaces:**
- Consumes: `ProductAddonModel::isLinked()`
- Produces:
  - `CartModel::upsert(int $userId, int $productId, int $qty, ?int $skuId = null, ?int $parentProductId = null): void`
  - `POST /cart/add-bundle` → `{"success":bool,"message":string,"cartCount":int,"skipped":array<int,string>,"csrf_hash":string}`
  - 세션 병렬 맵 `cart_addon_of`: `sessionKey => parentProductId`

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/CartAddBundleTest.php`:

```php
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
        new ProductAddonModel()->saveForProduct($mainId, [$addonId]);
        $rows = db_connect()->table('product_addons')->select('id')->where('product_id', $mainId)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->cleanup['product_addons'][] = (int) $row['id'];
        }
    }

    /** @param array<int, array<string, mixed>> $addons */
    private function callAddBundle(int $mainId, int $qty, array $addons): array
    {
        $_POST = ['product_id' => (string) $mainId, 'qty' => (string) $qty, 'addons' => $addons];

        $controller = new CartController();
        $controller->initController(service('request'), service('response'), service('logger'));
        $response = $controller->addBundle();

        $_POST = [];

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

        $rows = $this->cartRows($userId);
        $addonRow = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['product_id'] === $addon));

        $this->assertCount(1, $addonRow, '같은 상품·같은 SKU 는 한 행으로 병합된다');
        $this->assertSame($main, (int) $addonRow[0]['parent_product_id'], '먼저 정해진 분류가 유지돼야 한다');
        $this->assertSame(2, (int) $addonRow[0]['qty'], '수량은 합산된다');
    }
}
```

- [ ] **Step 2: 테스트를 돌려 실패 확인**

Run: `vendor/bin/phpunit tests/unit/CartAddBundleTest.php`
Expected: FAIL — `Error: Call to undefined method App\Controllers\Front\CartController::addBundle()`

- [ ] **Step 3: `CartModel` 확장**

`app/Models/CartModel.php` 의 `$allowedFields` 에 `'parent_product_id'` 를 추가하고, `upsert()` 를 교체한다:

```php
    /**
     * 장바구니에 담기. 같은 상품·같은 SKU 는 수량을 합산한다.
     *
     * $parentProductId 는 어느 본품에 딸려 담겼는지를 나타내는 표시·포장용 값이다.
     * 이미 행이 있으면 먼저 정해진 분류를 유지한다(COALESCE).
     */
    public function upsert(int $userId, int $productId, int $qty, ?int $skuId = null, ?int $parentProductId = null): void
    {
        $this->db->query(
            'INSERT INTO cart_items (user_id, product_id, sku_id, parent_product_id, qty, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                qty = qty + VALUES(qty),
                parent_product_id = COALESCE(parent_product_id, VALUES(parent_product_id))',
            [$userId, $productId, $skuId, $parentProductId, $qty]
        );
    }
```

`getByUser()` 의 `select()` 첫 줄을 다음으로 바꾼다:

```php
            ->select('cart_items.id, cart_items.product_id, cart_items.sku_id, cart_items.parent_product_id, cart_items.qty,
```

`mergeSession()` 의 마지막 `upsert` 호출을 다음으로 바꾼다:

```php
            $parentMap = session()->get('cart_addon_of') ?? [];
            $this->upsert($userId, $productId, $addQty, $skuId ?: null, isset($parentMap[$key]) ? (int) $parentMap[$key] : null);
```

`mergeAndClear()` 에서 `session()->remove('cart')` 하는 곳 옆에 `session()->remove('cart_addon_of');` 를 추가한다.

- [ ] **Step 4: `addBundle()` 구현**

`app/Controllers/Front/CartController.php` 상단 `use` 에 `use App\Models\ProductAddonModel;` 을 추가하고, `add()` 아래에 추가:

```php
    /**
     * POST /cart/add-bundle — 본품 + 추가구성상품을 한 요청으로 담는다.
     *
     * 요청마다 CSRF 토큰이 회전하므로 /cart/add 를 N 번 부르는 대신 한 번에 처리한다.
     */
    public function addBundle(): \CodeIgniter\HTTP\ResponseInterface
    {
        $productId = (int) $this->request->getPost('product_id');
        $qty       = max(1, (int) $this->request->getPost('qty'));
        $skuId     = $this->request->getPost('sku_id') ? (int) $this->request->getPost('sku_id') : null;
        $addons    = $this->request->getPost('addons');
        $addons    = is_array($addons) ? $addons : [];

        $main = $this->resolvePurchasable($productId, $skuId, $qty);
        if ($main === null) {
            return $this->response->setJSON(['success' => false, 'message' => '구매할 수 없는 상품입니다.', 'csrf_hash' => csrf_hash()]);
        }

        $addonModel = new ProductAddonModel();
        $accepted   = [];
        $skipped    = [];

        foreach ($addons as $addon) {
            $addonId  = (int) ($addon['product_id'] ?? 0);
            $addonSku = isset($addon['sku_id']) && $addon['sku_id'] ? (int) $addon['sku_id'] : null;
            $addonQty = max(1, (int) ($addon['qty'] ?? 1));

            if (! $addonModel->isLinked($productId, $addonId)) {
                $skipped[] = '추가구성상품이 아닌 항목은 담지 않았습니다.';
                continue;
            }

            $resolved = $this->resolvePurchasable($addonId, $addonSku, $addonQty);
            if ($resolved === null) {
                $skipped[] = '품절이거나 판매하지 않는 추가구성상품은 담지 않았습니다.';
                continue;
            }

            $accepted[] = $resolved;
        }

        $this->storeInCart($main['product_id'], $main['sku_id'], $main['qty'], null);
        foreach ($accepted as $item) {
            $this->storeInCart($item['product_id'], $item['sku_id'], $item['qty'], $productId);
        }

        $userId = session()->get('user_id');
        $count  = $userId ? $this->cartModel->getCount((int) $userId) : count(session()->get('cart') ?? []);

        return $this->response->setJSON([
            'success'   => true,
            'message'   => '장바구니에 담겼습니다.',
            'cartCount' => $count,
            'skipped'   => array_values(array_unique($skipped)),
            'csrf_hash' => csrf_hash(),
        ]);
    }

    /**
     * 살 수 있는 상품인지 확인하고 재고까지 클리핑한 수량을 돌려준다.
     *
     * @return array{product_id: int, sku_id: int|null, qty: int}|null
     */
    private function resolvePurchasable(int $productId, ?int $skuId, int $qty): ?array
    {
        $row = $this->productModel->db
            ->table('products')
            ->select('id, stock, status, deleted_at')
            ->where('id', $productId)
            ->where('status', 'on_sale')
            ->where('deleted_at IS NULL', null, false)
            ->get()->getRowArray();

        if (! $row) {
            return null;
        }

        if ($skuId !== null) {
            $sku = $this->skuModel->findForProduct($skuId, $productId);
            if (! $sku) {
                return null;
            }
            $stock = (int) $sku['stock'];
        } else {
            $stock = (int) $row['stock'];
        }

        if ($stock < 1) {
            return null;
        }

        return ['product_id' => $productId, 'sku_id' => $skuId, 'qty' => min($qty, $stock)];
    }

    /** 회원이면 DB, 비회원이면 세션에 담는다 */
    private function storeInCart(int $productId, ?int $skuId, int $qty, ?int $parentProductId): void
    {
        $userId = session()->get('user_id');

        if ($userId) {
            $this->cartModel->upsert((int) $userId, $productId, $qty, $skuId, $parentProductId);
            return;
        }

        $cart    = session()->get('cart') ?? [];
        $parents = session()->get('cart_addon_of') ?? [];
        $sessKey = CartModel::sessionKey($productId, $skuId);

        $cart[$sessKey] = ($cart[$sessKey] ?? 0) + $qty;
        if ($parentProductId !== null && ! isset($parents[$sessKey])) {
            $parents[$sessKey] = $parentProductId;
        }

        session()->set('cart', $cart);
        session()->set('cart_addon_of', $parents);
    }
```

- [ ] **Step 5: 라우트 추가**

`app/Config/Routes.php` 의 `cart/add` 라우트 바로 아래에 추가:

```php
$routes->post('cart/add-bundle', 'Front\CartController::addBundle');
```

- [ ] **Step 6: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/CartAddBundleTest.php`
Expected: `OK (6 tests, ...)`

- [ ] **Step 7: 비회원 → 로그인 병합 테스트 추가**

`tests/unit/CartAddBundleTest.php` 에 추가한다. 비회원으로 담은 뒤 로그인 병합을 거쳐도 부모가 살아남아야 한다.

```php
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
        new \App\Models\CartModel()->mergeSession($userId, $sessionCart, $stockMap);

        $rows     = $this->cartRows($userId);
        $addonRow = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['product_id'] === $addon));

        $this->assertCount(1, $addonRow);
        $this->assertSame($main, (int) $addonRow[0]['parent_product_id'], '병합 후에도 부모가 유지돼야 한다');
    }
```

- [ ] **Step 8: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/CartAddBundleTest.php`
Expected: `OK (7 tests, ...)`

- [ ] **Step 9: 전체 게이트 통과 후 커밋**

Run: `composer check`
Expected: 세 단계 모두 통과

```bash
git add app/Models/CartModel.php app/Controllers/Front/CartController.php app/Config/Routes.php tests/unit/CartAddBundleTest.php
git commit -m "✨ feat: 본품+추가구성상품 한 번에 담는 /cart/add-bundle 추가 (#147)"
```

---

### Task 4: 상품 상세 애드온 영역

**Files:**
- Modify: `app/Controllers/Front/ShopController.php` (`detail()` 에 `addons` 주입)
- Modify: `app/Views/shop/detail.php` (애드온 영역 + 담기 JS 를 `/cart/add-bundle` 로 전환)
- Test: `tests/unit/ProductDetailAddonTest.php`

**Interfaces:**
- Consumes: `ProductAddonModel::getForDisplay()`, `POST /cart/add-bundle`
- Produces: 뷰 변수 `addons`: `array<int, array<string, mixed>>` (id, name, slug, price, discount_price, stock, file_path)

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/ProductDetailAddonTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\ShopController;
use App\Models\ProductAddonModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class ProductDetailAddonTest extends CIUnitTestCase
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
        $this->prefix = 'PDAD' . substr(uniqid(), -6);
        session()->destroy();
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
        session()->destroy();
        parent::tearDown();
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

    private function slugOf(int $productId): string
    {
        $row = db_connect()->table('products')->select('slug')->where('id', $productId)->get()->getRowArray();

        return (string) ($row['slug'] ?? '');
    }

    /** @param array<int, int> $addonIds */
    private function link(int $mainId, array $addonIds): void
    {
        new ProductAddonModel()->saveForProduct($mainId, $addonIds);
        $rows = db_connect()->table('product_addons')->select('id')->where('product_id', $mainId)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->cleanup['product_addons'][] = (int) $row['id'];
        }
    }

    private function detail(int $productId): string
    {
        $controller = new ShopController();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller->detail($this->slugOf($productId));
    }

    public function testShowsAddonSection(): void
    {
        $main  = $this->insertProduct('MAIN');
        $addon = $this->insertProduct('ADDON');
        $this->link($main, [$addon]);

        $html = $this->detail($main);

        $this->assertStringContainsString('추가구성상품', $html, '애드온 영역이 없다');
        $this->assertStringContainsString($this->prefix . 'ADDON', $html, '애드온 상품명이 없다');
    }

    public function testHidesSoldOutAddon(): void
    {
        $main  = $this->insertProduct('MAIN');
        $addon = $this->insertProduct('ADDON', 0);
        $this->link($main, [$addon]);

        $html = $this->detail($main);

        $this->assertStringNotContainsString($this->prefix . 'ADDON', $html, '품절 애드온은 노출되면 안 된다');
    }

    public function testOmitsSectionWhenNoAddons(): void
    {
        $main = $this->insertProduct('MAIN');

        $html = $this->detail($main);

        $this->assertStringNotContainsString('추가구성상품', $html, '애드온이 없으면 영역 자체가 없어야 한다');
    }
}
```

- [ ] **Step 2: 테스트를 돌려 실패 확인**

Run: `vendor/bin/phpunit tests/unit/ProductDetailAddonTest.php`
Expected: FAIL — `testShowsAddonSection` 에서 `Failed asserting that '...' contains "추가구성상품"`

- [ ] **Step 3: 컨트롤러에서 애드온 주입**

`app/Controllers/Front/ShopController.php` 상단 `use` 에 `use App\Models\ProductAddonModel;` 을 추가하고, `detail()` 의 `render('shop/detail', [...])` 배열에 추가:

```php
            'addons' => new ProductAddonModel()->getForDisplay($productId),
```

- [ ] **Step 4: 상품 상세에 애드온 영역 추가**

`app/Views/shop/detail.php` 의 수량·담기 버튼 영역 바로 위에 추가:

```php
<?php $addons = $addons ?? []; ?>
<?php if ($addons !== []): ?>
<div class="card mb-3" id="addonSection">
    <div class="card-header bg-light d-flex align-items-center">
        <span class="fw-semibold">추가구성상품</span>
        <span class="text-muted small ms-2">추가로 구매를 원하시면 선택하세요.</span>
    </div>
    <div class="card-body">
        <?php foreach ($addons as $addon): ?>
        <div class="d-flex align-items-center border rounded p-2 mb-2">
            <?php if ($addon['file_path']): ?>
            <img src="<?= esc(base_url($addon['file_path'])) ?>" class="rounded me-3" style="width:56px;height:56px;object-fit:cover" alt="">
            <?php endif; ?>
            <div class="flex-grow-1">
                <div class="small fw-semibold"><?= esc($addon['name']) ?></div>
                <div class="small text-danger"><?= number_format((int) ($addon['discount_price'] ?: $addon['price'])) ?>원</div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary"
                    onclick="selectAddon(<?= (int) $addon['id'] ?>, '<?= esc($addon['name'], 'js') ?>', <?= (int) ($addon['discount_price'] ?: $addon['price']) ?>)">
                선택
            </button>
        </div>
        <?php endforeach; ?>
        <div id="selectedAddons" class="mt-3"></div>
    </div>
</div>
<?php endif; ?>
```

같은 파일의 `<script>` 블록에 추가:

```php
<script>
let selectedAddons = [];

function selectAddon(id, name, price) {
    if (selectedAddons.some(function (a) { return a.id === id; })) { return; }
    selectedAddons.push({ id: id, name: name, price: price, qty: 1 });
    renderSelectedAddons();
}

function changeAddonQty(id, delta) {
    const item = selectedAddons.find(function (a) { return a.id === id; });
    if (!item) { return; }
    item.qty = Math.max(1, item.qty + delta);
    renderSelectedAddons();
}

function removeSelectedAddon(id) {
    selectedAddons = selectedAddons.filter(function (a) { return a.id !== id; });
    renderSelectedAddons();
}

function renderSelectedAddons() {
    const el = document.getElementById('selectedAddons');
    if (!el) { return; }
    el.innerHTML = selectedAddons.map(function (a) {
        return '<div class="d-flex align-items-center border-top pt-2 mt-2 small">'
            + '<div class="flex-grow-1">' + a.name + '</div>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="changeAddonQty(' + a.id + ',-1)">-</button>'
            + '<span class="mx-2">' + a.qty + '</span>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="changeAddonQty(' + a.id + ',1)">+</button>'
            + '<span class="ms-3 fw-semibold">' + (a.price * a.qty).toLocaleString() + '원</span>'
            + '<button type="button" class="btn btn-sm btn-link text-danger" onclick="removeSelectedAddon(' + a.id + ')">삭제</button>'
            + '</div>';
    }).join('');
}
</script>
```

- [ ] **Step 5: 담기 요청을 `/cart/add-bundle` 로 전환**

`app/Views/shop/detail.php` 의 `addToCart()` 함수에서 `fetch('/cart/add', ...)` 를 다음으로 바꾼다:

```php
    selectedAddons.forEach(function (a, i) {
        body.append('addons[' + i + '][product_id]', a.id);
        body.append('addons[' + i + '][qty]', a.qty);
    });

    fetch('/cart/add-bundle', { method: 'POST', body })
```

응답 처리에서 `data.skipped` 가 비어 있지 않으면 그 메시지를 함께 알린다:

```php
        .then(function (data) {
            if (data.skipped && data.skipped.length > 0) {
                alert(data.skipped.join('\n'));
            }
            /* 이하 기존 처리 유지 */
        })
```

- [ ] **Step 6: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/ProductDetailAddonTest.php`
Expected: `OK (3 tests, ...)`

- [ ] **Step 7: 화면 확인**

Run: `php spark serve --port 8303` 후 애드온을 연결한 상품 상세 열기
Expected: 애드온 영역이 보이고, 선택 → 수량 조절 → 장바구니 담기 후 `/cart` 에 본품과 애드온이 모두 있다.

- [ ] **Step 8: 전체 게이트 통과 후 커밋**

Run: `composer check`

```bash
git add app/Controllers/Front/ShopController.php app/Views/shop/detail.php tests/unit/ProductDetailAddonTest.php
git commit -m "✨ feat: 상품 상세에 추가구성상품 선택 영역 추가 (#147)"
```

---

### Task 5: 주문 승계 + 주문 상세 묶음 표시

**Files:**
- Modify: `app/Models/OrderModel.php` (`order_items` `insertBatch` 에 `parent_product_id` 추가)
- Create: `app/Libraries/AddonGrouping.php` (본품 뒤에 애드온을 붙이는 정렬 헬퍼)
- Modify: `app/Views/shop/orders/detail.php`, `app/Views/admin/orders/detail.php` (묶음 렌더)
- Test: `tests/unit/OrderAddonGroupingTest.php`

**Interfaces:**
- Consumes: `cart_items.parent_product_id` (Task 3)
- Produces: `AddonGrouping::order(array $items): array<int, array<string, mixed>>` — 본품 뒤에 그 본품을 가리키는 애드온을 붙여 정렬하고, 각 행에 `is_addon` (bool) 을 넣어 돌려준다.

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/OrderAddonGroupingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\AddonGrouping;
use CodeIgniter\Test\CIUnitTestCase;

final class OrderAddonGroupingTest extends CIUnitTestCase
{
    public function testPlacesAddonRightAfterItsParent(): void
    {
        $items = [
            ['id' => 1, 'product_id' => 10, 'parent_product_id' => null],
            ['id' => 2, 'product_id' => 20, 'parent_product_id' => null],
            ['id' => 3, 'product_id' => 30, 'parent_product_id' => 10],
        ];

        $ordered = AddonGrouping::order($items);

        $this->assertSame([10, 30, 20], array_column($ordered, 'product_id'));
        $this->assertFalse($ordered[0]['is_addon']);
        $this->assertTrue($ordered[1]['is_addon']);
        $this->assertFalse($ordered[2]['is_addon']);
    }

    public function testGroupsUnderFirstMatchingParentRow(): void
    {
        $items = [
            ['id' => 1, 'product_id' => 10, 'parent_product_id' => null],
            ['id' => 2, 'product_id' => 10, 'parent_product_id' => null],
            ['id' => 3, 'product_id' => 30, 'parent_product_id' => 10],
        ];

        $ordered = AddonGrouping::order($items);

        $this->assertSame([1, 3, 2], array_column($ordered, 'id'), '같은 본품이 두 줄이면 첫 줄 아래로 묶인다');
    }

    public function testKeepsOrphanAddonAtTheEnd(): void
    {
        $items = [
            ['id' => 1, 'product_id' => 10, 'parent_product_id' => null],
            ['id' => 2, 'product_id' => 30, 'parent_product_id' => 99],
        ];

        $ordered = AddonGrouping::order($items);

        $this->assertSame([1, 2], array_column($ordered, 'id'));
        $this->assertFalse($ordered[1]['is_addon'], '본품을 못 찾은 애드온은 일반 항목으로 둔다');
    }

    public function testEmptyList(): void
    {
        $this->assertSame([], AddonGrouping::order([]));
    }
}
```

- [ ] **Step 2: 테스트를 돌려 실패 확인**

Run: `vendor/bin/phpunit tests/unit/OrderAddonGroupingTest.php`
Expected: FAIL — `Error: Class "App\Libraries\AddonGrouping" not found`

- [ ] **Step 3: 정렬 헬퍼 구현**

`app/Libraries/AddonGrouping.php`:

```php
<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * 주문·장바구니 항목을 "본품 → 그 본품의 추가구성상품" 순서로 늘어놓는다.
 *
 * parent_product_id 는 상품 id 를 가리키므로, 같은 본품이 옵션만 다르게 두 줄
 * 들어간 경우 애드온은 첫 줄 아래로 묶인다. 포장 단위를 알아보는 데는 충분하다.
 */
final class AddonGrouping
{
    /**
     * @param  array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public static function order(array $items): array
    {
        $parents  = [];
        $children = [];

        foreach ($items as $item) {
            $parentId = isset($item['parent_product_id']) ? (int) $item['parent_product_id'] : 0;
            if ($parentId > 0) {
                $children[$parentId][] = $item;
                continue;
            }
            $parents[] = $item;
        }

        $ordered = [];
        foreach ($parents as $parent) {
            $parent['is_addon'] = false;
            $ordered[]          = $parent;

            $productId = (int) $parent['product_id'];
            if (! isset($children[$productId])) {
                continue;
            }

            foreach ($children[$productId] as $child) {
                $child['is_addon'] = true;
                $ordered[]         = $child;
            }
            unset($children[$productId]);
        }

        // 본품을 못 찾은 애드온은 일반 항목으로 끝에 붙인다.
        foreach ($children as $orphans) {
            foreach ($orphans as $orphan) {
                $orphan['is_addon'] = false;
                $ordered[]          = $orphan;
            }
        }

        return $ordered;
    }
}
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/OrderAddonGroupingTest.php`
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: 주문 생성 시 승계**

`app/Models/OrderModel.php` 의 `$items[] = [...]` 배열에서 `'sku_option_label'` 줄 바로 위에 추가:

```php
                'parent_product_id' => isset($item['parent_product_id']) && $item['parent_product_id'] ? (int) $item['parent_product_id'] : null,
```

- [ ] **Step 6: 주문 상세 뷰에 묶음 표시**

`app/Views/shop/orders/detail.php` 의 `$items = $order['items'] ?? [];` 를 다음으로 바꾼다:

```php
$items    = \App\Libraries\AddonGrouping::order($order['items'] ?? []);
```

항목을 그리는 `foreach` 안에서 바깥 `<div>` 의 클래스에 들여쓰기와 배지를 더한다:

```php
    <div class="d-flex gap-3 <?= ! empty($item['is_addon']) ? 'ps-4 border-start border-3' : '' ?>">
        <?php if (! empty($item['is_addon'])): ?>
        <span class="badge bg-secondary align-self-start">추가구성</span>
        <?php endif; ?>
```

`app/Views/admin/orders/detail.php` 에도 동일하게 적용한다 — `$items = $order['items'] ?? [];` 를 `AddonGrouping::order(...)` 로 바꾸고, 항목 행에 같은 들여쓰기·배지를 넣는다.

- [ ] **Step 7: 주문 승계 테스트 추가**

`tests/unit/OrderAddonGroupingTest.php` 는 순수 유닛(DB 없음)이므로, 승계 검증은 **새 파일** `tests/unit/OrderAddonInheritTest.php` 에 둔다. 주문 생성은 `OrderModel` 을 직접 부르지 않고, 승계 규칙과 동일한 매핑을 검증한다 — 주문 생성 전체 경로는 기존 `OrderFlowTest` 가 이미 덮고 있다.

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 장바구니의 parent_product_id 가 주문 항목으로 승계되는지 확인한다.
 */
final class OrderAddonInheritTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** @var array<int, int> */
    private array $orderItemIds = [];

    protected function tearDown(): void
    {
        if ($this->orderItemIds !== []) {
            db_connect()->table('order_items')->whereIn('id', $this->orderItemIds)->delete();
        }
        $this->orderItemIds = [];
        parent::tearDown();
    }

    public function testParentProductIdColumnAcceptsAndReturnsValue(): void
    {
        $db = db_connect();
        $db->table('order_items')->insert([
            'order_id'          => 0,
            'product_id'        => 30,
            'parent_product_id' => 10,
            'product_name'      => '애드온승계테스트',
            'product_price'     => 1000,
            'qty'               => 1,
            'subtotal'          => 1000,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
        $id                   = (int) $db->insertID();
        $this->orderItemIds[] = $id;

        $row = $db->table('order_items')->where('id', $id)->get()->getRowArray();

        $this->assertSame(10, (int) $row['parent_product_id'], 'order_items 가 부모를 보관해야 한다');
    }
}
```

추가로 기존 `tests/unit/OrderFlowTest.php` 의 주문 생성 테스트에서 장바구니 항목 배열에 `'parent_product_id' => $mainProductId` 를 넣고, 생성된 주문 항목에 그대로 실렸는지 단언을 더한다:

```php
        $orderItems = db_connect()->table('order_items')->where('order_id', $orderId)->orderBy('id', 'ASC')->get()->getResultArray();
        $this->assertSame($mainProductId, (int) $orderItems[1]['parent_product_id'], '주문 항목에 부모가 승계돼야 한다');
```

- [ ] **Step 8: 전체 게이트 통과 후 커밋**

Run: `composer check`

```bash
git add app/Libraries/AddonGrouping.php app/Models/OrderModel.php app/Views/shop/orders/detail.php app/Views/admin/orders/detail.php tests/unit/OrderAddonGroupingTest.php tests/unit/OrderAddonInheritTest.php tests/unit/OrderFlowTest.php
git commit -m "✨ feat: 주문에 추가구성상품 묶음 승계·표시 (#147)"
```

---

### Task 6: 주문 엑셀에 묶음 표시

**Files:**
- Modify: `app/Controllers/Admin/OrderController.php` (엑셀 `nameMap` 구성)
- Test: `tests/unit/OrderExcelAddonTest.php`

**Interfaces:**
- Consumes: `order_items.parent_product_id` (Task 5), `AddonGrouping::order()`
- Produces: `AddonGrouping::labels(array $items): array<int, string>` — 본품은 `{상품명} x{수량}`, 애드온은 `+ {상품명} x{수량}` 으로 본품 바로 뒤. 컨트롤러와 테스트가 **같은 함수**를 쓴다.

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/OrderExcelAddonTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\AddonGrouping;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 엑셀 '상품명' 칸에 묶음이 드러나는지 — 포장 담당자가 같은 박스를 알아볼 수 있어야 한다.
 * 컨트롤러가 쓰는 것과 같은 AddonGrouping::labels() 를 직접 검증한다.
 */
final class OrderExcelAddonTest extends CIUnitTestCase
{
    public function testAddonFollowsParentWithPlusPrefix(): void
    {
        $rows = [
            ['id' => 1, 'product_id' => 10, 'parent_product_id' => null, 'product_name' => 'Patient Plate', 'qty' => 1],
            ['id' => 2, 'product_id' => 20, 'parent_product_id' => null, 'product_name' => '다른상품', 'qty' => 1],
            ['id' => 3, 'product_id' => 30, 'parent_product_id' => 10, 'product_name' => 'Gender', 'qty' => 2],
        ];

        $this->assertSame(
            ['Patient Plate x1', '+ Gender x2', '다른상품 x1'],
            AddonGrouping::labels($rows),
        );
    }

    public function testPlainOrderIsUnchanged(): void
    {
        $rows = [
            ['id' => 1, 'product_id' => 10, 'parent_product_id' => null, 'product_name' => '상품A', 'qty' => 2],
            ['id' => 2, 'product_id' => 20, 'parent_product_id' => null, 'product_name' => '상품B', 'qty' => 1],
        ];

        $this->assertSame(['상품A x2', '상품B x1'], AddonGrouping::labels($rows), '애드온이 없으면 기존 형식 그대로여야 한다');
    }
}
```

- [ ] **Step 2: 테스트를 돌려 실패 확인**

Run: `vendor/bin/phpunit tests/unit/OrderExcelAddonTest.php`
Expected: FAIL — `Error: Call to undefined method App\Libraries\AddonGrouping::labels()`

- [ ] **Step 3: `AddonGrouping::labels()` 구현**

`app/Libraries/AddonGrouping.php` 의 `order()` 아래에 추가:

```php
    /**
     * 엑셀 '상품명' 칸에 쓸 라벨 목록. 애드온은 본품 바로 뒤에 '+' 를 달고 붙는다.
     *
     * @param  array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    public static function labels(array $items): array
    {
        $labels = [];
        foreach (self::order($items) as $item) {
            $prefix   = ($item['is_addon'] ?? false) === true ? '+ ' : '';
            $labels[] = $prefix . (string) $item['product_name'] . ' x' . (string) $item['qty'];
        }

        return $labels;
    }
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `vendor/bin/phpunit tests/unit/OrderExcelAddonTest.php`
Expected: `OK (2 tests, ...)`

- [ ] **Step 5: 엑셀 조립부를 `labels()` 로 교체**

`app/Controllers/Admin/OrderController.php` 의 엑셀 내보내기에서 `nameMap` 을 만드는 블록을 다음으로 바꾼다:

```php
            $rows = $this->orderModel->db->table('order_items')
                ->select('order_id, product_id, parent_product_id, product_name, qty, id')
                ->whereIn('order_id', $orderIds)
                ->orderBy('order_id', 'ASC')->orderBy('id', 'ASC')
                ->get()->getResultArray();

            // 주문별로 모아 본품 → 애드온 순으로 라벨을 만든다.
            $byOrder = [];
            foreach ($rows as $row) {
                $byOrder[(int) $row['order_id']][] = $row;
            }
            foreach ($byOrder as $orderId => $orderRows) {
                $nameMap[$orderId] = \App\Libraries\AddonGrouping::labels($orderRows);
            }
```

- [ ] **Step 6: 기존 엑셀 테스트가 깨지지 않는지 확인**

Run: `vendor/bin/phpunit tests/unit/OrderExcelExportTest.php tests/unit/OrderExcelAddonTest.php`
Expected: 둘 다 `OK` — 애드온이 없는 주문의 라벨 형식(`상품A x2`)이 그대로여야 한다.

- [ ] **Step 7: 전체 게이트 통과 후 커밋**

Run: `composer check`

```bash
git add app/Libraries/AddonGrouping.php app/Controllers/Admin/OrderController.php tests/unit/OrderExcelAddonTest.php
git commit -m "✨ feat: 주문 엑셀 상품명에 추가구성상품 묶음 표시 (#147)"
```

---

## 마무리 체크리스트

- [ ] `composer check` 전량 통과 (cs 0건 · PHPStan 0건 · PHPUnit 전체)
- [ ] `php spark serve --port 8303` 로 실제 흐름 1회 확인: 관리자에서 애드온 연결 → 상품 상세에서 선택 → 장바구니 → 주문 → 관리자 주문 상세 묶음 표시 → 엑셀 다운로드
- [ ] PR 은 `feature/product-addons` → `dev`, Squash merge
- [ ] **배포 시 마이그레이션 필요** — `dev → main` 배포는 Actions 에서 `workflow_dispatch` 로 `run_migration = true` 를 선택해 실행한다. 일반 머지 배포만으로는 `product_addons` 테이블이 생기지 않아 상품 상세가 깨진다.
