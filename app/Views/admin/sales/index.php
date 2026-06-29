<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0">매출 관리</h4>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-info btn-sm" id="btnAiReport">
            <i class="bi bi-stars me-1"></i>AI 매출 분석
        </button>
        <a href="/admin/sales/export?from=<?= esc($from) ?>&to=<?= esc($to) ?>&keyword=<?= esc($keyword) ?>"
           class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>엑셀 다운로드
        </a>
    </div>
</div>

<!-- AI 매출 분석 리포트 -->
<div class="card border-info mb-4 d-none" id="aiReportCard">
    <div class="card-header bg-white fw-semibold text-info d-flex justify-content-between align-items-center">
        <span><i class="bi bi-stars me-1"></i>AI 매출 분석</span>
        <button type="button" class="btn-close" id="btnCloseAiReport"></button>
    </div>
    <div class="card-body">
        <div id="aiReportBody" style="white-space:pre-wrap" class="small"></div>
    </div>
</div>

<script>
(function () {
    var btn   = document.getElementById('btnAiReport');
    var card  = document.getElementById('aiReportCard');
    var body  = document.getElementById('aiReportBody');
    var csrf  = { name: '<?= csrf_token() ?>', hash: '<?= csrf_hash() ?>' };
    var params = { period: '<?= esc($period, 'js') ?>', from: '<?= esc($from, 'js') ?>', to: '<?= esc($to, 'js') ?>' };

    document.getElementById('btnCloseAiReport').addEventListener('click', function () { card.classList.add('d-none'); });

    btn.addEventListener('click', function () {
        btn.disabled = true;
        var original = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>분석 중…';
        card.classList.remove('d-none');
        body.textContent = '매출 데이터를 분석하고 있습니다…';

        var fd = new FormData();
        fd.append(csrf.name, csrf.hash);
        fd.append('period', params.period);
        fd.append('from', params.from);
        fd.append('to', params.to);

        fetch('/admin/sales/ai-report', { method: 'POST', body: fd })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                if (! res.ok || res.d.error) {
                    body.textContent = res.d.error || 'AI 분석에 실패했습니다.';
                    if (res.d.setup_url && confirm((res.d.error || '오류') + '\n설정으로 이동할까요?')) location.href = res.d.setup_url;
                    return;
                }
                body.textContent = res.d.report;
            })
            .catch(function () { body.textContent = '요청 중 오류가 발생했습니다.'; })
            .finally(function () { btn.disabled = false; btn.innerHTML = original; });
    });
}());
</script>

<!-- ─── 검색 / 필터 ──────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" action="/admin/sales" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small mb-1">기간 구분</label>
                <div class="btn-group" role="group">
                    <?php foreach (['daily' => '일별', 'weekly' => '주별', 'monthly' => '월별'] as $val => $label): ?>
                    <a href="?period=<?= $val ?>&from=<?= esc($from) ?>&to=<?= esc($to) ?>&keyword=<?= esc($keyword) ?>"
                       class="btn btn-sm <?= $period === $val ? 'btn-dark' : 'btn-outline-secondary' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">시작일</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= esc($from) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">종료일</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= esc($to) ?>">
            </div>
            <input type="hidden" name="period" value="<?= esc($period) ?>">
            <div class="col">
                <label class="form-label small mb-1">검색</label>
                <input type="text" name="keyword" class="form-control form-control-sm"
                       placeholder="주문번호, 수신자명, 회원명, 이메일"
                       value="<?= esc($keyword) ?>">
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-search me-1"></i>조회
                </button>
                <?php if ($keyword !== ''): ?>
                <a href="?period=<?= esc($period) ?>&from=<?= esc($from) ?>&to=<?= esc($to) ?>"
                   class="btn btn-sm btn-outline-secondary">초기화</a>
                <?php endif; ?>
            </div>
            <!-- 빠른 기간 버튼 -->
            <div class="col-12 d-flex gap-1 mt-1">
                <?php
                $quickPeriods = [
                    '이번 달'   => [date('Y-m-01'),                      date('Y-m-d')],
                    '지난 달'   => [date('Y-m-01', strtotime('-1 month')), date('Y-m-t', strtotime('-1 month'))],
                    '최근 7일'  => [date('Y-m-d', strtotime('-6 days')),  date('Y-m-d')],
                    '최근 30일' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
                    '올해'      => [date('Y-01-01'),                      date('Y-m-d')],
                ];
                foreach ($quickPeriods as $label => [$qFrom, $qTo]):
                    $active = $from === $qFrom && $to === $qTo;
                ?>
                <a href="?period=<?= esc($period) ?>&from=<?= $qFrom ?>&to=<?= $qTo ?>&keyword=<?= esc($keyword) ?>"
                   class="btn btn-xs btn-outline-secondary <?= $active ? 'active' : '' ?>"
                   style="font-size:.75rem;padding:.2rem .55rem">
                    <?= $label ?>
                </a>
                <?php endforeach; ?>
            </div>
        </form>
    </div>
</div>

<!-- ─── 요약 카드 ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">실 매출</div>
                <div class="fs-5 fw-bold text-primary">
                    <?= number_format((int) ($summary['total_revenue'] ?? 0)) ?>원
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">GMV (할인 전)</div>
                <div class="fs-5 fw-bold">
                    <?= number_format((int) ($summary['total_gmv'] ?? 0)) ?>원
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">총 할인액</div>
                <div class="fs-5 fw-bold text-danger">
                    <?= number_format((int) ($summary['total_discount'] ?? 0)) ?>원
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">총 매입원가</div>
                <div class="fs-5 fw-bold text-warning">
                    <?= number_format((int) ($summary['total_cost'] ?? 0)) ?>원
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <?php $profit = (int) ($summary['total_profit'] ?? 0); ?>
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">1차 마진</div>
                <div class="fs-5 fw-bold <?= $profit >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= number_format($profit) ?>원
                </div>
                <div class="text-muted" style="font-size:.72rem">실매출 − 매입원가 − 배송비</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">주문 수 / 평균</div>
                <div class="fs-5 fw-bold"><?= number_format((int) ($summary['total_orders'] ?? 0)) ?>건</div>
                <div class="small text-muted"><?= number_format((int) ($summary['avg_order'] ?? 0)) ?>원/건</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">

    <!-- ─── 기간별 매출 ────────────────────────────────────────────────────── -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">
                <?= ['daily' => '일별', 'weekly' => '주별', 'monthly' => '월별'][$period] ?> 매출
            </div>
            <?php if (empty($periodRows)): ?>
            <div class="card-body text-center text-muted py-5">데이터가 없습니다.</div>
            <?php else: ?>
            <div style="max-height:420px;overflow-y:auto">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th><?= ['daily' => '날짜', 'weekly' => '주 시작일', 'monthly' => '월'][$period] ?></th>
                            <th class="text-end">주문 수</th>
                            <th class="text-end">GMV</th>
                            <th class="text-end">할인</th>
                            <th class="text-end">실 매출</th>
                            <th class="text-end">매입원가</th>
                            <th class="text-end">1차 마진</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($periodRows as $row):
                            $rowProfit = (int) ($row['operating_profit'] ?? 0);
                        ?>
                        <tr>
                            <td class="small"><?= esc($row['period_key']) ?></td>
                            <td class="text-end small"><?= number_format($row['order_count']) ?>건</td>
                            <td class="text-end small text-muted"><?= number_format($row['gmv']) ?>원</td>
                            <td class="text-end small text-danger"><?= $row['total_discount'] > 0 ? '-' . number_format($row['total_discount']) : '—' ?>원</td>
                            <td class="text-end fw-semibold"><?= number_format($row['revenue']) ?>원</td>
                            <td class="text-end small text-warning"><?= number_format((int) ($row['total_cost'] ?? 0)) ?>원</td>
                            <td class="text-end small fw-semibold <?= $rowProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= number_format($rowProfit) ?>원
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ─── 결제수단별 매출 ───────────────────────────────────────────────── -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">결제수단별 매출</div>
            <?php if (empty($methodRows)): ?>
            <div class="card-body text-center text-muted py-5">데이터가 없습니다.</div>
            <?php else:
                $totalRevenue = array_sum(array_column($methodRows, 'revenue'));
            ?>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>결제수단</th>
                            <th class="text-end">건수</th>
                            <th class="text-end">매출</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($methodRows as $row):
                            $pgLabel  = $pgLabels[$row['pg_provider']] ?? $row['pg_provider'];
                            $method   = $row['method'] ?? '';
                            $label    = $pgLabel . ($method && $method !== $pgLabel ? ' · ' . $method : '');
                            $pct      = $totalRevenue > 0 ? round($row['revenue'] / $totalRevenue * 100) : 0;
                        ?>
                        <tr>
                            <td class="small">
                                <?= esc($label) ?>
                                <div class="progress mt-1" style="height:3px">
                                    <div class="progress-bar" style="width:<?= $pct ?>%"></div>
                                </div>
                            </td>
                            <td class="text-end small"><?= number_format($row['order_count']) ?>건</td>
                            <td class="text-end small fw-semibold"><?= number_format($row['revenue']) ?>원</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ─── 주문 목록 ─────────────────────────────────────────────────────────── -->
<div class="card overflow-hidden">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <span class="fw-semibold">결제 완료 주문 목록</span>
        <span class="text-muted small">최대 50건 표시</span>
    </div>
    <?php if (empty($orders)): ?>
    <div class="card-body text-center text-muted py-5">데이터가 없습니다.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>주문번호</th>
                    <th>주문일시</th>
                    <th>수신자</th>
                    <th>회원</th>
                    <th>결제수단</th>
                    <th class="text-end">GMV</th>
                    <th class="text-end">실 매출</th>
                    <th class="text-end">매입원가</th>
                    <th class="text-end">1차 마진</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order):
                    $pgLabel     = $pgLabels[$order['pg_provider']] ?? ($order['pg_provider'] ?? '—');
                    $orderProfit = (int) ($order['profit'] ?? 0);
                ?>
                <tr>
                    <td class="small font-monospace">
                        <a href="/admin/orders/<?= (int) $order['id'] ?>" class="text-decoration-none">
                            <?= esc($order['order_number']) ?>
                        </a>
                    </td>
                    <td class="small text-muted"><?= date('Y년 n월 j일 G시 i분', strtotime($order['created_at'])) ?></td>
                    <td class="small"><?= esc($order['receiver_name']) ?></td>
                    <td class="small"><?= esc($order['nickname'] ?? $order['email'] ?? '—') ?></td>
                    <td class="small"><?= esc($pgLabel) ?><?= $order['payment_method'] ? ' · ' . esc($order['payment_method']) : '' ?></td>
                    <td class="text-end small text-muted"><?= number_format($order['total_amount']) ?>원</td>
                    <td class="text-end fw-semibold small"><?= number_format($order['payable_amount']) ?>원</td>
                    <td class="text-end small text-warning"><?= number_format((int) ($order['cost_total'] ?? 0)) ?>원</td>
                    <td class="text-end small fw-semibold <?= $orderProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                        <?= number_format($orderProfit) ?>원
                    </td>
                    <td class="text-end">
                        <a href="/admin/orders/<?= (int) $order['id'] ?>" class="btn btn-xs btn-outline-secondary"
                           style="font-size:.72rem;padding:.15rem .45rem">상세</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
