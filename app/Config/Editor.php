<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Editor extends BaseConfig
{
    public string $tinymceApiKey = 'no-api-key';

    public function __construct()
    {
        parent::__construct();
        $key = env('editor.tinymce_api_key');
        if ($key) {
            $this->tinymceApiKey = $key;
        }
    }
}
