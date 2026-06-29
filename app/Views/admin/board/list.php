<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '게시판 관리' ?>
<?= $this->section('content') ?>
<div class="d-flex justify-content-between mb-3">
    <h5>게시판 관리</h5>
    <a href="/admin/boards/create" class="btn btn-primary btn-sm">+ 게시판 추가</a>
</div>
<table class="table table-hover board-table">
    <thead>
        <tr>
            <th>순서</th><th>게시판명</th><th>슬러그</th>
            <th>읽기권한</th><th>쓰기권한</th><th>파일</th><th>이미지</th><th>상태</th><th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($boards as $b): ?>
        <tr>
            <td><?= $b['sort_order'] ?></td>
            <td><?= esc($b['name']) ?></td>
            <td><code>/board/<?= esc($b['slug']) ?></code></td>
            <td><?= $b['read_permission'] ?></td>
            <td><?= $b['write_permission'] ?></td>
            <td><?= $b['allow_file'] ? '✓' : '-' ?></td>
            <td><?= $b['allow_image'] ? '✓' : '-' ?></td>
            <td><?= $b['is_active'] ? '<span class="badge bg-success">활성</span>' : '<span class="badge bg-secondary">비활성</span>' ?></td>
            <td>
                <a href="/admin/boards/<?= $b['id'] ?>/edit" class="btn btn-xs btn-outline-secondary btn-sm">수정</a>
                <a href="/admin/boards/<?= $b['id'] ?>/posts" class="btn btn-xs btn-outline-primary btn-sm">게시글</a>
                <a href="/board/<?= esc($b['slug']) ?>" target="_blank" class="btn btn-xs btn-outline-dark btn-sm">미리보기</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?= $this->endSection() ?>
