<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class SupplierController extends BaseController
{
    public function index(): string
    {
        $db        = \Config\Database::connect();
        $suppliers = $db->table('suppliers')->orderBy('name', 'ASC')->get()->getResultArray();

        return $this->render('admin/suppliers/list', compact('suppliers'));
    }

    public function create(): string
    {
        return $this->render('admin/suppliers/form', ['supplier' => null]);
    }

    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        if (! $this->validate($this->rules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data         = $this->collectData();
        $fileUploaded = $this->hasLicenseFile();
        $path         = $this->uploadLicense();

        if ($fileUploaded && $path === null) {
            return redirect()->back()->withInput()
                ->with('errors', ['business_license' => '허용되지 않는 파일 형식 또는 크기입니다. (PDF, JPG, PNG / 5MB 이하)']);
        }
        if ($path !== null) {
            $data['business_license_path'] = $path;
        }

        \Config\Database::connect()->table('suppliers')->insert($data + [
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->to('/admin/suppliers')->with('success', '매입처가 등록되었습니다.');
    }

    public function edit(int $id): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $db       = \Config\Database::connect();
        $supplier = $db->table('suppliers')->where('id', $id)->get()->getRowArray();
        if (! $supplier) {
            return redirect()->to('/admin/suppliers')->with('error', '매입처를 찾을 수 없습니다.');
        }
        return $this->render('admin/suppliers/form', compact('supplier'));
    }

    public function update(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db       = \Config\Database::connect();
        $supplier = $db->table('suppliers')->where('id', $id)->get()->getRowArray();
        if (! $supplier) {
            return redirect()->to('/admin/suppliers')->with('error', '매입처를 찾을 수 없습니다.');
        }

        if (! $this->validate($this->rules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data         = $this->collectData();
        $fileUploaded = $this->hasLicenseFile();
        $path         = $this->uploadLicense();

        if ($fileUploaded && $path === null) {
            return redirect()->back()->withInput()
                ->with('errors', ['business_license' => '허용되지 않는 파일 형식 또는 크기입니다. (PDF, JPG, PNG / 5MB 이하)']);
        }
        if ($path !== null) {
            // 기존 파일 삭제
            if (! empty($supplier['business_license_path'])) {
                $old = FCPATH . ltrim($supplier['business_license_path'], '/');
                if (is_file($old)) unlink($old);
            }
            $data['business_license_path'] = $path;
        }

        $db->table('suppliers')->where('id', $id)->update($data + ['updated_at' => date('Y-m-d H:i:s')]);

        return redirect()->to('/admin/suppliers')->with('success', '저장되었습니다.');
    }

    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db       = \Config\Database::connect();
        $supplier = $db->table('suppliers')->where('id', $id)->get()->getRowArray();
        if ($supplier && ! empty($supplier['business_license_path'])) {
            $file = FCPATH . ltrim($supplier['business_license_path'], '/');
            if (is_file($file)) unlink($file);
        }
        $db->table('products')->where('supplier_id', $id)->update(['supplier_id' => null]);
        $db->table('suppliers')->where('id', $id)->delete();

        return redirect()->to('/admin/suppliers')->with('success', '삭제되었습니다.');
    }

    // ── private ──────────────────────────────────────────────────────────────

    private function rules(): array
    {
        return [
            'name'           => 'required|max_length[100]',
            'business_no'    => 'required|max_length[20]',
            'contact_person' => 'required|max_length[50]',
            'phone'          => 'required|max_length[30]',
            'email'          => 'required|valid_email|max_length[100]',
        ];
    }

    private function collectData(): array
    {
        return [
            'name'           => trim($this->request->getPost('name') ?? ''),
            'business_no'    => trim($this->request->getPost('business_no') ?? '') ?: null,
            'contact_person' => trim($this->request->getPost('contact_person') ?? ''),
            'phone'          => trim($this->request->getPost('phone') ?? ''),
            'email'          => trim($this->request->getPost('email') ?? ''),
            'memo'           => trim($this->request->getPost('memo') ?? '') ?: null,
        ];
    }

    /** 사업자등록증 파일이 실제로 전송됐는지 여부 */
    private function hasLicenseFile(): bool
    {
        $file = $this->request->getFile('business_license');
        return $file && $file->isValid() && ! $file->hasMoved();
    }

    /** 사업자등록증 파일 업로드. 업로드된 파일이 없으면 null 반환. */
    private function uploadLicense(): ?string
    {
        $file = $this->request->getFile('business_license');
        if (! $file || ! $file->isValid() || $file->hasMoved()) {
            return null;
        }

        $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
        if (! in_array($file->getMimeType(), $allowed, true)) {
            return null;
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return null;
        }

        $dir  = FCPATH . 'uploads/suppliers/';
        if (! is_dir($dir)) mkdir($dir, 0755, true);

        $name = $file->getRandomName();
        $file->move($dir, $name);

        return '/uploads/suppliers/' . $name;
    }
}
