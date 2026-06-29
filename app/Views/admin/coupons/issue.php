<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '쿠폰 발급' ?>

<?= $this->section('content') ?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="/admin/coupons" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-chevron-left"></i>
    </a>
    <div>
        <h4 class="fw-bold mb-0">쿠폰 발급</h4>
        <div class="text-muted small"><?= esc($coupon['name']) ?> (<?= esc($coupon['code']) ?>)</div>
    </div>
</div>


<?php
    use App\Libraries\GradeService;
    $gradeLabels = GradeService::LABELS;
    $gradeBadges = GradeService::BADGE_CLASSES;
?>

<div class="row g-4">
    <div class="col-md-5">
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">회원 ID로 발급</div>
            <div class="card-body">
                <form method="post" action="/admin/coupons/<?= (int) $coupon['id'] ?>/issue">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">회원 ID (쉼표로 여러 명 입력)</label>
                        <textarea name="user_ids" class="form-control font-monospace"
                                  rows="3" placeholder="예: 1, 2, 5, 12"></textarea>
                        <div class="form-text">이미 발급된 회원은 자동으로 건너뜁니다.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">발급</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold bg-white">등급별 일괄 발급</div>
            <div class="card-body">
                <?php
                    $targetGrades = $coupon['target_grade']
                        ? array_map('trim', explode(',', $coupon['target_grade']))
                        : [];
                ?>
                <?php if (! empty($targetGrades)): ?>
                <div class="mb-2 small text-muted">
                    이 쿠폰의 대상 등급:
                    <?php foreach ($targetGrades as $tg): ?>
                        <span class="badge <?= $gradeBadges[$tg] ?? 'bg-secondary' ?>"><?= esc($gradeLabels[$tg] ?? $tg) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <form method="post" action="/admin/coupons/<?= (int) $coupon['id'] ?>/issue-grade">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">발급 대상 등급</label>
                        <select name="grade" class="form-select" required>
                            <?php foreach ($gradeLabels as $gk => $gl): ?>
                            <option value="<?= $gk ?>"
                                <?= in_array($gk, $targetGrades, true) ? 'selected' : '' ?>>
                                <?= esc($gl) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">선택한 등급 회원 전체에게 발급합니다. 이미 보유한 회원은 건너뜁니다.</div>
                    </div>
                    <button type="submit" class="btn btn-warning w-100"
                            onclick="return confirm('선택 등급 회원 전체에게 발급하겠습니까?')">
                        등급 일괄 발급
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card overflow-hidden">
            <div class="card-header fw-semibold bg-white d-flex justify-content-between align-items-center">
                <span>발급 내역</span>
                <span class="text-muted small">총 <?= number_format($total) ?>건</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>회원 ID</th>
                            <th>닉네임</th>
                            <th>출처</th>
                            <th>상태</th>
                            <th>발급일</th>
                            <th>사용일</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">발급 내역이 없습니다.</td></tr>
                        <?php else: foreach ($items as $uc): ?>
                        <tr>
                            <td class="small"><?= (int) $uc['user_id'] ?></td>
                            <td class="small"><?= esc($uc['nickname'] ?? '—') ?></td>
                            <td class="small">
                                <?php
                                    $srcMap = ['admin'=>['info','관리자'], 'grade_bulk'=>['warning','등급발급'], 'code'=>['secondary','코드입력']];
                                    [$srcColor, $srcLabel] = $srcMap[$uc['source']] ?? ['secondary', $uc['source']];
                                ?>
                                <span class="badge bg-<?= $srcColor ?> bg-opacity-75 text-dark"><?= $srcLabel ?></span>
                            </td>
                            <td>
                                <span class="badge bg-<?= $uc['status'] === 'used' ? 'success' : ($uc['status'] === 'expired' ? 'secondary' : 'primary') ?>">
                                    <?= ['issued'=>'보유','used'=>'사용','expired'=>'만료'][$uc['status']] ?? $uc['status'] ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?= $uc['issued_at'] ? date('Y년 n월 j일', strtotime($uc['issued_at'])) : '—' ?></td>
                            <td class="small text-muted"><?= $uc['used_at']   ? date('Y년 n월 j일', strtotime($uc['used_at']))   : '—' ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total > $perPage): ?>
            <div class="card-footer bg-white">
                <?php $totalPages = (int) ceil($total / $perPage); ?>
                <nav><ul class="pagination pagination-sm mb-0 justify-content-center">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a></li>
                    <?php endfor; ?>
                </ul></nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
