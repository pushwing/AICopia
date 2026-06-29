<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">기획전 관리</h4>
    <a href="/admin/promotions/create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>기획전 등록
    </a>
</div>

<div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
        <thead class="table-light">
            <tr>
                <th style="width:60px">순서</th>
                <th>제목</th>
                <th style="width:100px">슬러그</th>
                <th style="width:100px">접근등급</th>
                <th style="width:110px">기간</th>
                <th style="width:80px">상태</th>
                <th style="width:120px">관리</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($promotions)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">등록된 기획전이 없습니다.</td></tr>
        <?php else: ?>
        <?php
        $gradeLabels = [
            'all' => '전체', 'bronze' => 'Bronze',
            'silver' => 'Silver', 'gold' => 'Gold', 'platinum' => 'Platinum',
        ];
        $gradeColors = [
            'all' => 'secondary', 'bronze' => 'warning',
            'silver' => 'light', 'gold' => 'warning', 'platinum' => 'dark',
        ];
        foreach ($promotions as $p):
        ?>
        <tr>
            <td class="text-center text-muted"><?= (int) $p['sort_order'] ?></td>
            <td>
                <a href="/promotion/<?= esc($p['slug']) ?>" target="_blank" class="fw-semibold text-decoration-none">
                    <?= esc($p['title']) ?>
                </a>
            </td>
            <td class="small text-muted"><?= esc($p['slug']) ?></td>
            <td>
                <span class="badge bg-<?= $gradeColors[$p['grade_access']] ?? 'secondary' ?> text-dark">
                    <?= $gradeLabels[$p['grade_access']] ?? esc($p['grade_access']) ?>
                </span>
            </td>
            <td class="small text-muted">
                <?php
                $s = $p['start_date'] ? date('y.m.d', strtotime($p['start_date'])) : '-';
                $e = $p['end_date']   ? date('y.m.d', strtotime($p['end_date']))   : '-';
                echo "$s ~ $e";
                ?>
            </td>
            <td class="text-center">
                <?php if ($p['is_active']): ?>
                <span class="badge bg-success">활성</span>
                <?php else: ?>
                <span class="badge bg-secondary">비활성</span>
                <?php endif; ?>
            </td>
            <td>
                <div class="d-flex gap-1">
                    <a href="/admin/promotions/<?= $p['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">수정</a>
                    <form method="post" action="/admin/promotions/<?= $p['id'] ?>/delete"
                          onsubmit="return confirm('기획전을 삭제하시겠습니까?')">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">삭제</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
