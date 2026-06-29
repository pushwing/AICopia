<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="container py-5">

    <h3 class="fw-bold mb-4">기획전</h3>

    <?php if (empty($promotions)): ?>
    <p class="text-muted text-center py-5">진행 중인 기획전이 없습니다.</p>
    <?php else: ?>
    <?php
    $gradeLabels = ['all' => '전체', 'bronze' => 'Bronze+', 'silver' => 'Silver+', 'gold' => 'Gold+', 'platinum' => 'Platinum'];
    $gradeBadge  = ['all' => 'secondary', 'bronze' => 'warning', 'silver' => 'info', 'gold' => 'warning', 'platinum' => 'dark'];
    ?>
    <div class="row g-4">
        <?php foreach ($promotions as $p):
            $url = $p['accessible'] ? '/promotion/' . esc($p['slug']) : 'javascript:void(0)';
        ?>
        <div class="col-12 col-md-6 col-lg-4">
            <a href="<?= $url ?>"
               <?php if (! $p['accessible']): ?>
               onclick="alert('이 기획전은 <?= esc(addslashes($p['title'])) ?>\n해당 등급 회원만 열람할 수 있습니다.'); return false;"
               <?php endif; ?>
               class="text-decoration-none text-dark">
                <div class="card border-0 shadow-sm h-100 promotion-card">

                    <!-- 배너 이미지 -->
                    <div class="position-relative overflow-hidden" style="aspect-ratio:16/7">
                        <?php if (! empty($p['banner_image'])): ?>
                        <img src="<?= base_url(esc($p['banner_image'])) ?>"
                             alt="<?= esc($p['title']) ?>"
                             class="w-100 h-100" style="object-fit:cover">
                        <?php else: ?>
                        <div class="w-100 h-100 d-flex align-items-center justify-content-center bg-light text-muted">
                            <i class="bi bi-megaphone fs-1"></i>
                        </div>
                        <?php endif; ?>

                        <!-- 등급 뱃지 -->
                        <?php if ($p['grade_access'] !== 'all'): ?>
                        <span class="position-absolute top-0 start-0 badge bg-<?= $gradeBadge[$p['grade_access']] ?? 'secondary' ?> text-dark m-2">
                            <?= $gradeLabels[$p['grade_access']] ?? esc($p['grade_access']) ?>
                        </span>
                        <?php endif; ?>

                        <!-- 접근 불가 오버레이 -->
                        <?php if (! $p['accessible']): ?>
                        <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
                             style="background:rgba(0,0,0,.45)">
                            <span class="text-white fw-semibold"><i class="bi bi-lock me-1"></i>등급 제한</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="card-body">
                        <div class="fw-semibold mb-1"><?= esc($p['title']) ?></div>
                        <div class="small text-muted">
                            <?php
                            $s = $p['start_date'] ? date('Y.m.d', strtotime($p['start_date'])) : null;
                            $e = $p['end_date']   ? date('Y.m.d', strtotime($p['end_date']))   : null;
                            if ($s && $e)      echo $s . ' ~ ' . $e;
                            elseif ($s)        echo $s . ' ~';
                            elseif ($e)        echo '~ ' . $e;
                            else               echo '상시 진행';
                            ?>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<style>
.promotion-card { transition: transform .15s, box-shadow .15s; }
.promotion-card:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.12) !important; }
</style>
<?= $this->endSection() ?>
