<?php

declare(strict_types=1);

namespace App\Database\MySQLiTimezone;

use CodeIgniter\Database\MySQLi\Builder as MySQLiBuilder;

/**
 * 표준 MySQLi Builder를 그대로 쓴다.
 *
 * 존재 이유는 Result와 같다 — BaseConnection이 static::class 이름 치환으로 찾는다.
 */
class Builder extends MySQLiBuilder
{
}
