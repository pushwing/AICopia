<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\SettingModel;

class WelcomeController extends BaseController
{
    private SettingModel $settingModel;

    public function __construct()
    {
        $this->settingModel = new SettingModel();
    }

    public function index(): string
    {
        $rows = $this->settingModel->where('group', 'welcome')->orderBy('id')->findAll();
        $cfg  = array_column($rows, null, 'key');

        return $this->render('admin/welcome/index', ['cfg' => $cfg]);
    }

    public function update(): \CodeIgniter\HTTP\RedirectResponse
    {
        $post = $this->request->getPost();
        unset($post[csrf_token()]);

        $boolKeys = [
            'welcome_show_hero', 'welcome_show_categories', 'welcome_show_featured',
            'welcome_show_new', 'welcome_show_discount', 'welcome_show_bottom_banner',
        ];
        foreach ($boolKeys as $k) {
            $post[$k] = isset($post[$k]) ? $post[$k] : '0';
        }

        $this->settingModel->saveSettings($post);

        return redirect()->to('/admin/welcome')->with('success', 'Welcome 페이지 설정이 저장되었습니다.');
    }
}
