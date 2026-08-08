<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\BoardController;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * BoardController::download() 접근 권한 검증 (이슈 #118)
 *
 * download()는 view()와 동일한 두 가지 관문을 통과해야 한다:
 *  - boards.read_permission (guest/member/admin)
 *  - posts.is_secret (작성자 본인 또는 관리자만)
 *
 * 순차 정수 id로 첨부를 열거해 비밀글·회원전용 게시판 첨부를
 * 내려받을 수 있으면 안 된다.
 */
final class BoardFileDownloadAuthzTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['post_files' => [], 'posts' => [], 'boards' => [], 'users' => []];

    /** @var array<int, string> 생성한 임시 파일 절대경로 */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'BFDA' . substr(uniqid(), -6) . '_';
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['post_files', 'posts', 'boards', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['post_files' => [], 'posts' => [], 'boards' => [], 'users' => []];

        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];

        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertBoard(string $readPermission = 'guest'): int
    {
        $uid = $this->prefix . substr(uniqid(), -6);
        $db  = db_connect();
        $db->table('boards')->insert([
            'slug'            => $uid,
            'name'            => $uid,
            'read_permission' => $readPermission,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['boards'][] = $id;

        return $id;
    }

    private function insertUser(string $role = 'member'): int
    {
        $uid = $this->prefix . substr(uniqid(), -6);
        $db  = db_connect();
        $db->table('users')->insert([
            'username'   => $uid,
            'email'      => $uid . '@example.test',
            'password'   => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'   => $uid,
            'role'       => $role,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function insertPost(int $boardId, bool $isSecret = false, ?int $userId = null): int
    {
        $db = db_connect();
        $db->table('posts')->insert([
            'board_id'    => $boardId,
            'user_id'     => $userId,
            'title'       => $this->prefix . 'title',
            'content'     => 'body',
            'author_name' => '작성자',
            'is_secret'   => $isSecret ? 1 : 0,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['posts'][] = $id;

        return $id;
    }

    /** 실제 파일까지 생성해 "파일 없음" 리다이렉트와 권한 리다이렉트를 구분한다. */
    private function insertFile(int $postId): int
    {
        $storedName = $this->prefix . substr(uniqid(), -6) . '.txt';
        $relPath    = 'uploads/board/files/' . $storedName;
        $fullPath   = FCPATH . $relPath;

        if (! is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0775, true);
        }
        file_put_contents($fullPath, 'secret-attachment-body');
        $this->tempFiles[] = $fullPath;

        $db = db_connect();
        $db->table('post_files')->insert([
            'post_id'       => $postId,
            'original_name' => '기밀문서.txt',
            'stored_name'   => $storedName,
            'file_path'     => $relPath,
            'file_size'     => filesize($fullPath),
            'mime_type'     => 'text/plain',
            'is_image'      => 0,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['post_files'][] = $id;

        return $id;
    }

    private function controller(): BoardController
    {
        $controller = new BoardController();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller;
    }

    private function loginAs(int $userId, string $role): void
    {
        session()->set(['user_id' => $userId, 'user_role' => $role]);
    }

    // ── 차단되어야 하는 경우 ──────────────────────────────────────────────────

    public function testGuestCannotDownloadSecretPostAttachment(): void
    {
        $boardId = $this->insertBoard('guest');
        $postId  = $this->insertPost($boardId, true, $this->insertUser());
        $fileId  = $this->insertFile($postId);

        $result = $this->controller()->download($fileId);

        $this->assertInstanceOf(
            RedirectResponse::class,
            $result,
            '비밀글 첨부가 비로그인 사용자에게 다운로드됐다',
        );
    }

    public function testOtherMemberCannotDownloadSecretPostAttachment(): void
    {
        $boardId = $this->insertBoard('guest');
        $postId  = $this->insertPost($boardId, true, $this->insertUser());
        $fileId  = $this->insertFile($postId);

        $this->loginAs($this->insertUser(), 'member');
        $result = $this->controller()->download($fileId);

        $this->assertInstanceOf(
            RedirectResponse::class,
            $result,
            '남의 비밀글 첨부가 다른 회원에게 다운로드됐다',
        );
    }

    public function testGuestCannotDownloadAttachmentOnMemberOnlyBoard(): void
    {
        $boardId = $this->insertBoard('member');
        $postId  = $this->insertPost($boardId);
        $fileId  = $this->insertFile($postId);

        $result = $this->controller()->download($fileId);

        $this->assertInstanceOf(
            RedirectResponse::class,
            $result,
            '회원전용 게시판 첨부가 비로그인 사용자에게 다운로드됐다',
        );
    }

    public function testMemberCannotDownloadAttachmentOnAdminOnlyBoard(): void
    {
        $boardId = $this->insertBoard('admin');
        $postId  = $this->insertPost($boardId);
        $fileId  = $this->insertFile($postId);

        $this->loginAs($this->insertUser(), 'member');
        $result = $this->controller()->download($fileId);

        $this->assertInstanceOf(
            RedirectResponse::class,
            $result,
            '관리자전용 게시판 첨부가 일반 회원에게 다운로드됐다',
        );
    }

    public function testMissingFileRecordRedirects(): void
    {
        $result = $this->controller()->download(0);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    public function testOrphanedFileWithoutPostRedirects(): void
    {
        // 게시글이 삭제됐는데 첨부 행만 남은 경우 — 권한 판정 불가이므로 차단한다.
        $boardId = $this->insertBoard('guest');
        $postId  = $this->insertPost($boardId);
        $fileId  = $this->insertFile($postId);

        db_connect()->table('posts')->where('id', $postId)->delete();

        $result = $this->controller()->download($fileId);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    // ── 허용되어야 하는 경우 (회귀 방지) ──────────────────────────────────────

    public function testGuestCanDownloadPublicPostAttachment(): void
    {
        $boardId = $this->insertBoard('guest');
        $postId  = $this->insertPost($boardId);
        $fileId  = $this->insertFile($postId);

        $result = $this->controller()->download($fileId);

        $this->assertNotInstanceOf(
            RedirectResponse::class,
            $result,
            '공개 게시판의 일반글 첨부 다운로드가 막혔다',
        );
    }

    public function testAuthorCanDownloadOwnSecretPostAttachment(): void
    {
        $authorId = $this->insertUser();
        $boardId  = $this->insertBoard('guest');
        $postId   = $this->insertPost($boardId, true, $authorId);
        $fileId   = $this->insertFile($postId);

        $this->loginAs($authorId, 'member');
        $result = $this->controller()->download($fileId);

        $this->assertNotInstanceOf(
            RedirectResponse::class,
            $result,
            '작성자 본인의 비밀글 첨부 다운로드가 막혔다',
        );
    }

    public function testAdminCanDownloadSecretPostAttachment(): void
    {
        $boardId = $this->insertBoard('guest');
        $postId  = $this->insertPost($boardId, true, $this->insertUser());
        $fileId  = $this->insertFile($postId);

        $this->loginAs($this->insertUser('admin'), 'admin');
        $result = $this->controller()->download($fileId);

        $this->assertNotInstanceOf(
            RedirectResponse::class,
            $result,
            '관리자의 비밀글 첨부 다운로드가 막혔다',
        );
    }

    public function testMemberCanDownloadAttachmentOnMemberOnlyBoard(): void
    {
        $boardId = $this->insertBoard('member');
        $postId  = $this->insertPost($boardId);
        $fileId  = $this->insertFile($postId);

        $this->loginAs($this->insertUser(), 'member');
        $result = $this->controller()->download($fileId);

        $this->assertNotInstanceOf(
            RedirectResponse::class,
            $result,
            '회원전용 게시판 첨부 다운로드가 회원에게 막혔다',
        );
    }
}
