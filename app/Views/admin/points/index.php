<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '포인트 관리' ?>

<?= $this->section('content') ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0">포인트 관리</h4>
</div>

<div class="card overflow-hidden">
    <div class="card-header bg-white">
        <form method="get" class="d-flex gap-2">
            <input type="text" name="keyword" class="form-control form-control-sm"
                   placeholder="이메일, 닉네임" value="<?= esc($keyword) ?>" style="max-width:240px">
            <button type="submit" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-search"></i>
            </button>
            <?php if ($keyword): ?>
            <a href="/admin/points" class="btn btn-sm btn-outline-secondary">초기화</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>이메일</th>
                    <th>닉네임</th>
                    <th class="text-end">포인트 잔액</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr><td colspan="5" class="text-center py-4 text-muted">회원이 없습니다.</td></tr>
                <?php else: foreach ($users as $u): ?>
                <tr>
                    <td class="small text-muted"><?= (int) $u['id'] ?></td>
                    <td class="small"><?= esc($u['email']) ?></td>
                    <td class="small"><?= esc($u['nickname']) ?></td>
                    <td class="text-end fw-semibold"><?= number_format($u['point_balance']) ?>P</td>
                    <td class="text-end">
                        <a href="/admin/points/<?= (int) $u['id'] ?>/history"
                           class="btn btn-xs btn-outline-secondary"
                           style="font-size:.72rem;padding:.15rem .45rem">이력 / 조정</a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total > $perPage): ?>
    <div class="card-footer bg-white">
        <?php $totalPages = (int) ceil($total / $perPage); ?>
        <nav><ul class="pagination pagination-sm mb-0 justify-content-center">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $p ?>&keyword=<?= esc($keyword) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
