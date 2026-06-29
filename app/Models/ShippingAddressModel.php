<?php

namespace App\Models;

use CodeIgniter\Model;

class ShippingAddressModel extends Model
{
    protected $table         = 'shipping_addresses';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'user_id', 'receiver_name', 'receiver_phone', 'zipcode', 'address1', 'address2', 'is_default',
    ];

    public function getByUser(int $userId): array
    {
        return $this->where('user_id', $userId)->orderBy('is_default', 'DESC')->orderBy('id', 'DESC')->findAll();
    }

    public function getDefault(int $userId): ?array
    {
        return $this->where('user_id', $userId)->where('is_default', 1)->first();
    }

    /** 기본 배송지로 설정 (기존 기본 해제 후 지정) */
    public function setDefault(int $id, int $userId): bool
    {
        $address = $this->where('id', $id)->where('user_id', $userId)->first();
        if (! $address) {
            return false;
        }

        $this->db->transStart();
        $this->where('user_id', $userId)->set('is_default', 0)->update();
        $this->where('id', $id)->where('user_id', $userId)->set('is_default', 1)->update();
        $this->db->transComplete();

        return $this->db->transStatus();
    }

    public function deleteByUser(int $id, int $userId): bool
    {
        return $this->where('id', $id)->where('user_id', $userId)->delete() > 0;
    }

    /** 저장 또는 업데이트 — 동일 주소(zipcode + address1 + address2)면 덮어씀 */
    public function saveAddress(int $userId, array $data): int
    {
        $existing = $this->where('user_id', $userId)
            ->where('zipcode', $data['zipcode'])
            ->where('address1', $data['address1'])
            ->where('address2', $data['address2'] ?? null)
            ->first();

        if ($existing) {
            $this->update($existing['id'], [
                'receiver_name'  => $data['receiver_name'],
                'receiver_phone' => $data['receiver_phone'],
            ]);
            return (int) $existing['id'];
        }

        return (int) $this->insert(array_merge($data, ['user_id' => $userId]), true);
    }
}
