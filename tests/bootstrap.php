<?php

declare(strict_types=1);

// PHPUnit 전용 CI4 부트스트랩 (phpstan-bootstrap.php 와 분리)
defined('HOMEPATH')   || define('HOMEPATH', realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR);
defined('CONFIGPATH') || define('CONFIGPATH', realpath(__DIR__ . '/../app/Config') . DIRECTORY_SEPARATOR);
defined('PUBLICPATH') || define('PUBLICPATH', realpath(__DIR__ . '/../public') . DIRECTORY_SEPARATOR);

require_once __DIR__ . '/../vendor/codeigniter4/framework/system/Test/bootstrap.php';

// ParaTest 병렬 실행 시 worker마다 전용 테스트 DB를 쓰게 한다(공유 DB 충돌 방지).
// ParaTest는 worker별로 TEST_TOKEN(1..N)을 주입한다. testing 환경에선 defaultGroup='tests'
// (app/Config/Database.php)이므로 tests 그룹 DB명만 aicopia_test_{token}으로 바꾸면
// 모델·db_connect() 등 모든 연결이 전용 DB를 따른다. TEST_TOKEN이 없으면(순차 실행) 그대로 둔다.
//
// 주의: config('Database') 객체를 직접 바꾸면 CIUnitTestCase가 매 테스트 config를 리셋할 때
// 지워진다. 대신 CI4가 config 재구성 때마다 읽는 env 키(database.tests.database)를 슈퍼글로벌에
// 심어 리셋 이후에도 유지되게 한다.
$testToken = getenv('TEST_TOKEN');
if ($testToken !== false && (int) $testToken > 0) {
    $envKey                = 'database.tests.database';
    $baseDb                = $_SERVER[$envKey] ?? $_ENV[$envKey] ?? (getenv($envKey) ?: 'aicopia_test');
    $workerDb              = $baseDb . '_' . (int) $testToken;
    putenv("{$envKey}={$workerDb}");
    $_ENV[$envKey]         = $workerDb;
    $_SERVER[$envKey]      = $workerDb;
}
