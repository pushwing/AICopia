<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="container py-4" style="max-width:720px">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="/mypage/orders" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-chevron-left"></i>
        </a>
        <h5 class="fw-bold mb-0">보유 쿠폰</h5>
    </div>

    <!-- 탭 -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'available' ? 'active' : '' ?>"
               href="/mypage/coupons?tab=available">사용 가능</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'used' ? 'active' : '' ?>"
               href="/mypage/coupons?tab=used">사용 완료</a>
        </li>
    </ul>

    <?php if (empty($coupons)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-ticket-perforated display-6 d-block mb-2"></i>
        <?= $tab === 'available' ? '사용 가능한 쿠폰이 없습니다.' : '사용 완료 쿠폰이 없습니다.' ?>
    </div>
    <?php else: ?>
    <div class="d-flex flex-column gap-3">
        <?php foreach ($coupons as $uc):
            $discLabel = $uc['type'] === 'fixed'
                ? number_format($uc['discount_value']) . '원 할인'
                : $uc['discount_value'] . '% 할인';
            if ($uc['type'] === 'percent' && (int) $uc['max_discount_amount'] > 0)
                $discLabel .= ' (최대 ' . number_format($uc['max_discount_amount']) . '원)';
            $expired = $uc['status'] === 'expired' || ($uc['expires_at'] && strtotime($uc['expires_at']) < time());
        ?>
        <div class="card <?= ($uc['status'] === 'used' || $expired) ? 'opacity-50' : 'border-primary' ?>">
            <div class="card-body d-flex justify-content-between align-items-start">
                <div>
                    <div class="fw-bold"><?= esc($uc['name']) ?></div>
                    <div class="fs-5 fw-bold text-primary mt-1"><?= esc($discLabel) ?></div>
                    <?php if ((int) $uc['min_order_amount'] > 0): ?>
                    <div class="small text-muted mt-1">
                        <?= number_format($uc['min_order_amount']) ?>원 이상 주문 시 사용
                    </div>
                    <?php endif; ?>
                    <?php if ($uc['expires_at']): ?>
                    <div class="small text-muted">
                        ~<?= date('Y년 n월 j일', strtotime($uc['expires_at'])) ?> 까지
                    </div>
                    <?php endif; ?>
                </div>
                <div class="text-end ms-3 flex-shrink-0">
                    <?php if ($uc['status'] === 'used'): ?>
                    <span class="badge bg-secondary">사용 완료</span>
                    <div class="small text-muted mt-1"><?= $uc['used_at'] ? date('Y년 n월 j일', strtotime($uc['used_at'])) : '' ?></div>
                    <?php elseif ($expired): ?>
                    <span class="badge bg-secondary">기간 만료</span>
                    <?php else: ?>
                    <a href="/order" class="btn btn-primary btn-sm">사용하기</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- 페이지네이션 -->
    <?php if ($total > $perPage): ?>
    <nav class="mt-4">
        <ul class="pagination justify-content-center">
            <?php $totalPages = (int) ceil($total / $perPage);
                  for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                <a class="page-link" href="?tab=<?= esc($tab) ?>&page=<?= $p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
    <?php endif; ?>

</div>

<?= $this->endSection() ?>
