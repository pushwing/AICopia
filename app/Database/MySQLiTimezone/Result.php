<?php

declare(strict_types=1);

namespace App\Database\MySQLiTimezone;

use CodeIgniter\Database\MySQLi\Result as MySQLiResult;

/**
 * 표준 MySQLi Result를 그대로 쓴다.
 *
 * BaseConnection이 결과 객체 클래스를 static::class의 'Connection'을 'Result'로
 * 치환해 찾기 때문에, 커스텀 드라이버 네임스페이스에도 같은 이름의 클래스가 있어야 한다.
 */
class Result extends MySQLiResult
{
}
