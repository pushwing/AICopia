<?php

namespace App\Models;

use CodeIgniter\Model;

class PopupModel extends Model
{
    protected $table         = 'popups';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'title', 'image_path', 'content', 'show_scope',
        'pos_x', 'pos_y', 'priority', 'is_active', 'started_at', 'ended_at',
    ];

    public const SCOPES = [
        'all'       => '전체 페이지',
        'home_only' => '홈 전용',
        'specific'  => '특정 페이지',
    ];

    protected $afterInsert = ['clearCacheCallback'];
    protected $afterUpdate = ['clearCacheCallback'];
    protected $afterDelete = ['clearCacheCallback'];

    /**
     * 현재 URI 기준으로 노출할 팝업 목록 반환
     * 활성 팝업·페이지 매핑을 캐시하고 기간·스코프는 PHP에서 필터링 (캐시 1시간)
     */
    public function getActiveForPage(string $uri): array
    {
        $cached = (array) cache()->remember('active_popups', 3600, function () {
            $popups = $this->where('is_active', 1)->orderBy('priority', 'ASC')->findAll();

            $pageUrls = [];
            $rows = \Config\Database::connect()->table('popup_pages pp')
                ->select('pp.popup_id, m.url')
                ->join('menus m', 'm.id = pp.menu_id')
                ->get()->getResultArray();
            foreach ($rows as $row) {
                $pageUrls[$row['popup_id']][] = $row['url'];
            }

            return ['popups' => $popups, 'pageUrls' => $pageUrls];
        });

        $now        = date('Y-m-d H:i:s');
        $isHome     = ($uri === '' || $uri === '/');
        $currentUrl = '/' . ltrim($uri, '/');

        $result = [];
        foreach ((array) ($cached['popups'] ?? []) as $popup) {
            if ($popup['started_at'] !== null && $popup['started_at'] > $now) continue;
            if ($popup['ended_at'] !== null && $popup['ended_at'] < $now) continue;

            $show = match ($popup['show_scope']) {
                'all'       => true,
                'home_only' => $isHome,
                'specific'  => in_array($currentUrl, (array) ($cached['pageUrls'][$popup['id']] ?? []), true),
                default     => false,
            };
            if ($show) {
                $result[] = $popup;
            }
        }

        return $result;
    }

    protected function clearCacheCallback(array $data): array
    {
        cache()->delete('active_popups');
        return $data;
    }

    /**
     * 팝업에 연결된 menu_id 배열 반환
     */
    public function getPageIds(int $popupId): array
    {
        $rows = \Config\Database::connect()
            ->table('popup_pages')
            ->where('popup_id', $popupId)
            ->get()->getResultArray();
        return array_column($rows, 'menu_id');
    }

    /**
     * 팝업-페이지 연결 동기화 (트랜잭션)
     */
    public function syncPages(int $popupId, array $menuIds): void
    {
        $db = \Config\Database::connect();
        $db->transStart();

        $db->table('popup_pages')->where('popup_id', $popupId)->delete();

        if (! empty($menuIds)) {
            $rows = array_map(fn($menuId) => [
                'popup_id' => $popupId,
                'menu_id'  => (int) $menuId,
            ], $menuIds);
            $db->table('popup_pages')->insertBatch($rows);
        }

        $db->transComplete();

        cache()->delete('active_popups');
    }

    public function deleteWithFile(int $id): bool
    {
        $popup = $this->db->table($this->table)->where('id', $id)->get()->getRowArray();
        if (! $popup) return false;

        if (! empty($popup['image_path'])) {
            $fullPath = FCPATH . $popup['image_path'];
            if (file_exists($fullPath)) unlink($fullPath);
        }

        \Config\Database::connect()->table('popup_pages')->where('popup_id', $id)->delete();

        return (bool) $this->delete($id);
    }
}
