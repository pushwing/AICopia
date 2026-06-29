<?php

namespace Config;

use App\Libraries\ThemeView;
use CodeIgniter\Config\BaseService;
use Config\View as ViewConfig;

class Services extends BaseService
{
    public static function renderer(?string $viewPath = null, ?ViewConfig $config = null, bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('renderer', $viewPath, $config);
        }

        $viewPath ??= (new \Config\Paths())->viewDirectory;
        $config   ??= config(ViewConfig::class);

        return new ThemeView(
            $config,
            $viewPath,
            \CodeIgniter\Config\Services::get('locator'),
            CI_DEBUG,
            \CodeIgniter\Config\Services::get('logger'),
        );
    }
}
