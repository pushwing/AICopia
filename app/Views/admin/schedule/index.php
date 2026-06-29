<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '배치 작업 관리' ?>
<?= $this->section('content') ?>

<!-- 토스트 컨테이너 -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1100">
    <div id="scheduleToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="scheduleToastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="mb-0">배치 작업 관리</h4>
        <p class="text-muted small mb-0 mt-1">스케줄러에 등록된 자동 실행 작업의 활성화 및 실행 주기를 설정합니다.</p>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= esc(session()->getFlashdata('success')) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= esc(session()->getFlashdata('error')) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4">작업명</th>
                    <th>커맨드</th>
                    <th>실행 주기</th>
                    <th class="text-center">상태</th>
                    <th class="text-center pe-4">활성화</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td class="ps-4 fw-semibold"><?= esc($job['label']) ?></td>
                    <td><code class="text-secondary small"><?= esc($job['command']) ?></code></td>
                    <td>
                        <span class="font-monospace small text-body"><?= esc($job['cron'] ?: '—') ?></span>
                        <button type="button"
                                class="btn btn-link btn-sm p-0 ms-1 text-muted"
                                data-bs-toggle="modal"
                                data-bs-target="#cronModal"
                                data-base-key="<?= esc($job['base_key']) ?>"
                                data-command="<?= esc($job['command']) ?>"
                                data-label="<?= esc($job['label']) ?>"
                                data-cron="<?= esc($job['cron']) ?>"
                                title="주기 변경">
                            <i class="bi bi-pencil-square small"></i>
                        </button>
                    </td>
                    <td class="text-center">
                        <?php if ($job['enabled'] === '1'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 schedule-badge"
                                  data-key="<?= esc($job['enabled_key']) ?>">활성</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1 schedule-badge"
                                  data-key="<?= esc($job['enabled_key']) ?>">비활성</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center pe-4">
                        <div class="form-check form-switch d-flex justify-content-center m-0">
                            <input class="form-check-input schedule-toggle fs-5"
                                   type="checkbox" role="switch"
                                   data-key="<?= esc($job['enabled_key']) ?>"
                                   data-label="<?= esc($job['label']) ?>"
                                   <?= $job['enabled'] === '1' ? 'checked' : '' ?>>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($jobs)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">등록된 배치 작업이 없습니다.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 실행 주기 변경 모달 -->
<div class="modal fade" id="cronModal" tabindex="-1" aria-labelledby="cronModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cronModalLabel">실행 주기 변경</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="cronForm" action="">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <p class="text-muted small mb-3" id="modalJobLabel"></p>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-2">프리셋</label>
                        <div class="d-flex flex-wrap gap-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary preset-btn" data-cron="* * * * *">매분</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary preset-btn" data-cron="*/5 * * * *">5분마다</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary preset-btn" data-cron="*/10 * * * *">10분마다</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary preset-btn" data-cron="*/15 * * * *">15분마다</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary preset-btn" data-cron="*/30 * * * *">30분마다</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary preset-btn" data-cron="0 * * * *">매시간</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary preset-btn" data-cron="0 0 * * *">매일 자정</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary preset-btn" data-cron="0 1 * * *">매일 01시</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary preset-btn" data-cron="0 2 * * *">매일 02시</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary preset-btn" data-cron="0 3 * * *">매일 03시</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary preset-btn" data-cron="0 2 * * 1">매주 월요일</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary preset-btn" data-cron="0 2 * * 0">매주 일요일</button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="cronInput">크론 표현식</label>
                        <input type="text" class="form-control font-monospace" id="cronInput" name="cron"
                               placeholder="*/5 * * * *" required>
                        <div class="form-text">분 &nbsp;시 &nbsp;일 &nbsp;월 &nbsp;요일 &nbsp;(0=일요일 ~ 6=토요일)</div>
                    </div>

                    <div>
                        <label class="form-label small fw-semibold">크론탭 등록 예시</label>
                        <pre class="bg-dark text-light rounded p-2 small mb-0" id="cronPreview"></pre>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">취소</button>
                    <button type="submit" class="btn btn-sm btn-primary">저장</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="mt-4">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light border-0 d-flex align-items-center gap-2"
             role="button" data-bs-toggle="collapse" data-bs-target="#cronHelp" aria-expanded="false">
            <i class="bi bi-question-circle text-primary"></i>
            <span class="fw-semibold">크론(Cron) 등록 방법</span>
            <i class="bi bi-chevron-down ms-auto small text-muted"></i>
        </div>
        <div class="collapse" id="cronHelp">
            <div class="card-body pt-0 pb-4 px-4">

                <p class="text-muted small mt-3 mb-2">
                    크론탭에 <strong>단 1줄</strong>만 등록하면 됩니다. 활성화·주기 변경은 이 페이지에서만 관리하세요 — 크론탭 재편집 불필요.
                </p>

                <h6 class="mt-4 mb-2 fw-semibold">1단계 — 크론탭 편집기 열기</h6>
                <pre class="bg-dark text-light rounded p-3 small mb-0">crontab -e</pre>

                <h6 class="mt-4 mb-2 fw-semibold">2단계 — 스케줄러 실행 등록 (1줄)</h6>
                <p class="text-muted small mb-2"><code>/path/to/shop</code>을 실제 프로젝트 경로로 변경하세요.</p>
                <pre class="bg-dark text-light rounded p-3 small mb-0">* * * * * cd /path/to/shop && php spark tasks:run >> /dev/null 2>&1</pre>

                <h6 class="mt-4 mb-2 fw-semibold">3단계 — 등록 확인</h6>
                <pre class="bg-dark text-light rounded p-3 small mb-0">crontab -l</pre>

                <hr class="my-4">

                <h6 class="mb-3 fw-semibold">배치 작업 동작 구조</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>① 크론 트리거 (매분)</th>
                                <th>② 스케줄러 초기화</th>
                                <th>③ 주기 도달 확인</th>
                                <th>④ 결과</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>tasks:run</code> 호출</td>
                                <td>DB에서 활성화된 잡만 읽어 등록</td>
                                <td>설정된 크론 표현식과 현재 시각 비교</td>
                                <td>주기 미달이면 스킵, 도달 시 커맨드 실행</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted small mt-2 mb-0">
                    활성화·주기 변경은 이 페이지에서만 하면 됩니다. 크론탭 재편집 불필요.
                </p>

                <hr class="my-4">

                <h6 class="mb-2 fw-semibold">새 배치 작업 추가 시 체크리스트</h6>
                <ol class="small text-muted mb-0 ps-3">
                    <li class="mb-1"><code>app/Commands/</code>에 커맨드 클래스 작성</li>
                    <li class="mb-1">마이그레이션으로 <code>settings</code> 테이블에 <code>_enabled</code>, <code>_cron</code> 키 추가</li>
                    <li class="mb-1"><code>ScheduleController::JOB_COMMANDS</code> 및 <code>Config/Tasks::JOB_COMMANDS</code> 상수에 매핑 등록</li>
                    <li>크론탭 재등록 불필요 — 기존 <code>tasks:run</code> 항목이 자동으로 처리</li>
                </ol>

            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
(function () {
    const csrfName  = '<?= csrf_token() ?>';
    let   csrfToken = '<?= csrf_hash() ?>';

    // ── 토스트 ─────────────────────────────────────────────────────────────────
    const toastEl   = document.getElementById('scheduleToast');
    const toastBody = document.getElementById('scheduleToastBody');
    const bsToast   = new bootstrap.Toast(toastEl, { delay: 3000 });

    function showToast(message, type) {
        toastEl.classList.remove('text-bg-success', 'text-bg-danger');
        toastEl.classList.add(type === 'success' ? 'text-bg-success' : 'text-bg-danger');
        toastBody.textContent = message;
        bsToast.show();
    }

    // ── 토글 스위치 AJAX ───────────────────────────────────────────────────────
    document.querySelectorAll('.schedule-toggle').forEach(function (toggle) {
        toggle.addEventListener('change', async function () {
            const key          = this.dataset.key;
            const prevChecked  = ! this.checked;
            this.disabled      = true;

            const body = new FormData();
            body.append(csrfName, csrfToken);

            try {
                const res  = await fetch('/admin/schedule/' + key + '/toggle', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body,
                });
                const data = await res.json();

                if (data.success) {
                    csrfToken = data.csrf_token;
                    const badge = document.querySelector('.schedule-badge[data-key="' + key + '"]');
                    if (badge) {
                        if (data.enabled) {
                            badge.className = 'badge bg-success-subtle text-success border border-success-subtle px-2 py-1 schedule-badge';
                            badge.textContent = '활성';
                        } else {
                            badge.className = 'badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1 schedule-badge';
                            badge.textContent = '비활성';
                        }
                    }
                    showToast(data.message, 'success');
                } else {
                    this.checked = prevChecked;
                    showToast(data.message || '오류가 발생했습니다.', 'error');
                }
            } catch (e) {
                this.checked = prevChecked;
                showToast('서버와 통신 중 오류가 발생했습니다.', 'error');
            } finally {
                this.disabled = false;
            }
        });
    });

    // ── 주기 변경 모달 ─────────────────────────────────────────────────────────
    let currentCommand = '';

    const cronModal   = document.getElementById('cronModal');
    const cronInput   = document.getElementById('cronInput');
    const cronPreview = document.getElementById('cronPreview');
    const cronForm    = document.getElementById('cronForm');
    const modalLabel  = document.getElementById('modalJobLabel');

    function updatePreview(cron) {
        cronPreview.textContent = (cron || '* * * * *') +
            ' cd /path/to/shop && php spark ' + currentCommand + ' >> /dev/null 2>&1';
    }

    cronModal.addEventListener('show.bs.modal', function (e) {
        const btn      = e.relatedTarget;
        currentCommand = btn.dataset.command;
        const cron     = btn.dataset.cron;

        modalLabel.textContent = btn.dataset.label;
        cronInput.value        = cron;
        cronForm.action        = '/admin/schedule/' + btn.dataset.baseKey + '/cron';
        updatePreview(cron);
    });

    cronInput.addEventListener('input', function () {
        updatePreview(this.value.trim());
    });

    document.querySelectorAll('.preset-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const cron = this.dataset.cron;
            cronInput.value = cron;
            updatePreview(cron);
        });
    });
}());
</script>

<?= $this->endSection() ?>
