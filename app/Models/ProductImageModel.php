<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductImageModel extends Model
{
    protected $table        = 'product_images';
    protected $primaryKey   = 'id';
    protected $useTimestamps = false;
    protected $updatedField  = '';
    protected $allowedFields = ['product_id', 'media_id', 'is_primary', 'sort_order', 'created_at'];

    public function getByProduct(int $productId): array
    {
        $rows = $this->select('product_images.*, media.file_path, media.alt')
            ->join('media', 'media.id = product_images.media_id')
            ->where('product_id', $productId)
            ->orderBy('is_primary', 'DESC')
            ->orderBy('sort_order', 'ASC')
            ->findAll();

        foreach ($rows as &$row) {
            $row['media_url'] = base_url($row['file_path']);
        }
        return $rows;
    }

    public function getPrimary(int $productId): ?array
    {
        $row = $this->select('product_images.*, media.file_path, media.alt')
            ->join('media', 'media.id = product_images.media_id')
            ->where('product_id', $productId)
            ->where('is_primary', 1)
            ->first();

        if ($row) {
            $row['media_url'] = base_url($row['file_path']);
        }
        return $row;
    }

    public function setPrimary(int $productId, int $mediaId): void
    {
        $this->where('product_id', $productId)->set('is_primary', 0)->update();
        $this->where(['product_id' => $productId, 'media_id' => $mediaId])->set('is_primary', 1)->update();
    }

    public function attachPrimaryImages(array &$items): void
    {
        if (empty($items)) return;

        $ids  = array_column($items, 'id');
        $rows = $this->select('product_images.product_id, media.file_path')
            ->join('media', 'media.id = product_images.media_id')
            ->whereIn('product_id', $ids)
            ->where('is_primary', 1)
            ->findAll();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['product_id']] = base_url($row['file_path']);
        }

        foreach ($items as &$item) {
            $item['primary_image'] = $map[$item['id']] ?? null;
        }
    }

    public function deleteByProduct(int $productId): void
    {
        $this->where('product_id', $productId)->delete();
    }
}
