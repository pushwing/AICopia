<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
alert(<?= json_encode('【' . $promotion['title'] . '】\n이 기획전은 특정 등급 회원만 열람할 수 있습니다.') ?>);
history.length > 1 ? history.back() : location.href = '/';
</script>
<?= $this->endSection() ?>
