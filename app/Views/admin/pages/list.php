<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '페이지 관리' ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between mb-3">
    <div></div>
    <a href="/admin/pages/create" class="btn btn-primary btn-sm">+ 페이지 추가</a>
</div>

<div class="card border-0 shadow-sm">
    <table class="table table-hover mb-0">
        <thead class="table-light">
            <tr><th>순서</th><th>제목</th><th>슬러그</th><th>레이아웃</th><th>상태</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($pages as $p): ?>
            <tr>
                <td><?= $p['sort_order'] ?></td>
                <td><?= esc($p['title']) ?></td>
                <td><code>/<?= esc($p['slug']) ?></code></td>
                <td><span class="badge bg-secondary"><?= esc($p['layout']) ?></span></td>
                <td><?= $p['status'] === 'published' ? '<span class="badge bg-success">공개</span>' : '<span class="badge bg-warning">초안</span>' ?></td>
                <td class="text-end">
                    <a href="/<?= esc($p['slug']) ?>" target="_blank" class="btn btn-sm btn-outline-dark">미리보기</a>
                    <a href="/admin/pages/<?= $p['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">수정</a>
                    <form method="post" action="/admin/pages/<?= $p['id'] ?>/delete" class="d-inline" onsubmit="return confirm('삭제?')">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-danger">삭제</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?= $this->endSection() ?>
