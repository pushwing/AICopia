<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '대시보드' ?>
<?= $this->section('content') ?>

<!-- 매출 통계 -->
<div class="row g-3 mb-4">
    <?php
    $salesCards = [
        ['label' => '오늘 매출',    'value' => $salesStats['today'], 'icon' => 'bi-calendar-day',   'color' => 'primary'],
        ['label' => '이번 주 매출', 'value' => $salesStats['week'],  'icon' => 'bi-calendar-week',  'color' => 'success'],
        ['label' => '이번 달 매출', 'value' => $salesStats['month'], 'icon' => 'bi-calendar-month', 'color' => 'info'],
    ];
    foreach ($salesCards as $c):
    ?>
    <div class="col-sm-4">
        <a href="/admin/orders" class="card border-0 shadow-sm text-decoration-none">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="bg-<?= $c['color'] ?> bg-opacity-10 rounded p-3">
                    <i class="bi <?= $c['icon'] ?> fs-4 text-<?= $c['color'] ?>"></i>
                </div>
                <div>
                    <div class="text-muted small"><?= $c['label'] ?></div>
                    <div class="fs-5 fw-bold text-dark"><?= number_format($c['value']) ?>원</div>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- 운영 현황 -->
<div class="row g-3 mb-4">
    <?php
    $opCards = [
        ['label' => '오늘 신규 주문',  'value' => $operationStats['today_orders'],   'icon' => 'bi-bag-plus',        'color' => 'primary', 'href' => '/admin/orders'],
        ['label' => '미처리 주문',     'value' => $operationStats['pending_orders'],  'icon' => 'bi-hourglass-split', 'color' => 'warning', 'href' => '/admin/orders?status=awaiting_payment'],
        ['label' => '재고 부족 상품',  'value' => $operationStats['low_stock'],       'icon' => 'bi-exclamation-triangle', 'color' => 'danger',  'href' => '/admin/products?stock=low'],
        ['label' => '오늘 신규 회원',  'value' => $operationStats['today_users'],     'icon' => 'bi-person-plus',     'color' => 'success', 'href' => '/admin/users'],
        ['label' => '미확인 문의',     'value' => $operationStats['unread_inquiries'],'icon' => 'bi-bell',            'color' => 'info',    'href' => '/admin/inquiries?filter=unread'],
    ];
    foreach ($opCards as $c):
    ?>
    <div class="col-6 col-sm-4 col-xl">
        <a href="<?= $c['href'] ?>" class="card border-0 shadow-sm text-decoration-none h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="bg-<?= $c['color'] ?> bg-opacity-10 rounded p-3 flex-shrink-0">
                    <i class="bi <?= $c['icon'] ?> fs-5 text-<?= $c['color'] ?>"></i>
                </div>
                <div>
                    <div class="text-muted small"><?= $c['label'] ?></div>
                    <div class="fs-5 fw-bold text-dark"><?= number_format($c['value']) ?></div>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- 통계 카드 -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['label' => '총 게시글',  'value' => $stats['total_posts'],     'icon' => 'bi-card-text',  'color' => 'primary', 'href' => '/admin/posts'],
        ['label' => '총 회원',    'value' => $stats['total_users'],     'icon' => 'bi-people',     'color' => 'success', 'href' => '/admin/users'],
        ['label' => '전체 문의',  'value' => $stats['total_inquiries'], 'icon' => 'bi-envelope',   'color' => 'info',    'href' => '/admin/inquiries'],
        ['label' => '미읽음 문의','value' => $stats['unread_inquiries'],'icon' => 'bi-bell',       'color' => 'warning', 'href' => '/admin/inquiries?filter=unread'],
    ];
    foreach ($cards as $c):
    ?>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= $c['href'] ?>" class="card border-0 shadow-sm text-decoration-none">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="bg-<?= $c['color'] ?> bg-opacity-10 rounded p-3">
                    <i class="bi <?= $c['icon'] ?> fs-4 text-<?= $c['color'] ?>"></i>
                </div>
                <div>
                    <div class="text-muted small"><?= $c['label'] ?></div>
                    <div class="fs-4 fw-bold text-dark"><?= number_format($c['value']) ?></div>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- 오늘 접속 현황 -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <a href="/admin/stats" class="card border-0 shadow-sm text-decoration-none">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="bg-primary bg-opacity-10 rounded p-3">
                    <i class="bi bi-eye fs-4 text-primary"></i>
                </div>
                <div>
                    <div class="text-muted small">오늘 PV</div>
                    <div class="fs-4 fw-bold text-dark"><?= number_format($accessStats['today_pv'] ?? 0) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-sm-4">
        <a href="/admin/stats" class="card border-0 shadow-sm text-decoration-none">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="bg-success bg-opacity-10 rounded p-3">
                    <i class="bi bi-person fs-4 text-success"></i>
                </div>
                <div>
                    <div class="text-muted small">오늘 UV</div>
                    <div class="fs-4 fw-bold text-dark"><?= number_format($accessStats['today_uv'] ?? 0) ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-sm-4">
        <a href="/admin/stats?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="card border-0 shadow-sm text-decoration-none">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="bg-info bg-opacity-10 rounded p-3">
                    <i class="bi bi-bar-chart fs-4 text-info"></i>
                </div>
                <div>
                    <div class="text-muted small">이번달 PV</div>
                    <div class="fs-4 fw-bold text-dark"><?= number_format($accessStats['month_pv'] ?? 0) ?></div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- 차트 섹션 -->
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>최근 30일 일별 매출</strong>
                <a href="/admin/sales" class="small text-decoration-none">매출 상세</a>
            </div>
            <div class="card-body" style="height:240px">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <strong>판매량 TOP 5 <span class="text-muted small fw-normal">최근 30일</span></strong>
            </div>
            <div class="card-body" style="height:240px">
                <canvas id="topChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- 최근 주문 + 재고 부족 -->
<div class="row g-3 mb-3">
    <!-- 최근 주문 -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between">
                <strong>최근 주문</strong>
                <a href="/admin/orders" class="small text-decoration-none">전체보기</a>
            </div>
            <div class="list-group list-group-flush">
                <?php
                $statusLabels = [
                    'awaiting_payment' => ['label' => '입금대기', 'class' => 'warning'],
                    'paid'             => ['label' => '결제완료', 'class' => 'success'],
                    'preparing'        => ['label' => '준비중',   'class' => 'info'],
                    'shipped'          => ['label' => '배송중',   'class' => 'primary'],
                    'delivered'        => ['label' => '배송완료', 'class' => 'secondary'],
                    'cancelled'        => ['label' => '취소',     'class' => 'danger'],
                    'refund_requested' => ['label' => '환불요청', 'class' => 'danger'],
                    'refunded'         => ['label' => '환불완료', 'class' => 'secondary'],
                ];
                foreach ($recentOrders as $order):
                    $sl = $statusLabels[$order['status']] ?? ['label' => $order['status'], 'class' => 'secondary'];
                ?>
                <a href="/admin/orders/<?= $order['id'] ?>"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <div class="small">
                        <span class="badge bg-<?= $sl['class'] ?> me-1"><?= $sl['label'] ?></span>
                        <span class="text-dark"><?= esc($order['order_number']) ?></span>
                        <span class="text-muted ms-1"><?= esc($order['user_nickname']) ?></span>
                    </div>
                    <div class="text-end flex-shrink-0 ms-2">
                        <div class="small fw-semibold"><?= number_format($order['total_amount']) ?>원</div>
                        <div class="text-muted" style="font-size:.75rem"><?= date('Y년 n월 j일', strtotime($order['created_at'])) ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
                <?php if (empty($recentOrders)): ?>
                    <div class="list-group-item text-muted small text-center py-3">주문이 없습니다</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 재고 부족 (5개 이하) -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between">
                <strong>재고 부족 <span class="text-danger small fw-normal">5개 이하</span></strong>
                <a href="/admin/inventory" class="small text-decoration-none">재고관리</a>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($lowStockProducts as $prod): ?>
                <a href="/admin/products/<?= $prod['id'] ?>/edit"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span class="small text-truncate" style="max-width:240px"><?= esc($prod['name']) ?></span>
                    <span class="badge <?= $prod['stock'] == 0 ? 'bg-danger' : 'bg-warning text-dark' ?> flex-shrink-0">
                        <?= $prod['stock'] == 0 ? '품절' : $prod['stock'].'개' ?>
                    </span>
                </a>
                <?php endforeach; ?>
                <?php if (empty($lowStockProducts)): ?>
                    <div class="list-group-item text-muted small text-center py-3">재고 부족 상품이 없습니다</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- 최근 문의 -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between">
                <strong>최근 문의</strong>
                <a href="/admin/inquiries" class="small text-decoration-none">전체보기</a>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($recentInquiries as $inq): ?>
                <a href="/admin/inquiries/<?= $inq['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <div>
                        <?php if (! $inq['is_read']): ?><span class="badge bg-danger me-1">NEW</span><?php endif; ?>
                        <span class="small"><?= esc($inq['name']) ?></span>
                        <span class="text-muted small ms-1"><?= esc($inq['subject'] ?: mb_substr($inq['message'], 0, 20)) ?></span>
                    </div>
                    <span class="text-muted small"><?= date('Y년 n월 j일', strtotime($inq['created_at'])) ?></span>
                </a>
                <?php endforeach; ?>
                <?php if (empty($recentInquiries)): ?>
                    <div class="list-group-item text-muted small text-center py-3">문의가 없습니다</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 최근 게시글 -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between">
                <strong>최근 게시글</strong>
                <a href="/admin/posts" class="small text-decoration-none">전체보기</a>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($recentPosts as $post): ?>
                <a href="/board/<?= esc($post['board_slug']) ?>/<?= $post['id'] ?>"
                   target="_blank"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <div class="text-truncate" style="max-width:240px">
                        <span class="badge bg-light text-dark border me-1 small"><?= esc($post['board_name']) ?></span>
                        <span class="small text-dark"><?= esc($post['title']) ?></span>
                    </div>
                    <span class="text-muted small flex-shrink-0 ms-2"><?= date('Y년 n월 j일', strtotime($post['created_at'])) ?></span>
                </a>
                <?php endforeach; ?>
                <?php if (empty($recentPosts)): ?>
                    <div class="list-group-item text-muted small text-center py-3">게시글이 없습니다</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(async () => {
    const res  = await fetch('/admin/chart-data');
    const json = await res.json();

    // 매출 선 차트
    new Chart(document.getElementById('salesChart'), {
        type: 'line',
        data: {
            labels: json.sales.labels,
            datasets: [{
                label: '매출(원)',
                data: json.sales.data,
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13,110,253,.08)',
                fill: true,
                tension: 0.3,
                pointRadius: 2,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: v => v >= 10000 ? (v/10000).toLocaleString() + '만' : v.toLocaleString(),
                        font: { size: 10 },
                    },
                    grid: { color: 'rgba(0,0,0,.05)' },
                },
                x: { ticks: { font: { size: 10 } }, grid: { display: false } },
            },
        },
    });

    // TOP 5 가로 바 차트
    new Chart(document.getElementById('topChart'), {
        type: 'bar',
        data: {
            labels: json.top.labels.map(l => l.length > 12 ? l.slice(0, 12) + '…' : l),
            datasets: [{
                label: '판매량',
                data: json.top.data,
                backgroundColor: ['#0d6efd','#198754','#0dcaf0','#ffc107','#dc3545'],
                borderRadius: 4,
            }],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, ticks: { font: { size: 10 } }, grid: { color: 'rgba(0,0,0,.05)' } },
                y: { ticks: { font: { size: 10 } }, grid: { display: false } },
            },
        },
    });
})();
</script>
<?= $this->endSection() ?>
