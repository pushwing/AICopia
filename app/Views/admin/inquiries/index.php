<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '문의 수신함' ?>
<?= $this->section('content') ?>

<?php
// AI 분류 표시용 라벨·색상
$catLabels = ['shipping' => '배송', 'refund' => '환불', 'product' => '상품', 'payment' => '결제', 'etc' => '기타'];
$catColors = ['shipping' => 'info', 'refund' => 'warning', 'product' => 'primary', 'payment' => 'success', 'etc' => 'secondary'];
?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $filter === '' ? 'active' : '' ?>" href="/admin/inquiries">
            전체 <span class="badge bg-secondary ms-1"><?= number_format($totalAll) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $filter === 'unread' ? 'active' : '' ?>" href="/admin/inquiries?filter=unread">
            미읽음
            <?php if ($unreadCount > 0): ?>
            <span class="badge bg-danger ms-1"><?= $unreadCount ?></span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<!-- AI 분류 필터 -->
<div class="d-flex flex-wrap gap-1 mb-3">
    <?php $catQs = $filter ? '&filter=' . $filter : ''; ?>
    <a href="/admin/inquiries<?= $filter ? '?filter=' . $filter : '' ?>"
       class="btn btn-sm <?= $category === '' ? 'btn-dark' : 'btn-outline-secondary' ?>">전체 분류</a>
    <?php foreach ($catLabels as $key => $label): ?>
    <a href="/admin/inquiries?category=<?= $key . $catQs ?>"
       class="btn btn-sm <?= $category === $key ? 'btn-' . $catColors[$key] : 'btn-outline-' . $catColors[$key] ?>"><?= esc($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm">
    <table class="table table-hover mb-0 small">
        <thead class="table-light">
            <tr><th></th><th>분류</th><th>이름</th><th>이메일</th><th>제목</th><th>날짜</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($inquiries as $inq): ?>
            <tr class="<?= ! $inq['is_read'] ? 'fw-semibold' : '' ?>">
                <td><?php if (! $inq['is_read']): ?><span class="badge bg-danger">NEW</span><?php endif; ?></td>
                <td style="white-space:nowrap">
                    <?php if (! empty($inq['category'])): ?>
                        <span class="badge bg-<?= $catColors[$inq['category']] ?? 'secondary' ?>"><?= esc($catLabels[$inq['category']] ?? $inq['category']) ?></span>
                    <?php else: ?>
                        <span class="badge bg-light text-muted border">미분류</span>
                    <?php endif; ?>
                    <?php if (($inq['priority'] ?? '') === 'high'): ?>
                        <span class="badge bg-danger" title="긴급"><i class="bi bi-exclamation-lg"></i></span>
                    <?php endif; ?>
                </td>
                <td><?= esc($inq['name']) ?></td>
                <td><?= esc($inq['email']) ?></td>
                <td>
                    <a href="/admin/inquiries/<?= $inq['id'] ?>" class="text-decoration-none text-dark">
                        <?= esc($inq['subject'] ?: mb_substr($inq['message'], 0, 30)) ?>
                    </a>
                </td>
                <td><?= date('Y년 n월 j일', strtotime($inq['created_at'])) ?></td>
                <td>
                    <form method="post" action="/admin/inquiries/<?= $inq['id'] ?>/delete" class="d-inline" onsubmit="return confirm('삭제?')">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-danger">삭제</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($inquiries)): ?>
                <tr><td colspan="7" class="text-center py-5 text-muted">문의가 없습니다</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3 d-flex justify-content-center">
    <ul class="pagination pagination-sm">
        <?php
        $qs = ($filter ? '&filter=' . $filter : '') . ($category ? '&category=' . $category : '');
        for ($p = 1; $p <= $totalPages; $p++):
        ?>
        <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
            <a class="page-link" href="?page=<?= $p ?><?= $qs ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?= $this->endSection() ?>
