<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\WithdrawnUserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * users:purge-withdrawn — 설정 게이트와 파기 동작 검증
 */
final class PurgeWithdrawnUsersCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** @var list<int> */
    private array $snapshotIds = [];
    private ?string $originalEnabled = null;

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->snapshotIds !== []) {
            $db->table('withdrawn_users')->whereIn('id', $this->snapshotIds)->delete();
            $this->snapshotIds = [];
        }
        if ($this->originalEnabled !== null) {
            $db->table('settings')
                ->where('key', 'schedule_users_purge_withdrawn_enabled')
                ->update(['value' => $this->originalEnabled]);
            $this->originalEnabled = null;
        }
        cache()->delete('site_settings');

        parent::tearDown();
    }

    private function setEnabled(string $value): void
    {
        $db  = db_connect();
        $row = $db->table('settings')
            ->where('key', 'schedule_users_purge_withdrawn_enabled')
            ->get()->getRowArray();

        $this->originalEnabled = (string) $row['value'];
        $db->table('settings')
            ->where('key', 'schedule_users_purge_withdrawn_enabled')
            ->update(['value' => $value]);
        cache()->delete('site_settings');
    }

    private function insertExpiredSnapshot(): int
    {
        $uid = 'PC' . substr(uniqid(), -8);
        $db  = db_connect();
        $db->table('withdrawn_users')->insert([
            'user_id'      => 800000 + random_int(1, 99999),
            'email'        => $uid . '@example.test',
            'nickname'     => $uid,
            'reason_code'  => 'etc',
            'withdrawn_by' => 'member',
            'withdrawn_at' => date('Y-m-d H:i:s', strtotime('-90 days')),
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->snapshotIds[] = $id;

        return $id;
    }

    public function testCommandSkipsWhenDisabled(): void
    {
        $this->setEnabled('0');
        $id = $this->insertExpiredSnapshot();

        command('users:purge-withdrawn 30');

        $row = new WithdrawnUserModel()->find($id);
        $this->assertNotNull($row['email'], '비활성 상태에서는 파기하면 안 된다');
        $this->assertNull($row['purged_at']);
    }

    public function testCommandPurgesExpiredSnapshot(): void
    {
        $this->setEnabled('1');
        $id = $this->insertExpiredSnapshot();

        command('users:purge-withdrawn 30');

        $row = new WithdrawnUserModel()->find($id);
        $this->assertNull($row['email']);
        $this->assertNotNull($row['purged_at']);
        $this->assertSame('etc', $row['reason_code'], '통계용 사유는 남아야 한다');
    }
}
