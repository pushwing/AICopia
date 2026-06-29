<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0">매입처 관리</h4>
    <a href="/admin/suppliers/create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>매입처 등록
    </a>
</div>


<div class="card">
    <?php if (empty($suppliers)): ?>
    <div class="card-body text-center text-muted py-5">등록된 매입처가 없습니다.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>매입처명</th>
                    <th>사업자등록번호</th>
                    <th>담당자</th>
                    <th>전화번호</th>
                    <th>이메일</th>
                    <th>메모</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($suppliers as $s): ?>
                <tr>
                    <td class="fw-semibold">
                        <?= esc($s['name']) ?>
                        <?php if (! empty($s['business_license_path'])): ?>
                        <a href="<?= esc($s['business_license_path']) ?>" target="_blank"
                           class="ms-1 text-muted small" title="사업자등록증">
                            <i class="bi bi-file-earmark-text"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= esc($s['business_no'] ?? '—') ?></td>
                    <td class="text-muted small"><?= esc($s['contact_person'] ?? '—') ?></td>
                    <td class="small"><?= esc($s['phone'] ?? '—') ?></td>
                    <td class="small"><?= esc($s['email'] ?? '—') ?></td>
                    <td class="small text-muted" style="max-width:200px">
                        <span class="text-truncate d-inline-block" style="max-width:200px">
                            <?= esc($s['memo'] ?? '') ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <a href="/admin/suppliers/<?= (int) $s['id'] ?>/edit"
                           class="btn btn-xs btn-outline-secondary me-1"
                           style="font-size:.72rem;padding:.15rem .45rem">수정</a>
                        <form method="post" action="/admin/suppliers/<?= (int) $s['id'] ?>/delete"
                              class="d-inline"
                              onsubmit="return confirm('삭제하시겠습니까? 해당 매입처가 지정된 상품은 매입처가 해제됩니다.')">
                            <?= csrf_field() ?>
                            <button class="btn btn-xs btn-outline-danger"
                                    style="font-size:.72rem;padding:.15rem .45rem">삭제</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
