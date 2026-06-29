<form method="post" action="/inquiry/submit" class="needs-validation" novalidate>
    <?= csrf_field() ?>
    <?php if (session()->has('errors')): ?>
    <div class="alert alert-danger">
        <?php foreach (session('errors') as $e): ?><div><?= esc($e) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">이름 <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= old('name') ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">연락처</label>
            <input type="tel" name="phone" class="form-control" value="<?= old('phone') ?>" placeholder="010-0000-0000">
        </div>
        <div class="col-12">
            <label class="form-label">이메일 <span class="text-danger">*</span></label>
            <input type="email" name="email" class="form-control" value="<?= old('email') ?>" required>
        </div>
        <div class="col-12">
            <label class="form-label">제목</label>
            <input type="text" name="subject" class="form-control" value="<?= old('subject') ?>">
        </div>
        <div class="col-12">
            <label class="form-label">문의 내용 <span class="text-danger">*</span></label>
            <textarea name="message" class="form-control" rows="6" required><?= old('message') ?></textarea>
        </div>
        <div class="col-12">
            <button type="submit" class="btn btn-primary px-5">문의 보내기</button>
        </div>
    </div>
</form>
