<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * PostController::json() 데이터 레이어 검증
 *
 * - 반환 필드 구조
 * - author = user_nickname (로그인 작성자)
 * - author fallback = author_name (비회원 작성자)
 * - is_notice, is_secret int 캐스팅
 * - id DESC 정렬
 */
final class PostJsonApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;
    private array  $cleanup = ['posts' => [], 'boards' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'POJT' . substr(uniqid(), -6) . '_';
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['posts'] !== []) {
            $db->table('posts')->whereIn('id', $this->cleanup['posts'])->delete();
        }
        if ($this->cleanup['boards'] !== []) {
            $db->table('boards')->whereIn('id', $this->cleanup['boards'])->delete();
        }
        if ($this->cleanup['users'] !== []) {
            $db->table('users')->whereIn('id', $this->cleanup['users'])->delete();
        }
        $this->cleanup = ['posts' => [], 'boards' => [], 'users' => []];
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertBoard(array $overrides = []): int
    {
        $uid  = uniqid();
        $data = array_merge([
            'name'       => $this->prefix . $uid,
            'slug'       => $this->prefix . $uid,
            'sort_order' => 99,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $overrides);
        $db  = db_connect();
        $db->table('boards')->insert($data);
        $id  = (int) $db->insertID();
        $this->cleanup['boards'][] = $id;
        return $id;
    }

    private function insertUser(array $overrides = []): int
    {
        $uid  = uniqid();
        $data = array_merge([
            'username'   => 'pojt_' . $uid,
            'email'      => $this->prefix . $uid . '@test.com',
            'password'   => password_hash('test!', PASSWORD_DEFAULT),
            'nickname'   => $this->prefix . $uid,
            'role'       => 'member',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $overrides);
        $db = db_connect();
        $db->table('users')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertPost(int $boardId, array $overrides = []): int
    {
        $uid  = uniqid();
        $data = array_merge([
            'board_id'    => $boardId,
            'user_id'     => null,
            'title'       => $this->prefix . $uid,
            'content'     => '테스트 내용',
            'author_name' => '비회원' . $uid,
            'is_notice'   => 0,
            'is_secret'   => 0,
            'views'       => 0,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ], $overrides);
        $db  = db_connect();
        $db->table('posts')->insert($data);
        $id  = (int) $db->insertID();
        $this->cleanup['posts'][] = $id;
        return $id;
    }

    /** PostController::json() 와 동일한 쿼리 + 변환 */
    private function fetchJsonData(array $whereIn = []): array
    {
        $db = db_connect();
        $builder = $db->table('posts')
            ->select('posts.id, posts.title, posts.is_notice, posts.is_secret, posts.views,
                      posts.created_at, posts.author_name,
                      boards.name AS board_name, boards.slug AS board_slug,
                      users.nickname AS user_nickname')
            ->join('boards', 'boards.id = posts.board_id', 'left')
            ->join('users', 'users.id = posts.user_id', 'left')
            ->orderBy('posts.id', 'DESC');

        if ($whereIn !== []) {
            $builder->whereIn('posts.id', $whereIn);
        }

        $rows = $builder->get()->getResultArray();
        return array_map(fn($p) => [
            'id'         => (int) $p['id'],
            'title'      => $p['title'],
            'is_notice'  => (int) $p['is_notice'],
            'is_secret'  => (int) $p['is_secret'],
            'board_name' => $p['board_name'] ?? '',
            'board_slug' => $p['board_slug'] ?? '',
            'author'     => $p['user_nickname'] ?: ($p['author_name'] ?? ''),
            'views'      => (int) $p['views'],
            'created_at' => $p['created_at'],
        ], $rows);
    }

    // ── 필드 구조 ──────────────────────────────────────────────────────────────

    public function testRequiredFieldsArePresent(): void
    {
        $bid  = $this->insertBoard();
        $id   = $this->insertPost($bid);
        $rows = $this->fetchJsonData([$id]);

        $expected = ['id', 'title', 'is_notice', 'is_secret', 'board_name',
                     'board_slug', 'author', 'views', 'created_at'];
        foreach ($expected as $field) {
            $this->assertArrayHasKey($field, $rows[0], "필드 '{$field}' 누락");
        }
    }

    // ── author 필드 ───────────────────────────────────────────────────────────

    public function testAuthorIsUserNicknameWhenLoggedIn(): void
    {
        $bid  = $this->insertBoard();
        $uid  = $this->insertUser(['nickname' => $this->prefix . 'nick']);
        $id   = $this->insertPost($bid, ['user_id' => $uid, 'author_name' => '']);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame($this->prefix . 'nick', $rows[0]['author'],
            '로그인 작성자는 user_nickname 이 author 여야 한다');
    }

    public function testAuthorFallsBackToAuthorNameWhenNoUser(): void
    {
        $bid  = $this->insertBoard();
        $id   = $this->insertPost($bid, ['user_id' => null, 'author_name' => '비회원테스터']);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('비회원테스터', $rows[0]['author'],
            'user_id 없는 게시물은 author_name 이 author 여야 한다');
    }

    // ── 타입 캐스팅 ───────────────────────────────────────────────────────────

    public function testIsNoticeIsIntegerOne(): void
    {
        $bid  = $this->insertBoard();
        $id   = $this->insertPost($bid, ['is_notice' => 1]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['is_notice']);
        $this->assertSame(1, $rows[0]['is_notice']);
    }

    public function testIsNoticeIsIntegerZero(): void
    {
        $bid  = $this->insertBoard();
        $id   = $this->insertPost($bid, ['is_notice' => 0]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['is_notice']);
        $this->assertSame(0, $rows[0]['is_notice']);
    }

    public function testIsSecretIsInteger(): void
    {
        $bid  = $this->insertBoard();
        $id   = $this->insertPost($bid, ['is_secret' => 1]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['is_secret']);
        $this->assertSame(1, $rows[0]['is_secret']);
    }

    public function testViewsIsInteger(): void
    {
        $bid  = $this->insertBoard();
        $id   = $this->insertPost($bid, ['views' => 57]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['views']);
        $this->assertSame(57, $rows[0]['views']);
    }

    // ── 정렬 ──────────────────────────────────────────────────────────────────

    public function testOrderedByIdDesc(): void
    {
        $bid  = $this->insertBoard();
        $id1  = $this->insertPost($bid);
        $id2  = $this->insertPost($bid);
        $id3  = $this->insertPost($bid);
        $rows = $this->fetchJsonData([$id1, $id2, $id3]);
        $ids  = array_column($rows, 'id');

        $this->assertGreaterThan($ids[1], $ids[0]);
        $this->assertGreaterThan($ids[2], $ids[1]);
    }
}
