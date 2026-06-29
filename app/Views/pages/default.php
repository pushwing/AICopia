<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <h2 class="fw-bold mb-4 border-bottom pb-3"><?= esc($page['title']) ?></h2>
            <div class="page-content">
                <?= $page['content'] /* HTML 에디터 출력이므로 escape 안 함 */ ?>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
