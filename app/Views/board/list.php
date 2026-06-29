<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="container py-4">
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0"><?= esc($board['name']) ?></h4>
        <?php if ($board['description']): ?>
            <small class="text-muted"><?= esc($board['description']) ?></small>
        <?php endif; ?>
    </div>
    <a href="/board/<?= esc($board['slug']) ?>/write" class="btn btn-primary btn-sm">
        <i class="bi bi-pencil-square"></i> 글쓰기
    </a>
</div>

<!-- 검색 -->
<form class="d-flex gap-2 mb-3" method="get">
    <select name="type" class="form-select form-select-sm" style="width:120px">
        <option value="title"   <?= $searchType === 'title'   ? 'selected' : '' ?>>제목</option>
        <option value="content" <?= $searchType === 'content' ? 'selected' : '' ?>>내용</option>
        <option value="all"     <?= $searchType === 'all'     ? 'selected' : '' ?>>제목+내용</option>
    </select>
    <input type="text" name="keyword" class="form-control form-control-sm" value="<?= esc($keyword ?? '') ?>" placeholder="검색어">
    <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-search"></i></button>
    <?php if ($keyword): ?>
        <a href="/board/<?= esc($board['slug']) ?>" class="btn btn-outline-danger btn-sm">초기화</a>
    <?php endif; ?>
</form>

<table class="table table-hover board-table">
    <thead>
        <tr>
            <th style="width:60px" class="text-center">번호</th>
            <th>제목</th>
            <th style="width:100px" class="text-center">작성자</th>
            <th style="width:90px"  class="text-center">날짜</th>
            <th style="width:60px"  class="text-center">조회</th>
        </tr>
    </thead>
    <tbody>
        <!-- 공지 -->
        <?php foreach ($notices as $post): ?>
        <tr class="table-warning">
            <td class="text-center"><span class="badge-notice">공지</span></td>
            <td>
                <a href="/board/<?= esc($board['slug']) ?>/<?= $post['id'] ?>" class="text-decoration-none text-dark fw-semibold">
                    <?= esc($post['title']) ?>
                    <?php if ($post['is_secret']): ?> <i class="bi bi-lock-fill text-muted small"></i><?php endif; ?>
                </a>
            </td>
            <td class="text-center text-muted small"><?= esc($post['author_name']) ?></td>
            <td class="text-center text-muted small"><?= date('Y년 n월 j일', strtotime($post['created_at'])) ?></td>
            <td class="text-center text-muted small"><?= number_format($post['views']) ?></td>
        </tr>
        <?php endforeach; ?>

        <!-- 일반글 -->
        <?php if (empty($posts)): ?>
        <tr><td colspan="5" class="text-center py-5 text-muted">게시글이 없습니다.</td></tr>
        <?php endif; ?>
        <?php foreach ($posts as $i => $post): ?>
        <tr>
            <td class="text-center text-muted small">
                <?= $total - (($currentPage - 1) * $board['posts_per_page']) - $i ?>
            </td>
            <td>
                <a href="/board/<?= esc($board['slug']) ?>/<?= $post['id'] ?>" class="text-decoration-none text-dark">
                    <?= esc($post['title']) ?>
                    <?php if ($post['is_secret']): ?> <i class="bi bi-lock-fill text-muted small"></i><?php endif; ?>
                </a>
            </td>
            <td class="text-center text-muted small"><?= esc($post['user_nickname'] ?? $post['author_name']) ?></td>
            <td class="text-center text-muted small"><?= date('Y년 n월 j일', strtotime($post['created_at'])) ?></td>
            <td class="text-center text-muted small"><?= number_format($post['views']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- 페이지네이션 -->
<?php if ($totalPages > 1): ?>
<nav class="d-flex justify-content-center mt-3">
    <ul class="pagination pagination-sm">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $p ?><?= $keyword ? '&keyword=' . urlencode($keyword) . '&type=' . $searchType : '' ?>"><?= $p ?></a>
            </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

</div>

<?= $this->endSection() ?>
