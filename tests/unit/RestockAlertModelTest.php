<?php

namespace Tests\Unit;

use App\Models\RestockAlertModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * RestockAlertModel — exists / getPending / markNotified
 */
final class RestockAlertModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private RestockAlertModel $model;

    private array $cleanup = [
        'restock_alerts' => [],
        'products'       => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new RestockAlertModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach ($this->cleanup as $table => $ids) {
            if ($ids !== []) {
                $db->table($table)->whereIn('id', $ids)->delete();
            }
        }
        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertProduct(int $stock = 0, string $status = 'sold_out'): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => 'RA상품_' . uniqid(),
            'slug'           => 'ra-prod-' . uniqid(),
            'price'          => 10000,
            'cost_price'     => 0,
            'stock'          => $stock,
            'status'         => $status,
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function insertAlert(int $productId, string $email, ?string $notifiedAt = null): int
    {
        $db = db_connect();
        $db->table('restock_alerts')->insert([
            'product_id'  => $productId,
            'user_id'     => null,
            'email'       => $email,
            'notified_at' => $notifiedAt,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['restock_alerts'][] = $id;
        return $id;
    }

    // ── exists() ──────────────────────────────────────────────────────────────

    public function test_exists_returns_false_when_no_alert(): void
    {
        $productId = $this->insertProduct();

        $this->assertFalse($this->model->exists($productId, 'nobody@test.com'));
    }

    public function test_exists_returns_true_after_insert(): void
    {
        $productId = $this->insertProduct();
        $this->insertAlert($productId, 'user@test.com');

        $this->assertTrue($this->model->exists($productId, 'user@test.com'));
    }

    public function test_exists_is_scoped_to_product(): void
    {
        $p1 = $this->insertProduct();
        $p2 = $this->insertProduct();
        $this->insertAlert($p1, 'user@test.com');

        $this->assertTrue($this->model->exists($p1, 'user@test.com'));
        $this->assertFalse($this->model->exists($p2, 'user@test.com'));
    }

    // ── getPending() ──────────────────────────────────────────────────────────

    public function test_get_pending_returns_unnotified_alerts(): void
    {
        $productId = $this->insertProduct();
        $this->insertAlert($productId, 'a@test.com');
        $this->insertAlert($productId, 'b@test.com');

        $pending = $this->model->getPending($productId);

        $emails = array_column($pending, 'email');
        $this->assertContains('a@test.com', $emails);
        $this->assertContains('b@test.com', $emails);
    }

    public function test_get_pending_excludes_already_notified(): void
    {
        $productId = $this->insertProduct();
        $this->insertAlert($productId, 'notified@test.com', date('Y-m-d H:i:s'));
        $this->insertAlert($productId, 'pending@test.com');

        $pending = $this->model->getPending($productId);

        $emails = array_column($pending, 'email');
        $this->assertContains('pending@test.com', $emails);
        $this->assertNotContains('notified@test.com', $emails);
    }

    public function test_get_pending_returns_empty_when_all_notified(): void
    {
        $productId = $this->insertProduct();
        $this->insertAlert($productId, 'done@test.com', date('Y-m-d H:i:s'));

        $this->assertSame([], $this->model->getPending($productId));
    }

    // ── markNotified() ────────────────────────────────────────────────────────

    public function test_mark_notified_sets_notified_at_for_all_pending(): void
    {
        $productId = $this->insertProduct();
        $this->insertAlert($productId, 'x@test.com');
        $this->insertAlert($productId, 'y@test.com');

        $this->model->markNotified($productId);

        $pending = $this->model->getPending($productId);
        $this->assertSame([], $pending);
    }

    public function test_mark_notified_does_not_affect_other_products(): void
    {
        $p1 = $this->insertProduct();
        $p2 = $this->insertProduct();
        $this->insertAlert($p1, 'p1@test.com');
        $this->insertAlert($p2, 'p2@test.com');

        $this->model->markNotified($p1);

        $this->assertSame([], $this->model->getPending($p1));
        $this->assertCount(1, $this->model->getPending($p2));
    }
}
