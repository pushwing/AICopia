<?php

declare(strict_types=1);

// PHPUnit 전용 CI4 부트스트랩 (phpstan-bootstrap.php 와 분리)
defined('HOMEPATH')   || define('HOMEPATH',   realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR);
defined('CONFIGPATH') || define('CONFIGPATH', realpath(__DIR__ . '/../app/Config') . DIRECTORY_SEPARATOR);
defined('PUBLICPATH') || define('PUBLICPATH', realpath(__DIR__ . '/../public') . DIRECTORY_SEPARATOR);

require_once __DIR__ . '/../vendor/codeigniter4/framework/system/Test/bootstrap.php';
