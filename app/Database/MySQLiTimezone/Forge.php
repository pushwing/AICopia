<?php

declare(strict_types=1);

namespace App\Database\MySQLiTimezone;

use CodeIgniter\Database\MySQLi\Forge as MySQLiForge;

/**
 * 표준 MySQLi Forge를 그대로 쓴다.
 *
 * CI4의 Database::initDriver()는 DBDriver에 지정된 네임스페이스 뒤에
 * Connection·Forge·Utils를 각각 붙여 클래스를 찾는다. 타임존 관련 동작은
 * Connection에만 필요하지만, 이 클래스가 없으면 마이그레이션·Forge 사용 시
 * "class not found"로 죽으므로 얇은 상속 클래스를 둔다.
 */
class Forge extends MySQLiForge
{
}
