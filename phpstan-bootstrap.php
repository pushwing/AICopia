<?php

declare(strict_types=1);

// PHPStan용 부트스트랩 — MySQL 없는 환경에서 SQLite로 대체
// ENVIRONMENT=testing → Config\Database가 tests 그룹(SQLite :memory:) 선택
$_SERVER['CI_ENVIRONMENT'] = 'testing';
defined('ENVIRONMENT') || define('ENVIRONMENT', 'testing');
defined('CI_DEBUG')    || define('CI_DEBUG', false);

defined('HOMEPATH')   || define('HOMEPATH',   realpath(__DIR__) . DIRECTORY_SEPARATOR);
defined('CONFIGPATH') || define('CONFIGPATH', realpath(__DIR__ . '/app/Config') . DIRECTORY_SEPARATOR);
defined('PUBLICPATH') || define('PUBLICPATH', realpath(__DIR__ . '/public') . DIRECTORY_SEPARATOR);
defined('APPPATH')    || define('APPPATH',    realpath(__DIR__ . '/app') . DIRECTORY_SEPARATOR);
defined('ROOTPATH')   || define('ROOTPATH',   realpath(__DIR__) . DIRECTORY_SEPARATOR);
defined('SYSTEMPATH') || define('SYSTEMPATH', realpath(__DIR__ . '/vendor/codeigniter4/framework/system') . DIRECTORY_SEPARATOR);
defined('WRITEPATH')  || define('WRITEPATH',  realpath(__DIR__ . '/writable') . DIRECTORY_SEPARATOR);
defined('FCPATH')        || define('FCPATH',        realpath(__DIR__ . '/public') . DIRECTORY_SEPARATOR);
defined('CIPATH')        || define('CIPATH',        realpath(SYSTEMPATH . '..') . DIRECTORY_SEPARATOR);
defined('TESTPATH')      || define('TESTPATH',      realpath(__DIR__ . '/tests') . DIRECTORY_SEPARATOR);
defined('SUPPORTPATH')   || define('SUPPORTPATH',   realpath(__DIR__ . '/tests/_support') . DIRECTORY_SEPARATOR);
defined('COMPOSER_PATH') || define('COMPOSER_PATH', (string) realpath(__DIR__ . '/vendor/autoload.php'));
defined('VENDORPATH')    || define('VENDORPATH',    realpath(__DIR__ . '/vendor') . DIRECTORY_SEPARATOR);

// CI4 앱 상수 (APP_NAMESPACE, EXIT_*, 시간 상수 등)
require_once APPPATH . 'Config/Constants.php';

// Composer 오토로더
require_once ROOTPATH . 'vendor/autoload.php';

// CI4 내부 오토로더 (Config\*, App\* 네임스페이스 해소)
if (! class_exists(\Config\Autoload::class, false)) {
    require_once SYSTEMPATH . 'Config/AutoloadConfig.php';
    require_once APPPATH    . 'Config/Autoload.php';
    require_once SYSTEMPATH . 'Modules/Modules.php';
    require_once APPPATH    . 'Config/Modules.php';
}
require_once SYSTEMPATH . 'Autoloader/Autoloader.php';
require_once SYSTEMPATH . 'Config/BaseService.php';
require_once SYSTEMPATH . 'Config/Services.php';
require_once APPPATH    . 'Config/Services.php';

\CodeIgniter\Config\Services::autoloader()
    ->initialize(new \Config\Autoload(), new \Config\Modules())
    ->register();

// CI4 공통 헬퍼 함수 (model(), service() 등)
require_once SYSTEMPATH . 'Common.php';

// URL 헬퍼 함수 정의 (base_url, current_url, uri_string 등)
// PHPStan이 함수를 알아야 function.notFound 에러가 안 남
require_once SYSTEMPATH . 'Helpers/url_helper.php';

// app/Config/Settings.php가 'group' => 'default'를 설정하므로
// phpstan-codeigniter의 SchemaMigrator가 Database::forge('default')를 호출.
// MySQL 접속 시도를 막기 위해 'default' 그룹을 SQLite in-memory로 교체.
$dbConfig          = config(\Config\Database::class);
$dbConfig->default = [
    'DBDriver' => 'SQLite3',
    'database' => ':memory:',
    'DBPrefix' => '',
    'DBDebug'  => false,
];
