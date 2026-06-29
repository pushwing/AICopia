<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">접속 통계</h4>
</div>

<!-- 기간 선택 -->
<form class="row g-2 align-items-end mb-4" method="get">
    <div class="col-auto">
        <label class="form-label small text-muted mb-1">시작일</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= esc($from) ?>">
    </div>
    <div class="col-auto">
        <label class="form-label small text-muted mb-1">종료일</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= esc($to) ?>">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-outline-secondary">조회</button>
    </div>
    <div class="col-auto ms-auto">
        <div class="btn-group btn-group-sm">
            <a href="?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary">오늘</a>
            <a href="?from=<?= date('Y-m-d', strtotime('-6 days')) ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary">7일</a>
            <a href="?from=<?= date('Y-m-d', strtotime('-29 days')) ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary">30일</a>
            <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary">이번달</a>
        </div>
    </div>
</form>

<!-- 요약 카드 -->
<div class="row g-3 mb-4">
    <?php
    $summaryCards = [
        ['label' => '오늘 PV',   'value' => $todayPv, 'icon' => 'bi-eye',        'color' => 'primary'],
        ['label' => '오늘 UV',   'value' => $todayUv, 'icon' => 'bi-person',      'color' => 'success'],
        ['label' => '기간 PV',   'value' => $totalPv, 'icon' => 'bi-bar-chart',   'color' => 'info'],
        ['label' => '기간 UV',   'value' => $totalUv, 'icon' => 'bi-people',      'color' => 'warning'],
    ];
    foreach ($summaryCards as $c):
    ?>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="bg-<?= $c['color'] ?> bg-opacity-10 rounded p-3">
                    <i class="bi <?= $c['icon'] ?> fs-4 text-<?= $c['color'] ?>"></i>
                </div>
                <div>
                    <div class="text-muted small"><?= $c['label'] ?></div>
                    <div class="fs-4 fw-bold text-dark"><?= number_format($c['value']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- 일별 PV/UV 차트 -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><strong>일별 방문 추이</strong></div>
    <div class="card-body">
        <canvas id="dailyChart" height="90"></canvas>
    </div>
</div>

<!-- 시간대별 분포 + 유입 경로 -->
<div class="row g-4 mb-4">
    <!-- 시간대별 접속 분포 -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong>시간대별 접속 분포</strong></div>
            <div class="card-body">
                <canvas id="hourlyChart" height="130"></canvas>
            </div>
        </div>
    </div>
    <!-- 유입 경로 TOP 10 -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong>유입 경로 TOP 10</strong></div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:36px">#</th>
                            <th>도메인</th>
                            <th style="width:80px" class="text-end">방문</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($refererData)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">데이터가 없습니다.</td></tr>
                    <?php else: ?>
                    <?php foreach ($refererData as $i => $row): ?>
                    <tr>
                        <td class="text-muted small"><?= $i + 1 ?></td>
                        <td class="small text-truncate" style="max-width:180px"><?= esc($row['domain'] ?: '(직접)') ?></td>
                        <td class="text-end small"><?= number_format($row['hits']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 인기 상품 페이지 TOP 10 -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><strong>인기 상품 페이지 TOP 10</strong></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:40px">#</th>
                    <th>상품 페이지</th>
                    <th style="width:100px" class="text-end">PV</th>
                    <th style="width:100px" class="text-end">UV</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($topProductPages)): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">데이터가 없습니다.</td></tr>
            <?php else: ?>
            <?php foreach ($topProductPages as $i => $row): ?>
            <tr>
                <td class="text-muted small"><?= $i + 1 ?></td>
                <td class="small"><a href="<?= esc($row['page']) ?>" target="_blank" class="text-decoration-none"><?= esc($row['page']) ?></a></td>
                <td class="text-end small"><?= number_format($row['hits']) ?></td>
                <td class="text-end small text-muted"><?= number_format($row['unique_visitors']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 페이지별 순위 -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white"><strong>페이지별 조회수 (상위 20)</strong></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:40px">#</th>
                    <th>페이지</th>
                    <th style="width:100px" class="text-end">PV</th>
                    <th style="width:100px" class="text-end">UV</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($topPages)): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">데이터가 없습니다.</td></tr>
            <?php else: ?>
            <?php foreach ($topPages as $i => $row): ?>
            <tr>
                <td class="text-muted"><?= $i + 1 ?></td>
                <td class="small"><a href="<?= esc($row['page']) ?>" target="_blank" class="text-decoration-none"><?= esc($row['page']) ?></a></td>
                <td class="text-end"><?= number_format($row['hits']) ?></td>
                <td class="text-end text-muted"><?= number_format($row['unique_visitors']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"
        integrity="sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt"
        crossorigin="anonymous"></script>
<script>
(function () {
    const labels = <?= json_encode($dailyLabels) ?>;
    const pvData = <?= json_encode($dailyPv) ?>;
    const uvData = <?= json_encode($dailyUv) ?>;

    // 시간대별 분포 차트
    const hourlyData = <?= json_encode($hourlyData) ?>;
    new Chart(document.getElementById('hourlyChart'), {
        type: 'bar',
        data: {
            labels: Array.from({length: 24}, (_, i) => i + '시'),
            datasets: [{
                label: 'PV',
                data: hourlyData,
                backgroundColor: 'rgba(13,110,253,.6)',
                borderRadius: 3,
            }],
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        },
    });

    new Chart(document.getElementById('dailyChart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'PV (페이지뷰)',
                    data: pvData,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13,110,253,.08)',
                    fill: true,
                    tension: .3,
                    pointRadius: labels.length > 14 ? 2 : 4,
                },
                {
                    label: 'UV (순방문자)',
                    data: uvData,
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25,135,84,.08)',
                    fill: true,
                    tension: .3,
                    pointRadius: labels.length > 14 ? 2 : 4,
                },
            ],
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        },
    });
})();
</script>
<?= $this->endSection() ?>
