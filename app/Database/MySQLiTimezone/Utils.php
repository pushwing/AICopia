<?php

declare(strict_types=1);

namespace App\Database\MySQLiTimezone;

use CodeIgniter\Database\MySQLi\Utils as MySQLiUtils;

/**
 * 표준 MySQLi Utils를 그대로 쓴다.
 *
 * 존재 이유는 Forge와 같다 — CI4가 DBDriver 네임스페이스에서 Utils도 찾기 때문이다.
 */
class Utils extends MySQLiUtils
{
}
