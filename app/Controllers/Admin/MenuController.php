<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\MenuModel;

class MenuController extends BaseController
{
    private MenuModel $menuModel;

    public function __construct()
    {
        $this->menuModel = new MenuModel();
    }

    public function index(): string
    {
        return $this->render('admin/menus/index', [
            'menus' => $this->menuModel->orderBy('sort_order')->findAll(),
        ]);
    }

    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->menuModel->insert([
            'parent_id'  => $this->request->getPost('parent_id') ?: null,
            'title'      => $this->request->getPost('title'),
            'url'        => $this->request->getPost('url'),
            'target'     => $this->request->getPost('target') ?: '_self',
            'sort_order' => (int) $this->request->getPost('sort_order'),
            'is_active'  => 1,
        ]);
        $this->menuModel->clearCache();
        return redirect()->to('/admin/menus')->with('success', '메뉴가 추가되었습니다.');
    }

    public function update(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->menuModel->update($id, [
            'title'      => $this->request->getPost('title'),
            'url'        => $this->request->getPost('url'),
            'target'     => $this->request->getPost('target') ?: '_self',
            'sort_order' => (int) $this->request->getPost('sort_order'),
            'is_active'  => (int) $this->request->getPost('is_active'),
        ]);
        $this->menuModel->clearCache();
        return redirect()->to('/admin/menus')->with('success', '수정되었습니다.');
    }

    public function move(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $direction = $this->request->getPost('direction');
        $current   = $this->menuModel->find($id);
        if (! $current || ! in_array($direction, ['up', 'down'], true)) {
            return $this->response->setJSON(['ok' => false]);
        }

        $db      = db_connect();
        $builder = $db->table('menus');

        if (empty($current['parent_id'])) {
            $builder->where('parent_id IS NULL', null, false);
        } else {
            $builder->where('parent_id', $current['parent_id']);
        }

        // sort_order 중복 대비: id를 보조 정렬 키로 사용
        $siblings = $builder->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')
                            ->get()->getResultArray();

        $currentIdx = null;
        foreach ($siblings as $i => $s) {
            if ((int) $s['id'] === $id) { $currentIdx = $i; break; }
        }

        if ($currentIdx === null) {
            return $this->response->setJSON(['ok' => false]);
        }

        $swapIdx = $direction === 'up' ? $currentIdx - 1 : $currentIdx + 1;

        if ($swapIdx < 0 || $swapIdx >= count($siblings)) {
            return $this->response->setJSON(['ok' => false]);
        }

        // 배열에서 위치 교환 후 sort_order 재정규화 (0, 1, 2, …)
        [$siblings[$currentIdx], $siblings[$swapIdx]] = [$siblings[$swapIdx], $siblings[$currentIdx]];

        foreach ($siblings as $i => $s) {
            $this->menuModel->update((int) $s['id'], ['sort_order' => $i]);
        }

        $this->menuModel->clearCache();
        return $this->response->setJSON(['ok' => true]);
    }

    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->menuModel->delete($id);
        $this->menuModel->clearCache();
        return redirect()->to('/admin/menus')->with('success', '삭제되었습니다.');
    }
}
