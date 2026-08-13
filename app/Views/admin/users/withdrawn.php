<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '탈퇴회원 관리' ?>

<?= $this->section('content') ?>

<?php $reasonLabels = \App\Libraries\WithdrawalService::REASON_CODES; ?>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <form method="get" action="/admin/users/withdrawn" class="d-flex gap-2">
        <input type="text" name="q" value="<?= esc($keyword) ?>" class="form-control form-control-sm"
               style="max-width:240px" placeholder="이메일 / 닉네임 검색">
        <button class="btn btn-outline-secondary btn-sm">검색</button>
    </form>
    <a href="/admin/users" class="btn btn-outline-secondary btn-sm ms-auto">일반 회원 목록</a>
</div>

<div class="alert alert-info small">
    개인정보는 보관 기간(설정 → <code>withdrawal_retention_days</code>)이 지나면 자동 파기됩니다.
    파기된 항목은 <span class="text-muted">—</span> 로 표시됩니다.
</div>

<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
    <thead class="table-light">
        <tr>
            <th>회원ID</th><th>이메일</th><th>닉네임</th><th>등급</th>
            <th class="text-end">주문</th><th class="text-end">소멸 포인트</th><th class="text-end">소멸 쿠폰</th>
            <th>사유</th><th>경로</th><th>탈퇴일</th><th>파기</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($rows === []): ?>
        <tr><td colspan="11" class="text-center text-muted py-4">탈퇴회원이 없습니다.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= esc((string) $row['user_id']) ?></td>
            <td><?= $row['email'] !== null ? esc($row['email']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $row['nickname'] !== null ? esc($row['nickname']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= esc($row['grade'] ?? '-') ?></td>
            <td class="text-end"><?= esc((string) $row['order_count']) ?></td>
            <td class="text-end"><?= esc(number_format((int) $row['point_balance'])) ?></td>
            <td class="text-end"><?= esc((string) $row['coupon_count']) ?></td>
            <td><?= esc($reasonLabels[$row['reason_code']] ?? $row['reason_code']) ?></td>
            <td><?= $row['withdrawn_by'] === 'admin' ? '관리자' : '회원' ?></td>
            <td><?= esc(date('Y-m-d H:i', strtotime((string) $row['withdrawn_at']))) ?></td>
            <td>
                <?php if ($row['purged_at'] !== null): ?>
                    <span class="badge bg-secondary">파기됨</span>
                <?php else: ?>
                    <span class="badge bg-light text-dark">보관중</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<nav>
    <ul class="pagination pagination-sm">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
            <a class="page-link" href="/admin/users/withdrawn?q=<?= esc($keyword, 'url') ?>&page=<?= $p ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?= $this->endSection() ?>
