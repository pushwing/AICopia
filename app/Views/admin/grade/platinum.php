<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '플래티넘 승급 관리' ?>
<?php use App\Libraries\GradeService; ?>

<?= $this->section('content') ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0">
        <i class="bi bi-trophy-fill text-primary me-2"></i>플래티넘 승급 관리
    </h4>
    <span class="text-muted small">골드 회원만 표시됩니다. 승급은 관리자 수동 선정입니다.</span>
</div>

<div class="card overflow-hidden">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <form class="d-flex gap-2" method="get">
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="닉네임 또는 이메일 검색" value="<?= esc($keyword) ?>" style="width:220px">
            <button class="btn btn-sm btn-outline-secondary">검색</button>
            <?php if ($keyword): ?><a href="/admin/grade/platinum" class="btn btn-sm btn-outline-danger">초기화</a><?php endif; ?>
        </form>
        <span class="text-muted small">총 <?= number_format($total) ?>명</span>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>회원</th>
                    <th>이메일</th>
                    <th class="text-end">누적 구매횟수</th>
                    <th class="text-end">누적 구매금액</th>
                    <th class="text-center">가입 연수</th>
                    <th class="text-end">가입일</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                        골드 등급 회원이 없습니다.
                    </td>
                </tr>
                <?php else: foreach ($items as $m): ?>
                <tr>
                    <td>
                        <span class="badge <?= GradeService::BADGE_CLASSES['gold'] ?> me-1">
                            <i class="bi <?= GradeService::ICONS['gold'] ?>"></i> 골드
                        </span>
                        <a href="/admin/users/<?= (int) $m['id'] ?>/edit" class="text-decoration-none fw-semibold">
                            <?= esc($m['nickname']) ?>
                        </a>
                    </td>
                    <td class="small text-muted"><?= esc($m['email']) ?></td>
                    <td class="text-end small"><?= number_format($m['order_count']) ?>건</td>
                    <td class="text-end small fw-semibold"><?= number_format($m['total_amount']) ?>원</td>
                    <td class="text-center small"><?= (int) $m['years_since_signup'] ?>년</td>
                    <td class="text-end small text-muted"><?= date('Y.m.d', strtotime($m['created_at'])) ?></td>
                    <td class="text-end">
                        <form method="post" action="/admin/grade/platinum/<?= (int) $m['id'] ?>/promote"
                              onsubmit="return confirm('<?= esc($m['nickname']) ?> 님을 플래티넘으로 승급하겠습니까?')">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-primary">
                                <i class="bi bi-arrow-up-circle me-1"></i>플래티넘 승급
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white">
        <nav><ul class="pagination pagination-sm mb-0 justify-content-center">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $p ?>&q=<?= urlencode($keyword) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
