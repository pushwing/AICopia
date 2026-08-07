<?php
/**
 * 공용 에러 페이지 골격.
 * 호출부(error_XXX.php)가 $aivCode, $aivHeading, $aivMessage 를 정의한 뒤 include 한다.
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= esc($aivCode) ?> — <?= esc($aivHeading) ?> | AICopia</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;500;700;900&family=Space+Grotesk:wght@600;700&display=swap">
  <?php include __DIR__ . '/style.php'; ?>
</head>
<body>
  <div class="error-page">
    <div class="error-card">
      <a href="/" class="error-logo" aria-label="AICopia 홈">
        <svg width="24" height="24" viewBox="0 0 64 64" fill="none" aria-hidden="true">
          <path d="M14 46 L30 30 L44 40 L58 20" stroke="#2563EB" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
          <circle cx="14" cy="46" r="6" fill="#2563EB"/>
          <circle cx="30" cy="30" r="6" fill="#2563EB"/>
          <circle cx="44" cy="40" r="6" fill="#6D5EF6"/>
          <circle cx="58" cy="20" r="6" fill="#6D5EF6"/>
        </svg>
        <span><b>AI</b>vance</span>
      </a>

      <p class="error-code"><?= esc($aivCode) ?></p>
      <h1 class="error-heading"><?= esc($aivHeading) ?></h1>
      <p class="error-message"><?= esc($aivMessage) ?></p>

      <div class="error-actions">
        <a href="/" class="btn btn-primary">홈으로 가기</a>
        <a href="#" class="btn btn-outline" onclick="if(history.length>1){history.back();return false;}">이전 페이지로</a>
      </div>

      <p class="error-footer">&copy; AICopia</p>
    </div>
  </div>
</body>
</html>
